<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\ImportStaging\Contracts\TransactionRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingTransaction;
use ksfraser\FrontAccounting\Square\DAO\TransactionStagingDAO;

/**
 * Adapts Square's TransactionStagingDAO to ISU's TransactionRepositoryInterface.
 *
 * Bridges Square's proprietary ksf_import_square_transactions table to the
 * standardized ISU staging interface, allowing ISU's StagingService to process
 * Square transaction data through the common contract.
 *
 * Extra Square-specific fields (device, staff, location, deposit, etc.) are
 * preserved in raw_json so no data is lost during the translation.
 *
 * @requirement FR-SQUARE-ISU-001 Transaction Repository Adapter
 * @BABOK Related: BR-SQ-020 Standardize staging on ISU interfaces
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @since 2.4.5
 */
class TransactionRepositoryAdapter implements TransactionRepositoryInterface
{
    /** @var TransactionStagingDAO */
    private $dao;

    /**
     * @param string $tablePrefix Database table prefix (e.g. '0_')
     * @since 2.4.5
     */
    public function __construct(string $tablePrefix)
    {
        $this->dao = new TransactionStagingDAO($tablePrefix);
    }

/**
 * Adapter for Square Transaction DTO.
 *
 * @deprecated Use ksfraser/staging-dto StagingOrder/StagingPayment/StagingRefund via ISU hooks instead.
 *             This adapter is maintained for backward compatibility only.
 *             New code should create DTOs and call hook_invoke('ksf_FA_ImportStagingProcessing_UI', 'stageEntity', $dto).
 *
 * @package Ksfraser\FrontAccounting\Square\Staging
 * @since 1.0.0
 * @deprecated 1.1.0 Use StagingOrder/StagingPayment/StagingRefund DTOs via hooks
 */
    public function insert(StagingTransaction $transaction): int
    {
        return $this->dao->insert($this->toSquareRow($transaction));
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?StagingTransaction
    {
        $row = $this->dao->getById($id);
        return $row ? $this->toStagingTransaction($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findBySource(string $source, string $sourceTransactionId): ?StagingTransaction
    {
        $row = $this->dao->getByTransactionId($sourceTransactionId);
        return $row ? $this->toStagingTransaction($row) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findByStatus(string $status, ?string $source = null): array
    {
        $environment = $source === 'square_csv' ? null : null;
        $rows = $this->dao->getByStatus($status, $environment);
        return array_map([$this, 'toStagingTransaction'], $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function findByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, ?string $source = null): array
    {
        $rows = $this->dao->getByStatus(
            'staged',
            null,
            $from->format('Y-m-d'),
            $to->format('Y-m-d')
        );
        return array_map([$this, 'toStagingTransaction'], $rows);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStatus(int $id, string $status, ?float $confidence = null, ?string $error = null): void
    {
        $extra = [];
        if ($error !== null) {
            $extra['error_log'] = $error;
        }
        $this->dao->updateStatus($id, $status, $extra);
    }

    /**
     * {@inheritdoc}
     */
    public function updateFaReference(int $id, int $invoiceNo, ?int $debtorNo = null): void
    {
        $extra = ['fa_invoice_no' => $invoiceNo];
        if ($debtorNo !== null) {
            $extra['fa_debtor_no'] = $debtorNo;
        }
        $this->dao->update($id, $extra);
    }

    /**
     * {@inheritdoc}
     */
    public function updateBySource(StagingTransaction $transaction): bool
    {
        $existing = $this->dao->getByTransactionId($transaction->getSourceTransactionId());
        if (!$existing) {
            return false;
        }
        $this->dao->update((int)$existing['id'], $this->toSquareRow($transaction));
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueueForProcessing(?string $source = null, int $limit = 100): array
    {
        $rows = $this->dao->getByStatus('staged');
        $sliced = array_slice($rows, 0, $limit);
        return array_map([$this, 'toStagingTransaction'], $sliced);
    }

    /**
     * {@inheritdoc}
     */
    public function countByStatus(?string $source = null): array
    {
        return $this->dao->getStatusCounts();
    }

    /**
     * Convert an ISU StagingTransaction to a Square row array.
     *
     * @param StagingTransaction $transaction
     * @return array<string,mixed>
     */
    public function toSquareRow(StagingTransaction $transaction): array
    {
        $raw = $transaction->getRawJson();
        $extra = is_string($raw) ? (json_decode($raw, true) ?? []) : (is_array($raw) ? $raw : []);

        return array_merge($extra, [
            'source' => $transaction->getSource() ?? 'api',
            'transaction_id' => $transaction->getSourceTransactionId() ?? '',
            'square_order_id' => $transaction->getSourceOrderId() ?: null,
            'payment_id' => $transaction->getSourcePaymentId() ?? '',
            'Date' => $transaction->getTransactionDate()
                ? $transaction->getTransactionDate()->format('Y-m-d')
                : date('Y-m-d'),
            'total_collected' => $transaction->getTotalAmount(),
            'tax' => $transaction->getTaxAmount(),
            'tip' => $transaction->getTipAmount(),
            'discounts' => $transaction->getDiscountAmount(),
            'currency' => $transaction->getCurrency(),
            'customer_name' => $transaction->getCustomerName() ?? '',
            'Customer_id' => (int)($transaction->getCustomerId() ?? 0),
            'status' => $transaction->getStatus() ?? 'staged',
            'fa_invoice_no' => $transaction->getFaInvoiceNo(),
            'fa_debtor_no' => $transaction->getFaDebtorNo(),
            'error_log' => $transaction->getErrorLog(),
        ]);
    }

    /**
     * Convert a Square row array to an ISU StagingTransaction model.
     *
     * Square-specific fields not in the ISU model are preserved in raw_json.
     *
     * @param array<string,mixed> $row
     * @return StagingTransaction
     */
    public function toStagingTransaction(array $row): StagingTransaction
    {
        $extraFields = [
            'Time', 'Timezone', 'gross_sales', 'net_sales', 'service_charges',
            'gift_card_sales', 'partial_refunds', 'card', 'card_entry_methods',
            'cash', 'square_gift_card', 'other_tender', 'other_tender_type',
            'other_tender_note', 'fees', 'net_total', 'card_brand', 'PAN_suffix',
            'device_name', 'staff_name', 'staff_id', 'description', 'details',
            'event_type', 'location', 'Dining_option', 'customer_reference_id',
            'device_nickname', 'third_party_fees', 'deposit_id', 'deposit_date',
            'deposit_details', 'fee_percentage_rate', 'fee_fixed_rate',
            'refund_reason', 'discount_name', 'transaction_status',
            'order_reference_id', 'fulfillment_note', 'free_processing_applied',
            'channel', 'unattributed_tips', 'square_location_id',
            'square_customer_id', 'environment', 'fa_branch_code',
            'square_transaction_id', 'Date',
        ];

        $rawJson = [];
        foreach ($extraFields as $field) {
            if (isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null) {
                $rawJson[$field] = $row[$field];
            }
        }

        $tx = new StagingTransaction($row['source'] ?? 'api');
        $tx->setId((int)($row['id'] ?? 0));
        $tx->setSourceTransactionId($row['transaction_id'] ?? $row['square_transaction_id'] ?? '');
        $tx->setSourceOrderId($row['square_order_id'] ?? null);
        $tx->setSourcePaymentId($row['payment_id'] ?? null);

        if (!empty($row['Date'])) {
            try {
                $tx->setTransactionDate(new \DateTimeImmutable($row['Date']));
            } catch (\Exception $e) {
                // ignore invalid dates
            }
        }

        $tx->setTotalAmount((float)($row['total_collected'] ?? $row['total_amount'] ?? 0));
        $tx->setTaxAmount((float)($row['tax'] ?? $row['tax_amount'] ?? 0));
        $tx->setTipAmount((float)($row['tip'] ?? $row['tip_amount'] ?? 0));
        $tx->setDiscountAmount((float)($row['discounts'] ?? $row['discount_amount'] ?? 0));
        $tx->setShippingAmount((float)($row['shipping_amount'] ?? 0));
        $tx->setCurrency($row['currency'] ?? 'USD');
        $tx->setCustomerName($row['customer_name'] ?? null);
        $tx->setCustomerEmail($row['customer_email'] ?? null);
        $tx->setCustomerId(!empty($row['Customer_id']) ? (string)$row['Customer_id'] : null);
        $tx->setStatus($row['status'] ?? 'staged');
        $tx->setFaInvoiceNo($row['fa_invoice_no'] ? (int)$row['fa_invoice_no'] : null);
        $tx->setFaDebtorNo($row['fa_debtor_no'] ? (int)$row['fa_debtor_no'] : null);
        $tx->setErrorLog($row['error_log'] ?? null);

        if (!empty($rawJson)) {
            $tx->setRawJson(json_encode($rawJson));
        }

        if (!empty($row['created_at'])) {
            try {
                $tx->setCreatedAt(new \DateTimeImmutable($row['created_at']));
            } catch (\Exception $e) {
                // ignore
            }
        }

        return $tx;
    }
}
