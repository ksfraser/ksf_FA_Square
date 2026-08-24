<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\ImportStaging\Contracts\PaymentRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingPayment;

/**
 * Adapts Square's payment data to ISU's PaymentRepositoryInterface.
 *
 * Uses ISU's staging_payments table for storage, allowing ISU's
 * StagingService to process Square payment data through the standard contract.
 *
 * @requirement FR-SQUARE-ISU-003 Payment Repository Adapter
 * @BABOK Related: BR-SQ-020 Standardize staging on ISU interfaces
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @since 2.4.5
 */
class PaymentRepositoryAdapter implements PaymentRepositoryInterface
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function insert(StagingPayment $payment): int
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "INSERT INTO {$tableName}
                (source, source_payment_id, source_transaction_id, staging_transaction_id,
                 amount, currency, fee, net_amount, payment_method, payment_date,
                 reference, card_brand, pan_suffix, card_entry_method, raw_json,
                 status, source_updated_at)
                VALUES (" . \db_escape($payment->getSource()) . ","
             . \db_escape($payment->getSourcePaymentId(), true) . ","
             . \db_escape($payment->getSourceTransactionId(), true) . ","
             . ($payment->getStagingTransactionId() !== null ? (int)$payment->getStagingTransactionId() : "NULL") . ","
             . (float)$payment->getAmount() . ","
             . \db_escape($payment->getCurrency()) . ","
             . (float)$payment->getFee() . ","
             . (float)$payment->getNetAmount() . ","
             . \db_escape($payment->getPaymentMethod(), true) . ","
             . \db_escape($payment->getPaymentDate() ? $payment->getPaymentDate()->format('Y-m-d') : null, true) . ","
             . \db_escape($payment->getReference(), true) . ","
             . \db_escape($payment->getCardBrand(), true) . ","
             . \db_escape($payment->getPanSuffix(), true) . ","
             . \db_escape($payment->getCardEntryMethod(), true) . ","
             . \db_escape($payment->getRawJson(), true) . ","
             . \db_escape($payment->getStatus()) . ","
             . \db_escape($payment->getSourceUpdatedAt() ? $payment->getSourceUpdatedAt()->format('Y-m-d H:i:s') : null, true)
             . ")";
        \db_query($sql);
        return (int)\db_insert_id();
    }

    public function findById(int $id): ?StagingPayment
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE id = " . (int)$id;
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row ? $this->toModel($row) : null;
        }
        return null;
    }

    public function findBySource(string $source, string $sourcePaymentId): ?StagingPayment
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE source = " . \db_escape($source)
             . " AND source_payment_id = " . \db_escape($sourcePaymentId);
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row ? $this->toModel($row) : null;
        }
        return null;
    }

    public function findByStatus(string $status, ?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE status = " . \db_escape($status);
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY created_at ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function findByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, ?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE payment_date BETWEEN "
             . \db_escape($from->format('Y-m-d')) . " AND " . \db_escape($to->format('Y-m-d'));
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY payment_date ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function findByTransaction(int $stagingTransactionId): array
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE staging_transaction_id = " . (int)$stagingTransactionId
             . " ORDER BY amount DESC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function updateStatus(int $id, string $status, ?float $confidence = null, ?string $error = null): void
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sets = ["status = " . \db_escape($status)];
        if ($confidence !== null) {
            $sets[] = "match_confidence = " . (float)$confidence;
        }
        if ($error !== null) {
            $sets[] = "error_log = " . \db_escape($error);
        }
        $sql = "UPDATE {$tableName} SET " . implode(', ', $sets) . " WHERE id = " . (int)$id;
        \db_query($sql);
    }

    public function updateFaReference(int $id, int $faTransType, int $faTransNo, ?string $faBankAccount = null): void
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sets = [
            "fa_trans_type = " . (int)$faTransType,
            "fa_trans_no = " . (int)$faTransNo,
        ];
        if ($faBankAccount !== null) {
            $sets[] = "fa_bank_account = " . \db_escape($faBankAccount);
        }
        $sql = "UPDATE {$tableName} SET " . implode(', ', $sets) . " WHERE id = " . (int)$id;
        \db_query($sql);
    }

    public function updateBySource(StagingPayment $payment): bool
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "UPDATE {$tableName} SET
                amount = " . (float)$payment->getAmount() . ",
                currency = " . \db_escape($payment->getCurrency() ?? 'CAD') . ",
                fee = " . (float)$payment->getFee() . ",
                net_amount = " . (float)$payment->getNetAmount() . ",
                payment_method = " . \db_escape($payment->getPaymentMethod() ?? '') . ",
                status = " . \db_escape($payment->getStatus()) . "
                WHERE source = " . \db_escape($payment->getSource())
             . " AND source_payment_id = " . \db_escape($payment->getSourcePaymentId() ?? '');
        \db_query($sql);
        return \db_affected_rows() > 0;
    }

    public function getQueueForReconciliation(?string $source = null, int $limit = 100): array
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT * FROM {$tableName} WHERE status IN ('staged', 'validated', 'matched')";
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY payment_date ASC LIMIT " . (int)$limit;
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function countByStatus(?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_payments';
        $sql = "SELECT status, COUNT(*) as count FROM {$tableName}";
        if ($source !== null) {
            $sql .= " WHERE source = " . \db_escape($source);
        }
        $sql .= " GROUP BY status";
        $result = \db_query($sql);
        $counts = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $counts[$row['status']] = (int)$row['count'];
                }
            }
        }
        return $counts;
    }

    /**
     * Convert a raw row to an ISU StagingPayment model.
     *
     * @param array<string,mixed> $row
     * @return StagingPayment
     */
    private function toModel(array $row): StagingPayment
    {
        $payment = new StagingPayment($row['source'] ?? 'square_api');
        $payment->setId((int)($row['id'] ?? 0));
        $payment->setSourcePaymentId($row['source_payment_id'] ?? null);
        $payment->setSourceTransactionId($row['source_transaction_id'] ?? null);
        $payment->setStagingTransactionId($row['staging_transaction_id'] ? (int)$row['staging_transaction_id'] : null);
        $payment->setAmount((float)($row['amount'] ?? 0));
        $payment->setCurrency($row['currency'] ?? 'CAD');
        $payment->setFee((float)($row['fee'] ?? 0));
        $payment->setNetAmount((float)($row['net_amount'] ?? 0));
        $payment->setPaymentMethod($row['payment_method'] ?? null);
        $payment->setReference($row['reference'] ?? null);
        $payment->setCardBrand($row['card_brand'] ?? null);
        $payment->setPanSuffix($row['pan_suffix'] ?? null);
        $payment->setCardEntryMethod($row['card_entry_method'] ?? null);
        $payment->setRawJson($row['raw_json'] ?? null);
        $payment->setStatus($row['status'] ?? 'staged');
        $payment->setMatchConfidence($row['match_confidence'] ? (float)$row['match_confidence'] : null);
        $payment->setFaTransType($row['fa_trans_type'] ? (int)$row['fa_trans_type'] : null);
        $payment->setFaTransNo($row['fa_trans_no'] ? (int)$row['fa_trans_no'] : null);
        $payment->setFaBankAccount($row['fa_bank_account'] ?? null);
        $payment->setErrorLog($row['error_log'] ?? null);

        if (!empty($row['payment_date'])) {
            try {
                $payment->setPaymentDate(new \DateTimeImmutable($row['payment_date']));
            } catch (\Exception $e) {
                // ignore invalid dates
            }
        }

        return $payment;
    }
}
