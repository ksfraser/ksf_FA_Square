<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Exception;
use DateTimeImmutable;
use DateTimeInterface;
use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\DAO\DebtorsMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\CustBranchDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SalesOrdersDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareImportLogDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\TransactionStagingDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\ItemStagingDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMatchDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SalesMatchDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentsDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareCustomerDAO;
use Ksfraser\Frontaccounting\SquareUp\Pull\OrderImporter;
use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;
use Square\Exceptions\ApiException;
use Square\SquareClient;
use Square\Models\Payment;
use Square\Models\Order;
use Square\Models\OrderLineItem;

/**
 * Service class to handle Square import logic with staging support.
 *
 * Supports two-step import flow:
 * 1. Stage: Pull from API/CSV → ksf_import_square_transactions + items
 * 2. Process: Review → Match/Create → FA invoices + payments
 *
 * Backward compatible with existing production data (ksf_import_square_* tables).
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ImportService
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * @var Settings
     */
    private $settings;

    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var OrderImporter
     */
    private $orderImporter;

    /**
     * @var DebtorsMasterDAO
     */
    private $debtorsMasterDao;

    /**
     * @var CustBranchDAO
     */
    private $custBranchDao;

    /**
     * @var SalesOrdersDAO
     */
    private $salesOrdersDao;

    /**
     * @var SquareImportLogDAO
     */
    private $squareImportLogDao;

    /**
     * @var TransactionStagingDAO
     */
    private $transactionStagingDao;

    /**
     * @var ItemStagingDAO
     */
    private $itemStagingDao;

    /**
     * @var PaymentMatchDAO
     */
    private $paymentMatchDao;

    /**
     * @var SalesMatchDAO
     */
    private $salesMatchDao;

    /**
     * @var PaymentService|null
     */
    private $paymentService;

    public function __construct(
        string $tablePrefix,
        Settings $settings,
        SquareClient $client,
        PaymentService $paymentService = null
    ) {
        $this->tablePrefix = $tablePrefix;
        $this->settings = $settings;
        $this->client = $client;
        $this->orderImporter = new OrderImporter($client, $settings);
        $this->debtorsMasterDao = new DebtorsMasterDAO($tablePrefix);
        $this->custBranchDao = new CustBranchDAO($tablePrefix);
        $this->salesOrdersDao = new SalesOrdersDAO($tablePrefix);
        $this->squareImportLogDao = new SquareImportLogDAO($tablePrefix);
        $this->transactionStagingDao = new TransactionStagingDAO($tablePrefix);
        $this->itemStagingDao = new ItemStagingDAO($tablePrefix);
        $this->paymentMatchDao = new PaymentMatchDAO($tablePrefix);
        $this->salesMatchDao = new SalesMatchDAO($tablePrefix);
        $this->paymentService = $paymentService !== null
            ? $paymentService
            : $this->createDefaultPaymentService();
    }

    /**
     * Builds the default PaymentService from the import dependencies.
     *
     * @return PaymentService
     */
    private function createDefaultPaymentService(): PaymentService
    {
        return new PaymentService(
            new PaymentsDAO($this->tablePrefix),
            new PaymentAdapter($this->tablePrefix),
            new CustomerService($this->client, $this->debtorsMasterDao, new SquareCustomerDAO($this->tablePrefix)),
            new PaymentMappingDAO($this->tablePrefix)
        );
    }

    /**
     * Ensures all staging tables exist.
     *
     * @return void
     * @throws Exception
     */
    public function ensureStagingTablesExist(): void
    {
        $this->transactionStagingDao->ensureTableExists();
        $this->itemStagingDao->ensureTableExists();
        $this->paymentMatchDao->ensureTableExists();
        $this->salesMatchDao->ensureTableExists();
    }

    /**
     * Gets staging status counts.
     *
     * @return array [status => count]
     */
    public function getStagingStatusCounts(): array
    {
        return $this->transactionStagingDao->getStatusCounts($this->settings->getEnvironment());
    }

    /**
     * Gets staged transactions by status.
     *
     * @param string $status
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public function getStagedTransactions(
        string $status = TransactionStagingDAO::STATUS_STAGED,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        return $this->transactionStagingDao->getByStatus(
            $status,
            $this->settings->getEnvironment(),
            $fromDate,
            $toDate
        );
    }

    /**
     * Validates import parameters.
     *
     * @param int $destCust Destination customer ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Validation result with 'valid' flag and 'error' message
     */
    public function validateImportParams(
        int $destCust,
        string $fromDate,
        string $toDate
    ): array {
        if ($destCust <= 0) {
            return ['valid' => false, 'error' => _("Please select a destination customer.")];
        }

        if ($fromDate === '' || $toDate === '') {
            return ['valid' => false, 'error' => _("Please select a date range.")];
        }

        $customer = $this->debtorsMasterDao->getByDebtorNo($destCust);
        if (!$customer) {
            return ['valid' => false, 'error' => _("Customer not found.")];
        }

        return ['valid' => true, 'error' => '', 'customer' => $customer];
    }

    /**
     * Stage transactions from Square API into staging tables.
     * Step 1 of 2-step import flow.
     *
     * @param DateTimeInterface $fromDate
     * @param DateTimeInterface $toDate
     * @param string $locationFilter
     * @param array $locations [locationId => locationName]
     * @return array Results with 'staged', 'skipped', 'errors'
     * @throws ApiException
     */
    public function stageFromApi(
        DateTimeInterface $fromDate,
        DateTimeInterface $toDate,
        string $locationFilter,
        array $locations
    ): array {
        $this->ensureStagingTablesExist();

        $results = [
            'staged' => 0,
            'skipped' => 0,
            'errors' => [],
            'payments_found' => 0,
            'locations_skipped' => 0,
        ];

        $env = $this->settings->getEnvironment();

        foreach ($locations as $locId => $locName) {
            if ($locationFilter !== '' && $locationFilter !== $locId) {
                continue;
            }

            $payments = $this->orderImporter->listPayments($fromDate, $toDate, $locId);
            $paymentCount = is_countable($payments) ? count($payments) : 0;
            $results['payments_found'] += $paymentCount;

            if ($paymentCount === 0) {
                $results['errors'][] = _("Location '") . $locName . _("': No payments found for selected date range.");
            }

            foreach ($payments as $payment) {
                $paymentId = $payment->getId();

                if ($this->transactionStagingDao->exists($paymentId)) {
                    $results['errors'][] = _("Skipping (already staged): ") . $paymentId;
                    $results['skipped']++;
                    continue;
                }

                $refunded = $payment->getRefundedMoney();
                if ($refunded !== null && $refunded->getAmount() != 0) {
                    $results['errors'][] = _("Skipping refund: ") . $paymentId;
                    $results['skipped']++;
                    continue;
                }

                $orderId = $payment->getOrderId();
                if ($orderId === null) {
                    continue;
                }

                try {
                    $orderResult = $this->orderImporter->getPaymentWithOrder($paymentId);
                    $order = $orderResult['order'];

                    if ($order === null) {
                        $results['errors'][] = _("Cannot retrieve order: ") . $orderId;
                        $results['skipped']++;
                        continue;
                    }

                    $this->stagePaymentAndOrder($payment, $order, $locId, $locName, $env);
                    $results['staged']++;
                    $results['errors'][] = _("Staged: ") . $paymentId;
                } catch (Exception $e) {
                    $results['errors'][] = _("Error staging ") . $paymentId . ": " . $e->getMessage();
                }
            }
        }

        $this->squareImportLogDao->insertLog(
            'api',
            $results['staged'],
            $results['skipped'],
            0,
            'completed',
            $fromDate->format('Y-m-d'),
            $toDate->format('Y-m-d'),
            $env,
            SquareImportLogDAO::OP_TYPE_STAGE,
            $locationFilter
        );

        return $results;
    }

    /**
     * Stages a single payment and its order.
     *
     * @param Payment $payment
     * @param Order $order
     * @param string $locationId
     * @param string $locationName
     * @param string $environment
     * @return int Staged transaction ID
     * @throws Exception
     */
    private function stagePaymentAndOrder(
        Payment $payment,
        Order $order,
        string $locationId,
        string $locationName,
        string $environment
    ): int {
        $createdAt = $payment->getCreatedAt();
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $createdAt,
            new \DateTimeZone('UTC')
        );
        if ($dt) {
            $dt = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } else {
            $dt = new DateTimeImmutable();
        }

        $totalMoney = $payment->getTotalMoney();
        $taxMoney = $payment->getTaxMoney();
        $tipMoney = $payment->getTipMoney();
        $discountMoney = $payment->getDiscountMoney();
        $refundedMoney = $payment->getRefundedMoney();
        $totalCollected = $totalMoney !== null ? $totalMoney->getAmount() / 100 : 0;

        $transactionData = [
            'Date' => $dt->format('Y-m-d'),
            'Time' => $dt->format('H:i:s'),
            'Timezone' => $dt->getTimezone()->getName(),
            'transaction_id' => $payment->getId(),
            'payment_id' => $payment->getId(),
            'square_order_id' => $order->getId(),
            'square_location_id' => $locationId,
            'location' => $locationName,
            'environment' => $environment,
            'status' => TransactionStagingDAO::STATUS_STAGED,
            'source' => TransactionStagingDAO::SOURCE_API,
            'gross_sales' => $totalCollected,
            'net_sales' => $totalCollected,
            'total_collected' => $totalCollected,
            'tax' => $taxMoney !== null ? $taxMoney->getAmount() / 100 : 0,
            'tip' => $tipMoney !== null ? $tipMoney->getAmount() / 100 : 0,
            'partial_refunds' => $refundedMoney !== null ? $refundedMoney->getAmount() / 100 : 0,
        ];

        $cardDetails = $payment->getCardDetails();
        if ($cardDetails !== null) {
            $card = $cardDetails->getCard();
            if ($card !== null) {
                $transactionData['card_brand'] = $card->getCardBrand() ?? '';
                $last4 = $card->getLast4();
                if ($last4 !== null) {
                    $transactionData['PAN_suffix'] = (int)$last4;
                }
                $transactionData['card'] = $totalCollected;
                $transactionData['card_entry_methods'] = $cardDetails->getEntryMethod() ?? '';
            }
        }

        $customerId = $payment->getCustomerId();
        if ($customerId !== null) {
            $transactionData['square_customer_id'] = $customerId;
        }

        $transactionData['raw_json'] = json_encode([
            'payment' => $this->paymentToArray($payment),
            'order' => $this->orderToArray($order),
        ]);

        $stagingId = $this->transactionStagingDao->insert($transactionData);

        $lineItems = $order->getLineItems();
        if ($lineItems !== null) {
            foreach ($lineItems as $item) {
                $this->stageLineItem($item, $payment->getId(), $dt, $locationName);
            }
        }

        return $stagingId;
    }

    /**
     * Stages a single line item.
     *
     * @param OrderLineItem $item
     * @param string $paymentId
     * @param DateTimeInterface $dt
     * @param string $locationName
     * @return int
     * @throws Exception
     */
    private function stageLineItem(
        OrderLineItem $item,
        string $paymentId,
        DateTimeInterface $dt,
        string $locationName
    ): int {
        $basePrice = $item->getBasePriceMoney();
        $grossSales = $item->getGrossSalesMoney();
        $totalPrice = $item->getTotalSaleMoney();
        $tax = $item->getTotalTaxMoney();
        $discount = $item->getTotalDiscountMoney();

        $itemData = [
            'Date' => $dt->format('Y-m-d'),
            'Time' => $dt->format('H:i:s'),
            'Timezone' => $dt->getTimezone()->getName(),
            'transaction_id' => $paymentId,
            'payment_id' => $paymentId,
            'Item' => $item->getName() ?? '',
            'name' => $item->getName() ?? '',
            'quantity' => (int)$item->getQuantity(),
            'location' => $locationName,
            'gross_sales' => $grossSales !== null ? $grossSales->getAmount() / 100 : 0,
            'net_sales' => $totalPrice !== null ? $totalPrice->getAmount() / 100 : 0,
            'tax' => $tax !== null ? $tax->getAmount() / 100 : 0,
            'discounts' => $discount !== null ? $discount->getAmount() / 100 : 0,
            'unit_price' => $basePrice !== null ? $basePrice->getAmount() / 100 : 0,
            'total_amount' => $totalPrice !== null ? $totalPrice->getAmount() / 100 : 0,
            'discount_amount' => $discount !== null ? $discount->getAmount() / 100 : 0,
        ];

        $catalogObjectId = $item->getCatalogObjectId();
        $variationId = $item->getVariationTotalPriceMoney();

        if ($catalogObjectId !== null) {
            $itemData['square_catalog_object_id'] = $catalogObjectId;

            try {
                $catApi = $this->client->getCatalogApi();
                $catResponse = $catApi->retrieveCatalogObject($catalogObjectId, false);
                if ($catResponse->isSuccess()) {
                    $catObj = $catResponse->getResult()->getObject();
                    if ($catObj !== null) {
                        $varData = $catObj->getItemVariationData();
                        if ($varData !== null && $varData->getSku() !== null) {
                            $sku = $varData->getSku();
                            $itemData['sku'] = $sku;
                            $itemData['stock_id'] = $sku;
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        return $this->itemStagingDao->insert($itemData);
    }

    /**
     * Processes a single staged transaction into FA.
     * Step 2 of 2-step import flow.
     *
     * @param int $stagingId
     * @param int $debtorNo
     * @param int $branchCode
     * @param string $adjustmentItem
     * @param string $tipsItem
     * @return array [invoice_no, success, message]
     * @throws Exception
     */
    public function processStagedTransaction(
        int $stagingId,
        int $debtorNo,
        int $branchCode,
        string $adjustmentItem = '',
        string $tipsItem = ''
    ): array {
        $tableName = $this->transactionStagingDao->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE id = " . (int)$stagingId;
        $result = \db_query($sql);

        if ($result === false || \db_num_rows($result) === 0) {
            throw new Exception(_("Staged transaction not found: ") . $stagingId);
        }

        $trans = \db_fetch_assoc($result);
        if ($trans === false) {
            throw new Exception(_("Failed to read staged transaction"));
        }

        $paymentId = $trans['payment_id'];
        $transactionId = $trans['transaction_id'];

        if ($this->salesOrdersDao->orderExists($paymentId)) {
            $this->transactionStagingDao->updateStatus($stagingId, TransactionStagingDAO::STATUS_IMPORTED, [
                'error_log' => 'Already imported',
            ]);
            return ['success' => false, 'message' => _("Already imported")];
        }

        $customer = $this->debtorsMasterDao->getByDebtorNo($debtorNo);
        if (!$customer) {
            throw new Exception(_("Customer not found: ") . $debtorNo);
        }

        $branch = $this->custBranchDao->getByDebtorNoAndBranchCode($debtorNo, $branchCode);
        if (!$branch) {
            $branches = $this->custBranchDao->getByDebtorNo($debtorNo);
            if (empty($branches)) {
                throw new Exception(_("No branches found for customer: ") . $debtorNo);
            }
            $branch = $branches[0];
        }

        $items = $this->itemStagingDao->getByTransactionId($transactionId);

        $cart = new \Cart(ST_SALESINVOICE);
        $cart->customer_id = $customer['debtor_no'];
        $cart->customer_currency = $customer['curr_code'];
        $cart->Comments = 'Imported from Square: ' . $paymentId;

        $transDate = $trans['Date'] ?? date('Y-m-d');
        $cart->document_date = $transDate;
        $cart->due_date = $transDate;

        $cart->set_branch(
            $branch['branch_code'],
            $branch['tax_group_id'],
            $branch['br_address']
        );
        $cart->cust_ref = $paymentId;

        foreach ($items as $item) {
            $sku = $item['sku'] ?? $item['stock_id'] ?? $item['Item'] ?? '';
            $qty = (int)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['unit_price'] ?? $item['gross_sales'] ?? 0);

            if ($qty > 0 && $unitPrice == 0 && isset($item['gross_sales']) && $item['gross_sales'] > 0) {
                $unitPrice = (float)$item['gross_sales'] / $qty;
            }

            $discount = 0;
            $grossAmt = (float)($item['gross_sales'] ?? 0);
            $discAmt = (float)($item['discounts'] ?? $item['discount_amount'] ?? 0);
            if ($grossAmt > 0 && $discAmt > 0) {
                $discount = $discAmt / $grossAmt;
            }

            if ($sku !== '') {
                add_to_order($cart, $sku, $qty, $unitPrice, $discount);
            }
        }

        $tipAmount = (float)($trans['tip'] ?? 0);
        if ($tipAmount != 0 && $tipsItem !== '') {
            add_to_order($cart, $tipsItem, 1, $tipAmount, 0);
        }

        $total = $cart->get_trans_total();
        $totalOrder = (float)($trans['total_collected'] ?? 0);
        $adj = round($totalOrder - $total, 2);

        if ($adj != 0 && $adjustmentItem !== '') {
            add_to_order($cart, $adjustmentItem, 1, $adj, 0);
        }

        $orderNo = $cart->write(1);

        $this->transactionStagingDao->updateStatus($stagingId, TransactionStagingDAO::STATUS_IMPORTED, [
            'fa_invoice_no' => $orderNo,
            'fa_debtor_no' => $debtorNo,
            'fa_branch_code' => $branch['branch_code'],
        ]);

        $this->salesMatchDao->insertMatch($transactionId, $orderNo);

        $this->recordSquarePayment($this->buildSquarePaymentFromTransaction($trans), $debtorNo);

        $this->broadcastOrderImported([
            'source_order_id' => (string)$paymentId,
            'fa_order_no' => (int)$orderNo,
            'fa_trans_type' => ST_SALESINVOICE,
            'customer_id' => (int)$debtorNo,
            'order_total' => (float)($trans['total_collected'] ?? 0),
            'order_date' => (string)($trans['tran_date'] ?? date('Y-m-d')),
            'currency' => (string)($trans['currency'] ?? ''),
        ]);

        return [
            'success' => true,
            'invoice_no' => $orderNo,
            'message' => _("Created invoice #") . $orderNo,
        ];
    }

    /**
     * Broadcasts an order_imported event to other ksf modules.
     *
     * HRM (sales commissions) and ProjectManagement (project revenue)
     * listen for this event via hook_invoke_all. The call is guarded so
     * the module still works when the listener modules are not installed.
     *
     * @param array $payload Event payload
     * @return void
     */
    private function broadcastOrderImported(array $payload): void
    {
        if (!function_exists('hook_invoke_all')) {
            return;
        }

        $data = array_merge([
            'source' => 'square',
            'source_order_id' => '',
            'fa_order_no' => 0,
            'fa_trans_type' => ST_SALESINVOICE,
            'customer_id' => 0,
            'order_total' => 0.0,
            'order_date' => date('Y-m-d'),
            'currency' => '',
        ], $payload);

        hook_invoke_all('order_imported', $data);
    }

    /**
     * Records an imported Square payment against the invoice's debtor.
     *
     * Failure to record the payment must not fail the import (the invoice
     * is already written); the error is logged and null returned.
     *
     * @param array $squarePayment Square payment array
     * @param int $debtorNo FA debtor number
     * @return int|null FA payment id, or null when not recorded
     */
    private function recordSquarePayment(array $squarePayment, int $debtorNo): ?int
    {
        if ($this->paymentService === null) {
            return null;
        }

        try {
            return $this->paymentService->recordImportedPayment($squarePayment, $debtorNo);
        } catch (\Exception $e) {
            error_log('KSF Square: payment record failed for '
                . ($squarePayment['id'] ?? 'unknown')
                . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Builds a Square payment array from a staged transaction row.
     *
     * Prefers the stored payment data (raw_json) and falls back to the
     * staged total when no payment payload is available.
     *
     * @param array $trans Staged transaction row
     * @return array Square payment array
     */
    private function buildSquarePaymentFromTransaction(array $trans): array
    {
        $raw = json_decode((string)($trans['raw_json'] ?? ''), true);
        $paymentData = is_array($raw) && isset($raw['payment']) && is_array($raw['payment'])
            ? $raw['payment']
            : [];

        $amountCents = isset($paymentData['amount']) ? (int)$paymentData['amount'] : 0;
        if ($amountCents <= 0) {
            $amountCents = (int)round(((float)($trans['total_collected'] ?? 0)) * 100);
        }

        $paymentId = (string)($trans['payment_id'] ?? '');

        return [
            'id' => $paymentId,
            'amount_money' => [
                'amount' => $amountCents,
                'currency' => (string)($paymentData['currency'] ?? ($trans['currency'] ?? '')),
            ],
            'status' => 'COMPLETED',
            'payment_method' => (string)($paymentData['source_type'] ?? 'OTHER'),
            'reference_id' => $paymentId,
            'note' => 'Imported from Square: ' . $paymentId,
            'customer_email' => '',
        ];
    }

    /**
     * Builds a Square payment array from a Square Payment object.
     *
     * @param Payment $payment Square payment
     * @return array Square payment array
     */
    private function buildSquarePaymentFromPayment(Payment $payment): array
    {
        $totalMoney = $payment->getTotalMoney();

        return [
            'id' => (string)$payment->getId(),
            'amount_money' => [
                'amount' => $totalMoney !== null ? (int)$totalMoney->getAmount() : 0,
                'currency' => $totalMoney !== null ? (string)$totalMoney->getCurrency() : '',
            ],
            'status' => (string)($payment->getStatus() ?? 'COMPLETED'),
            'payment_method' => (string)($payment->getSourceType() ?? 'OTHER'),
            'reference_id' => (string)($payment->getReferenceId() ?? $payment->getId()),
            'note' => (string)($payment->getNote() ?? 'Imported from Square: ' . $payment->getId()),
            'customer_email' => (string)($payment->getBuyerEmailAddress() ?? ''),
        ];
    }

    /**
     * Performs direct import (legacy method, bypasses staging).
     * Kept for backward compatibility.
     *
     * @param array $customer Customer data
     * @param string $fromDate From date
     * @param string $toDate To date
     * @param bool $trialRun Whether this is a trial run
     * @param string $adjustmentItem Adjustment item for rounding
     * @param string $tipsItem Tips item for adding tips
     * @param string $locationFilter Location filter (empty = all)
     * @param array $locations Array of locations
     * @return array Import results with 'imported', 'skipped', 'failed', 'errors', 'msg'
     */
    public function performImport(
        array $customer,
        string $fromDate,
        string $toDate,
        bool $trialRun,
        string $adjustmentItem,
        string $tipsItem,
        string $locationFilter,
        array $locations
    ): array {
        $stagingManager = new StagingTableManager($this->tablePrefix);
        $stagingManager->createStagingTables();

        $importResults = [
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'msg' => '',
            'locations_skipped' => 0,
            'payments_found' => 0,
        ];

        try {
            $fromDateTime = new DateTimeImmutable($fromDate);
            $toDateTime = new DateTimeImmutable($toDate);
            $destCust = (int)$customer['debtor_no'];

            $locationsProcessed = 0;
            foreach ($locations as $locId => $locName) {
                if ($locationFilter !== '' && $locationFilter !== $locId) {
                    continue;
                }

                $locationsProcessed++;
                $branch = $this->custBranchDao->getByDebtorNoAndName($destCust, $locName);
                if (!$branch) {
                    $importResults['errors'][] = _("SKIPPED LOCATION: '") . $locName . _("' - No matching FA customer branch found.");
                    $importResults['errors'][] = _("  To fix: Create a customer branch named '") . $locName . _("' for the selected customer.");
                    $importResults['locations_skipped']++;
                    continue;
                }

                $payments = $this->orderImporter->listPayments($fromDateTime, $toDateTime, $locId);
                $paymentCount = is_countable($payments) ? count($payments) : 0;
                $importResults['payments_found'] += $paymentCount;

                if ($paymentCount === 0) {
                    $importResults['errors'][] = _("Location '") . $locName . _("': No payments found for selected date range.");
                }

                foreach ($payments as $payment) {
                    $paymentMethod = str_replace("CREDIT_CARD", "CARD", $payment->getSourceType());
                    if ($paymentMethod === "NO_SALE") {
                        continue;
                    }

                    if ($this->salesOrdersDao->orderExists($payment->getId())) {
                        $importResults['errors'][] = _("Skipping (already imported): ") . $payment->getId();
                        $importResults['skipped']++;
                        continue;
                    }

                    $refunded = $payment->getRefundedMoney();
                    if ($refunded !== null && $refunded->getAmount() != 0) {
                        $importResults['errors'][] = _("Skipping refund: ") . $payment->getId();
                        $importResults['skipped']++;
                        continue;
                    }

                    $orderId = $payment->getOrderId();
                    if ($orderId === null) {
                        continue;
                    }

                    $result = $this->orderImporter->getPaymentWithOrder($payment->getId());
                    $order = $result['order'];
                    if ($order === null) {
                        $importResults['errors'][] = _("Cannot retrieve order: ") . $orderId;
                        $importResults['skipped']++;
                        continue;
                    }

                    $lineItems = $order->getLineItems();
                    if ($lineItems === null) {
                        continue;
                    }

                    if ($trialRun) {
                        $totalMoney = $payment->getTotalMoney();
                        $importResults['errors'][] = _("TRIAL: Would import ") . $payment->getId()
                            . " (" . ($totalMoney !== null ? $totalMoney->getAmount() / 100 : 0) . " "
                            . ($totalMoney !== null ? $totalMoney->getCurrency() : '') . ")";
                    } else {
                        $cart = new \Cart(ST_SALESINVOICE);
                        $cart->customer_id = $customer['debtor_no'];
                        $cart->customer_currency = $customer['curr_code'];
                        $cart->Comments = 'Imported from Square: ' . $payment->getId();

                        $dt = \DateTime::createFromFormat(
                            'Y-m-d\TH:i:s\Z',
                            $payment->getCreatedAt(),
                            new \DateTimeZone('UTC')
                        );
                        if ($dt) {
                            $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                            $cart->document_date = $dt->format('Y-m-d');
                            $cart->due_date = $dt->format('Y-m-d');
                        }

                        $cart->set_branch(
                            $branch['branch_code'],
                            $branch['tax_group_id'],
                            $branch['br_address']
                        );
                        $cart->cust_ref = $payment->getId();

                        $taxType = '';
                        $orderTaxes = $order->getTaxes();
                        if ($orderTaxes !== null && count($orderTaxes) > 0) {
                            $taxType = $orderTaxes[0]->getType();
                        }

                        $catApi = $this->client->getCatalogApi();
                        foreach ($lineItems as $item) {
                            $catObjId = $item->getCatalogObjectId();
                            $sku = $item->getName();
                            if ($catObjId !== null) {
                                try {
                                    $catResponse = $catApi->retrieveCatalogObject($catObjId, false);
                                    if ($catResponse->isSuccess()) {
                                        $catObj = $catResponse->getResult()->getObject();
                                        if ($catObj !== null) {
                                            $varData = $catObj->getItemVariationData();
                                            if ($varData !== null && $varData->getSku() !== null) {
                                                $sku = $varData->getSku();
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                }
                            }

                            $basePrice = $item->getBasePriceMoney();
                            $unitPrice = $basePrice !== null ? $basePrice->getAmount() / 100 : 0;

                            $discount = 0;
                            if ($taxType === "INCLUSIVE") {
                                $totalPrice = $item->getVariationTotalPriceMoney();
                                $totalAmt = $totalPrice !== null ? $totalPrice->getAmount() : 1;
                                $discMoney = $item->getTotalDiscountMoney();
                                if ($discMoney !== null && $totalAmt > 0) {
                                    $discount = $discMoney->getAmount() / $totalAmt;
                                }
                            } else {
                                $grossSales = $item->getGrossSalesMoney();
                                $grossAmt = $grossSales !== null ? $grossSales->getAmount() : 1;
                                $discMoney = $item->getTotalDiscountMoney();
                                if ($discMoney !== null && $grossAmt > 0) {
                                    $discount = $discMoney->getAmount() / $grossAmt;
                                }
                            }

                            add_to_order($cart, $sku, $item->getQuantity(), $unitPrice, $discount);
                        }

                        $tipMoney = $payment->getTipMoney();
                        if ($tipMoney !== null && $tipMoney->getAmount() != 0 && $tipsItem !== '') {
                            add_to_order($cart, $tipsItem, 1, $tipMoney->getAmount() / 100, 0);
                        }

                        $total = $cart->get_trans_total();
                        $totalMoney = $payment->getTotalMoney();
                        $totalOrder = $totalMoney !== null ? $totalMoney->getAmount() / 100 : 0;
                        $adj = round($totalOrder - $total, 2);
                        if ($adj != 0 && $adjustmentItem !== '') {
                            $importResults['errors'][] = _("Adjustment needed: ") . $adj;
                            add_to_order($cart, $adjustmentItem, 1, $adj, 0);
                        }

                        $orderNo = $cart->write(1);
                        if ($orderNo) {
                            $this->salesMatchDao->insertMatch($payment->getId(), $orderNo);
                            $this->recordSquarePayment(
                                $this->buildSquarePaymentFromPayment($payment),
                                (int)$customer['debtor_no']
                            );
                        }

                        $this->broadcastOrderImported([
                            'source_order_id' => $payment->getId(),
                            'fa_order_no' => (int)$orderNo,
                            'fa_trans_type' => ST_SALESINVOICE,
                            'customer_id' => (int)$customer['debtor_no'],
                            'order_total' => (float)($totalMoney !== null ? $totalMoney->getAmount() / 100 : 0),
                            'order_date' => $dt ? $dt->format('Y-m-d') : date('Y-m-d'),
                        ]);
                        $importResults['errors'][] = _("Created invoice #") . $orderNo
                            . _(" for ") . $payment->getId();
                        $importResults['imported']++;
                    }
                }
            }

            if (!$trialRun) {
                $this->squareImportLogDao->insertLog(
                    'api',
                    $importResults['imported'],
                    $importResults['skipped'],
                    $importResults['failed'],
                    'completed',
                    $fromDate,
                    $toDate,
                    $this->settings->getEnvironment(),
                    SquareImportLogDAO::OP_TYPE_DIRECT,
                    $locationFilter
                );

                $newLastDate = date('Y-m-d', strtotime($toDate));
                $lastSetting = $this->settings->getLastImportDate();
                if ($lastSetting === null || $newLastDate > $lastSetting->format('Y-m-d')) {
                    Settings::saveToDatabase($this->tablePrefix, 'lastdate', $newLastDate);
                }
            }

            $importResults['msg'] = _("Import complete. Imported: ") . $importResults['imported']
                . _(", Skipped: ") . $importResults['skipped']
                . _(", Failed: ") . $importResults['failed'];
        } catch (ApiException $e) {
            $importResults['error'] = _("API Error: ") . $e->getMessage();
        } catch (Exception $e) {
            $importResults['error'] = _("Error: ") . $e->getMessage();
        }

        return $importResults;
    }

    /**
     * Gets the default dates for the import form.
     *
     * @return array With 'from_date' and 'to_date'
     */
    public function getDefaultDates(): array
    {
        $lastDate = $this->settings->getLastImportDate();
        return [
            'from_date' => $lastDate !== null ? $lastDate->format('Y-m-d') : Today(),
            'to_date' => Today(),
        ];
    }

    /**
     * Gets recent import logs.
     *
     * @param int $limit Maximum number of logs to return
     * @return array Array of log entries
     */
    public function getRecentLogs(int $limit = 10): array
    {
        return $this->squareImportLogDao->getRecentLogs($limit);
    }

    /**
     * Checks if there are any import logs.
     *
     * @return bool True if there are logs, false otherwise
     */
    public function hasLogs(): bool
    {
        return $this->squareImportLogDao->hasLogs();
    }

    /**
     * Converts Payment object to array for JSON storage.
     *
     * @param Payment $payment
     * @return array
     */
    private function paymentToArray(Payment $payment): array
    {
        $totalMoney = $payment->getTotalMoney();
        $taxMoney = $payment->getTaxMoney();
        $tipMoney = $payment->getTipMoney();

        return [
            'id' => $payment->getId(),
            'created_at' => $payment->getCreatedAt(),
            'updated_at' => $payment->getUpdatedAt(),
            'amount' => $totalMoney !== null ? $totalMoney->getAmount() : 0,
            'currency' => $totalMoney !== null ? $totalMoney->getCurrency() : '',
            'tax_amount' => $taxMoney !== null ? $taxMoney->getAmount() : 0,
            'tip_amount' => $tipMoney !== null ? $tipMoney->getAmount() : 0,
            'source_type' => $payment->getSourceType(),
            'order_id' => $payment->getOrderId(),
            'customer_id' => $payment->getCustomerId(),
            'location_id' => $payment->getLocationId(),
        ];
    }

    /**
     * Converts Order object to array for JSON storage.
     *
     * @param Order $order
     * @return array
     */
    private function orderToArray(Order $order): array
    {
        $totalMoney = $order->getTotalMoney();
        $totalTax = $order->getTotalTaxMoney();
        $totalDiscount = $order->getTotalDiscountMoney();
        $totalTip = $order->getTotalTipMoney();

        $lineItems = $order->getLineItems();
        $itemsArr = [];
        if ($lineItems !== null) {
            foreach ($lineItems as $item) {
                $basePrice = $item->getBasePriceMoney();
                $itemsArr[] = [
                    'name' => $item->getName(),
                    'quantity' => $item->getQuantity(),
                    'base_price' => $basePrice !== null ? $basePrice->getAmount() : 0,
                    'catalog_object_id' => $item->getCatalogObjectId(),
                ];
            }
        }

        return [
            'id' => $order->getId(),
            'created_at' => $order->getCreatedAt(),
            'updated_at' => $order->getUpdatedAt(),
            'total_amount' => $totalMoney !== null ? $totalMoney->getAmount() : 0,
            'total_tax' => $totalTax !== null ? $totalTax->getAmount() : 0,
            'total_discount' => $totalDiscount !== null ? $totalDiscount->getAmount() : 0,
            'total_tip' => $totalTip !== null ? $totalTip->getAmount() : 0,
            'line_items_count' => count($itemsArr),
        ];
    }
}
