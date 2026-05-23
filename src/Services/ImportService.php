<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Exception;
use DateTimeImmutable;
use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\DAO\DebtorsMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\CustBranchDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SalesOrdersDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareImportLogDAO;
use Ksfraser\Frontaccounting\SquareUp\Pull\OrderImporter;
use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;
use Square\Exceptions\ApiException;
use Square\SquareClient;

/**
 * Service class to handle Square import logic.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ImportService
{
    private string $tablePrefix;
    private Settings $settings;
    private SquareClient $client;
    private OrderImporter $orderImporter;
    private DebtorsMasterDAO $debtorsMasterDao;
    private CustBranchDAO $custBranchDao;
    private SalesOrdersDAO $salesOrdersDao;
    private SquareImportLogDAO $squareImportLogDao;

    public function __construct(
        string $tablePrefix,
        Settings $settings,
        SquareClient $client
    ) {
        $this->tablePrefix = $tablePrefix;
        $this->settings = $settings;
        $this->client = $client;
        $this->orderImporter = new OrderImporter($client, $settings);
        $this->debtorsMasterDao = new DebtorsMasterDAO($tablePrefix);
        $this->custBranchDao = new CustBranchDAO($tablePrefix);
        $this->salesOrdersDao = new SalesOrdersDAO($tablePrefix);
        $this->squareImportLogDao = new SquareImportLogDAO($tablePrefix);
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
     * Performs the import of Square orders.
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
        ];

        try {
            $fromDateTime = new DateTimeImmutable($fromDate);
            $toDateTime = new DateTimeImmutable($toDate);
            $destCust = (int)$customer['debtor_no'];

            foreach ($locations as $locId => $locName) {
                if ($locationFilter !== '' && $locationFilter !== $locId) {
                    continue;
                }

                $branch = $this->custBranchDao->getByDebtorNoAndName($destCust, $locName);
                if (!$branch) {
                    $importResults['errors'][] = _("Skipping location: ") . $locName . _(" - no matching FA branch");
                    continue;
                }

                $payments = $this->orderImporter->listPayments($fromDateTime, $toDateTime, $locId);

                foreach ($payments as $payment) {
                    $paymentMethod = str_replace("CREDIT_CARD", "CARD", $payment->getSourceType());
                    if ($paymentMethod === "NO_SALE") {
                        continue;
                    }

                    // Check if already imported
                    if ($this->salesOrdersDao->orderExists($payment->getId())) {
                        $importResults['errors'][] = _("Skipping (already imported): ") . $payment->getId();
                        $importResults['skipped']++;
                        continue;
                    }

                    // Check if refunded
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
                        $importResults['errors'][] = _("TRIAL: Would import ") . $payment->getId()
                            . " (" . $payment->getTotalMoney()->getAmount() / 100 . " "
                            . $payment->getTotalMoney()->getCurrency() . ")";
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
                                    // Ignore errors, use item name as SKU
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
                        $totalOrder = $payment->getTotalMoney()->getAmount() / 100;
                        $adj = round($totalOrder - $total, 2);
                        if ($adj != 0 && $adjustmentItem !== '') {
                            $importResults['errors'][] = _("Adjustment needed: ") . $adj;
                            add_to_order($cart, $adjustmentItem, 1, $adj, 0);
                        }

                        $orderNo = $cart->write(1);
                        $importResults['errors'][] = _("Created invoice #") . $orderNo
                            . _(" for ") . $payment->getId();
                        $importResults['imported']++;
                    }
                }
            }

            if (!$trialRun) {
                // Log the import
                $this->squareImportLogDao->insertLog(
                    'api',
                    $importResults['imported'],
                    $importResults['skipped'],
                    $importResults['failed'],
                    'completed'
                );

                // Update last import date if needed
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
}