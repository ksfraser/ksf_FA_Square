<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use Exception;
use DateTimeImmutable;
use DateTimeInterface;
use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use ksfraser\FrontAccounting\Square\DAO\CustBranchDAO;
use ksfraser\FrontAccounting\Square\DAO\SalesOrdersDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareImportLogDAO;
use ksfraser\FrontAccounting\Square\DAO\PaymentMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\SalesMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\PaymentsDAO;
use ksfraser\FrontAccounting\Square\DAO\PaymentMappingDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareCustomerDAO;
use ksfraser\FrontAccounting\Square\Pull\OrderImporter;
use ksfraser\FrontAccounting\Square\Staging\IsuStagingGateway;
use Square\Exceptions\ApiException;
use Square\SquareClient;
use Square\Models\Payment;
use Square\Models\Order;
use Square\Models\OrderLineItem;

/**
 * Service class to handle Square import logic with staging support.
 *
 * Supports two-step import flow:
 * 1. Stage: Pull from API/CSV → ISU staging tables (via hooks)
 * 2. Process: Review → Match/Create → FA invoices + payments
 *
 * All staging operations go through ISU (ksf_FA_ImportStagingProcessing)
 * via IsuStagingGateway. Square does NOT have its own staging tables.
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
     * @var string Company directory for log files
     */
    private $companyDir;

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
     * @var IsuStagingGateway
     */
    private $gateway;

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
        PaymentService $paymentService = null,
        IsuStagingGateway $gateway = null
    ) {
        global $path_to_root;
        $this->tablePrefix = $tablePrefix;
        $this->companyDir = rtrim($path_to_root, '/') . '/company/0/';
        $this->settings = $settings;
        $this->client = $client;
        $this->orderImporter = new OrderImporter($client, $settings);
        $this->debtorsMasterDao = new DebtorsMasterDAO($tablePrefix);
        $this->custBranchDao = new CustBranchDAO($tablePrefix);
        $this->salesOrdersDao = new SalesOrdersDAO($tablePrefix);
        $this->squareImportLogDao = new SquareImportLogDAO($tablePrefix);
        $this->gateway = $gateway ?? new IsuStagingGateway();
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
     * Write a timestamped message to the company square sync log.
     *
     * @param string $level  INFO, WARN, ERROR
     * @param string $message
     * @param array  $context Optional key-value context to append
     * @return void
     */
    private function logMessage(string $level, string $message, array $context = []): void
    {
        $logFile = $this->companyDir . 'square_sync.log';
        $ts = date('Y-m-d H:i:s');
        $line = sprintf("[%s] %s: %s", $ts, $level, $message);
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ensures staging tables exist.
     *
     * ISU manages its own tables. This method ensures ISU is initialized
     * and Square's non-staging support tables exist.
     *
     * @return void
     */
    public function ensureStagingTablesExist(): void
    {
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
        return $this->gateway->getStatusCounts('square');
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
        string $status = 'staged',
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        return $this->gateway->getByStatus($status, $fromDate, $toDate);
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

        $customer = $this->debtorsMasterDao->getDebtor($destCust);
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

        $this->logMessage('INFO', '=== Square import started ===', [
            'from_date' => $fromDate->format('Y-m-d'),
            'to_date' => $toDate->format('Y-m-d'),
            'location_filter' => $locationFilter,
            'environment' => $this->settings->getEnvironment(),
        ]);

        $results = [
            'staged' => 0,
            'skipped' => 0,
            'errors' => [],
            'payments_found' => 0,
            'locations_skipped' => 0,
        ];

        $env = $this->settings->getEnvironment();

        if (empty($locations)) {
            $this->logMessage('ERROR', 'No Square locations configured for import');
            $results['errors'][] = _("No Square locations found. Check API connection and location configuration.");
            return $results;
        }

        $this->logMessage('INFO', sprintf('Found %d Square location(s) configured', count($locations)));

        foreach ($locations as $locId => $locName) {
            if ($locationFilter !== '' && $locationFilter !== $locId) {
                $results['locations_skipped']++;
                continue;
            }

            $payments = $this->orderImporter->listPayments($fromDate, $toDate, $locId);
            $paymentCount = is_countable($payments) ? count($payments) : 0;
            $results['payments_found'] += $paymentCount;

            $this->logMessage('INFO', sprintf('Location %s (%s): found %d payment(s)', $locName, $locId, $paymentCount), [
                'location_id' => $locId,
                'location_name' => $locName,
                'payment_count' => $paymentCount,
            ]);

            if ($paymentCount === 0) {
                $results['errors'][] = _("Location '") . $locName . _("': No payments found for selected date range.");
            }

            foreach ($payments as $payment) {
                $paymentId = $payment->getId();

                if ($this->gateway->exists($paymentId)) {
                    $this->logMessage('WARN', sprintf('Payment %s already staged, skipping', $paymentId), [
                        'payment_id' => $paymentId,
                        'reason' => 'already_staged',
                    ]);
                    $results['errors'][] = _("Skipping (already staged): ") . $paymentId;
                    $results['skipped']++;
                    continue;
                }

                $refunded = $payment->getRefundedMoney();
                if ($refunded !== null && $refunded->getAmount() != 0) {
                    $this->logMessage('WARN', sprintf('Payment %s is refunded, skipping', $paymentId), [
                        'payment_id' => $paymentId,
                        'reason' => 'refunded',
                        'refunded_amount' => $refunded->getAmount() / 100,
                    ]);
                    $results['errors'][] = _("Skipping refund: ") . $paymentId;
                    $results['skipped']++;
                    continue;
                }

                $orderId = $payment->getOrderId();
                if ($orderId === null) {
                    $this->logMessage('WARN', sprintf('Payment %s has no order ID, skipping', $paymentId), [
                        'payment_id' => $paymentId,
                        'reason' => 'no_order_id',
                    ]);
                    $results['errors'][] = _("Skipping (no order ID): ") . $paymentId;
                    $results['skipped']++;
                    continue;
                }

                try {
                    $orderResult = $this->orderImporter->getPaymentWithOrder($paymentId);
                    $order = $orderResult['order'];

                    if ($order === null) {
                        $this->logMessage('ERROR', sprintf('Cannot retrieve order %s for payment %s', $orderId, $paymentId), [
                            'payment_id' => $paymentId,
                            'order_id' => $orderId,
                            'reason' => 'order_not_found',
                        ]);
                        $results['errors'][] = _("Cannot retrieve order: ") . $orderId . _(" for payment: ") . $paymentId;
                        $results['skipped']++;
                        continue;
                    }

                    $stagingId = $this->stagePaymentAndOrder($payment, $order, $locId, $locName, $env);
                    $results['staged']++;
                    $results['errors'][] = _("Staged: ") . $paymentId;

                    $this->logMessage('INFO', sprintf('Payment %s staged successfully (ISU staging ID: %d)', $paymentId, $stagingId), [
                        'payment_id' => $paymentId,
                        'order_id' => $orderId,
                        'staging_id' => $stagingId,
                        'amount' => $payment->getTotalMoney() ? $payment->getTotalMoney()->getAmount() / 100 : 0,
                        'currency' => $payment->getTotalMoney() ? $payment->getTotalMoney()->getCurrency() : 'USD',
                    ]);
                } catch (Exception $e) {
                    $this->logMessage('ERROR', sprintf('Error staging payment %s: %s', $paymentId, $e->getMessage()), [
                        'payment_id' => $paymentId,
                        'error' => $e->getMessage(),
                    ]);
                    $results['errors'][] = _("Error staging ") . $paymentId . ": " . $e->getMessage();
                }
            }
        }

        $this->logMessage('INFO', sprintf('Import staging complete: %d staged, %d skipped, %d errors', $results['staged'], $results['skipped'], count($results['errors'])), [
            'staged' => $results['staged'],
            'skipped' => $results['skipped'],
            'errors' => count($results['errors']),
            'payments_found' => $results['payments_found'],
        ]);

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
        $tipMoney = $payment->getTipMoney();
        $refundedMoney = $payment->getRefundedMoney();
        $totalCollected = $totalMoney !== null ? $totalMoney->getAmount() / 100 : 0;

        $orderTaxMoney = $order->getTotalTaxMoney();

        $paymentData = [
            'payment_id' => $payment->getId(),
            'square_order_id' => $order->getId(),
            'square_location_id' => $locationId,
            'location' => $locationName,
            'environment' => $environment,
            'total_collected' => $totalCollected,
            'gross_sales' => $totalCollected,
            'net_sales' => $totalCollected,
            'tax' => $orderTaxMoney !== null ? $orderTaxMoney->getAmount() / 100 : 0,
            'tip' => $tipMoney !== null ? $tipMoney->getAmount() / 100 : 0,
            'partial_refunds' => $refundedMoney !== null ? $refundedMoney->getAmount() / 100 : 0,
            'currency' => $totalMoney !== null ? $totalMoney->getCurrency() : 'USD',
            'created_at' => $dt->format('c'),
        ];

        $cardDetails = $payment->getCardDetails();
        if ($cardDetails !== null) {
            $card = $cardDetails->getCard();
            if ($card !== null) {
                $paymentData['card_brand'] = $card->getCardBrand() ?? '';
                $last4 = $card->getLast4();
                if ($last4 !== null) {
                    $paymentData['PAN_suffix'] = (int)$last4;
                }
                $paymentData['card'] = $totalCollected;
                $paymentData['card_entry_methods'] = $cardDetails->getEntryMethod() ?? '';
            }
        }

        $customerId = $payment->getCustomerId();
        if ($customerId !== null) {
            $paymentData['square_customer_id'] = $customerId;
        }

        $lineItems = [];
        $orderLineItems = $order->getLineItems();
        if ($orderLineItems !== null) {
            foreach ($orderLineItems as $item) {
                $basePrice = $item->getBasePriceMoney();
                $tax = $item->getTotalTaxMoney();
                $discount = $item->getTotalDiscountMoney();

                $lineItems[] = [
                    'source_id' => $item->getUid() ?? '',
                    'transaction_source_id' => $payment->getId(),
                    'sku' => $item->getCatalogObjectId() ?? '',
                    'name' => $item->getName() ?? '',
                    'description' => $item->getName() ?? '',
                    'quantity' => (int)$item->getQuantity(),
                    'unit_price' => $basePrice !== null ? $basePrice->getAmount() / 100 : 0,
                    'discount' => $discount !== null ? $discount->getAmount() / 100 : 0,
                    'tax' => $tax !== null ? $tax->getAmount() / 100 : 0,
                ];
            }
        }

        $orderData = [
            'square_order_id' => $order->getId(),
        ];

        $this->logMessage('INFO', sprintf('Staging payment %s via ISU (order %s)', $payment->getId(), $order->getId()), [
            'payment_id' => $payment->getId(),
            'square_order_id' => $order->getId(),
            'source_transaction_id' => $payment->getId(),
            'amount' => $totalCollected,
            'currency' => $totalMoney !== null ? $totalMoney->getCurrency() : 'USD',
            'location_id' => $locationId,
            'location_name' => $locationName,
            'line_item_count' => count($lineItems),
        ]);

        $stagingId = $this->gateway->stageSquareOrder($paymentData, $orderData, $lineItems);

        if ($stagingId > 0) {
            $this->logMessage('INFO', sprintf('Payment %s staged in ISU with staging ID %d', $payment->getId(), $stagingId), [
                'payment_id' => $payment->getId(),
                'staging_id' => $stagingId,
            ]);
        } else {
            $this->logMessage('ERROR', sprintf('Payment %s failed to stage in ISU (staging ID is 0)', $payment->getId()), [
                'payment_id' => $payment->getId(),
                'reason' => 'staging_id_zero',
            ]);
        }

        return $stagingId;
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
        $this->logMessage('INFO', sprintf('Processing staged transaction ID %d for debtor %d', $stagingId, $debtorNo), [
            'staging_id' => $stagingId,
            'debtor_no' => $debtorNo,
            'branch_code' => $branchCode,
        ]);

        $trans = $this->gateway->getById($stagingId);

        if ($trans === null) {
            $this->logMessage('ERROR', sprintf('Staged transaction ID %d not found in ISU', $stagingId), [
                'staging_id' => $stagingId,
                'reason' => 'not_found',
            ]);
            throw new Exception(_("Staged transaction not found: ") . $stagingId);
        }

        $paymentId = $trans['source_payment_id'] ?? $trans['source_transaction_id'] ?? '';

        $this->logMessage('INFO', sprintf('Processing staged payment %s (ISU ID %d)', $paymentId, $stagingId), [
            'payment_id' => $paymentId,
            'staging_id' => $stagingId,
            'source' => $trans['source'] ?? 'unknown',
            'status' => $trans['status'] ?? 'unknown',
            'total_amount' => $trans['total_amount'] ?? 0,
        ]);

        if ($this->salesOrdersDao->orderExists($paymentId)) {
            $this->logMessage('WARN', sprintf('Payment %s already imported as FA invoice, skipping', $paymentId), [
                'payment_id' => $paymentId,
                'staging_id' => $stagingId,
                'reason' => 'already_imported',
            ]);
            $this->gateway->updateStatus($stagingId, 'imported', ['error_log' => 'Already imported']);
            return ['success' => false, 'message' => _("Already imported")];
        }

        $customer = $this->debtorsMasterDao->getDebtor($debtorNo);
        if (!$customer) {
            $this->logMessage('ERROR', sprintf('Customer %d not found while processing staged payment %s', $debtorNo, $paymentId), [
                'payment_id' => $paymentId,
                'staging_id' => $stagingId,
                'debtor_no' => $debtorNo,
                'reason' => 'customer_not_found',
            ]);
            throw new Exception(_("Customer not found: ") . $debtorNo);
        }

        $branch = $this->custBranchDao->getByDebtorNoAndBranchCode($debtorNo, $branchCode);
        if (!$branch) {
            $branches = $this->custBranchDao->getByDebtorNo($debtorNo);
            if (empty($branches)) {
                $this->logMessage('ERROR', sprintf('No branches found for customer %d', $debtorNo), [
                    'payment_id' => $paymentId,
                    'staging_id' => $stagingId,
                    'debtor_no' => $debtorNo,
                    'reason' => 'no_branches',
                ]);
                throw new Exception(_("No branches found for customer: ") . $debtorNo);
            }
            $branch = $branches[0];
        }

        $items = $this->gateway->getLineItems($stagingId);

        $cart = new \Cart(ST_SALESINVOICE);
        $cart->customer_id = $customer['debtor_no'];
        $cart->customer_currency = $customer['curr_code'];
        $cart->Comments = 'Imported from Square: ' . $paymentId;

        $transDate = $trans['transaction_date'] ?? date('Y-m-d');
        $cart->document_date = $transDate;
        $cart->due_date = $transDate;

        $cart->set_branch(
            $branch['branch_code'],
            $branch['tax_group_id'],
            $branch['br_address']
        );
        $cart->cust_ref = $paymentId;

        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            $qty = (int)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['unit_price'] ?? 0);

            $discount = 0;
            $grossAmt = (float)($item['total_amount'] ?? 0);
            $discAmt = (float)($item['discount_amount'] ?? 0);
            if ($grossAmt > 0 && $discAmt > 0) {
                $discount = $discAmt / $grossAmt;
            }

            if ($sku !== '' && $qty > 0) {
                add_to_order($cart, $sku, $qty, $unitPrice, $discount);
            }
        }

        $tipAmount = (float)($trans['tip_amount'] ?? 0);
        if ($tipAmount != 0 && $tipsItem !== '') {
            add_to_order($cart, $tipsItem, 1, $tipAmount, 0);
        }

        $total = $cart->get_trans_total();
        $totalOrder = (float)($trans['total_amount'] ?? 0);
        $adj = round($totalOrder - $total, 2);

        if ($adj != 0 && $adjustmentItem !== '') {
            add_to_order($cart, $adjustmentItem, 1, $adj, 0);
        }

        $orderNo = $cart->write(1);

        $this->logMessage('INFO', sprintf('FA invoice #%d created for staged payment %s', $orderNo, $paymentId), [
            'payment_id' => $paymentId,
            'staging_id' => $stagingId,
            'fa_invoice_no' => $orderNo,
            'fa_debtor_no' => $debtorNo,
            'invoice_total' => $total,
            'square_total' => $totalOrder,
            'adjustment' => $adj,
        ]);

        $this->gateway->updateStatus($stagingId, 'imported', [
            'fa_invoice_no' => (string)$orderNo,
            'fa_debtor_no' => (string)$debtorNo,
        ]);

        $this->salesMatchDao->insertMatch($paymentId, $orderNo);

        $this->recordSquarePayment($this->buildSquarePaymentFromTransaction($trans), $debtorNo);

        $this->logMessage('INFO', sprintf('Payment %s fully imported as FA invoice #%d', $paymentId, $orderNo), [
            'payment_id' => $paymentId,
            'staging_id' => $stagingId,
            'fa_invoice_no' => $orderNo,
            'reason' => 'fully_imported',
        ]);

        $this->broadcastOrderImported([
            'source_order_id' => (string)$paymentId,
            'fa_order_no' => (int)$orderNo,
            'fa_trans_type' => ST_SALESINVOICE,
            'customer_id' => (int)$debtorNo,
            'order_total' => (float)($trans['total_amount'] ?? 0),
            'order_date' => (string)($trans['transaction_date'] ?? date('Y-m-d')),
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
            $amountCents = (int)round(((float)($trans['total_amount'] ?? 0)) * 100);
        }

        $paymentId = (string)($trans['source_payment_id'] ?? $trans['source_transaction_id'] ?? '');

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
        $this->ensureStagingTablesExist();

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
                    // Fall back to the customer's default (first) branch when the
                    // Square location name does not match an FA customer branch
                    // name, instead of skipping the location outright.
                    $branches = $this->custBranchDao->getByDebtorNo($destCust);
                    if (empty($branches)) {
                        $importResults['errors'][] = _("SKIPPED LOCATION: '") . $locName . _("' - No matching FA customer branch found.");
                        $importResults['errors'][] = _("  No branches exist for customer: ") . $destCust;
                        $importResults['locations_skipped']++;
                        continue;
                    }
                    $branch = $branches[0];
                    $importResults['errors'][] = _("WARNING: Location '") . $locName . _("' — using default branch '") . ($branch['br_name'] ?? $branch['branch_code']) . _("' instead.");
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
     * Gets the ISU staging gateway.
     *
     * @return IsuStagingGateway
     */
    public function getGateway(): IsuStagingGateway
    {
        return $this->gateway;
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
        $tipMoney = $payment->getTipMoney();

        return [
            'id' => $payment->getId(),
            'created_at' => $payment->getCreatedAt(),
            'updated_at' => $payment->getUpdatedAt(),
            'amount' => $totalMoney !== null ? $totalMoney->getAmount() : 0,
            'currency' => $totalMoney !== null ? $totalMoney->getCurrency() : '',
            'tax_amount' => 0,
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
