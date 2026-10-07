<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

use DateTimeInterface;
use Exception;

/**
 * Data Access Object for square_import_log table.
 *
 * Tracks import runs with date ranges, environment, and operation type.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SquareImportLogDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * Operation type constants
     */
    public const OP_TYPE_DIRECT = 'direct';
    public const OP_TYPE_STAGE = 'stage';
    public const OP_TYPE_PROCESS = 'process';

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets the full table name.
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tablePrefix . 'square_import_log';
    }

    /**
     * Ensures the table has all required columns.
     * Safe for existing production installations.
     *
     * @return void
     */
    public function ensureTableUpgraded(): void
    {
        $tableName = $this->getTableName();

        $newColumns = [
            'from_date DATE DEFAULT NULL',
            'to_date DATE DEFAULT NULL',
            "environment VARCHAR(20) NOT NULL DEFAULT 'sandbox'",
            "operation_type VARCHAR(20) NOT NULL DEFAULT 'direct'",
            'location_filter VARCHAR(32) DEFAULT NULL',
        ];

        foreach ($newColumns as $colDef) {
            $colName = explode(' ', $colDef)[0];
            $check = @\db_query("SELECT {$colName} FROM {$tableName} LIMIT 1");
            if ($check === false) {
                $alterSql = "ALTER TABLE {$tableName} ADD COLUMN {$colDef}";
                @\db_query($alterSql);
            }
        }
    }

    /**
     * Gets the last N import log entries.
     *
     * @param int $limit Maximum number of entries to return
     * @return array Array of log entries
     */
    public function getRecentLogs(int $limit = 10): array
    {
        $sql = "SELECT * FROM {$this->getTableName()} ORDER BY run_date DESC LIMIT " . (int)$limit;
        $result = \db_query($sql);
        $logs = [];
        if ($result !== false && \db_num_rows($result) > 0) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row === false) {
                    break;
                }
                $logs[] = $row;
            }
        }
        return $logs;
    }

    /**
     * Gets import logs by environment.
     *
     * @param string $environment
     * @param int $limit
     * @return array
     */
    public function getLogsByEnvironment(string $environment, int $limit = 10): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE environment = " . \db_escape($environment) . " ORDER BY run_date DESC LIMIT " . (int)$limit;
        $result = \db_query($sql);
        $logs = [];
        if ($result !== false && \db_num_rows($result) > 0) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row === false) {
                    break;
                }
                $logs[] = $row;
            }
        }
        return $logs;
    }

    /**
     * Finds gaps in imported date ranges.
     * Returns array of gaps [from_date, to_date] that might have been missed.
     *
     * @param string $environment
     * @return array
     */
    public function findDateGaps(string $environment): array
    {
        $tableName = $this->getTableName();
        $gaps = [];

        $sql = "SELECT DISTINCT from_date, to_date FROM {$tableName}
                WHERE environment = " . \db_escape($environment) . "
                AND from_date IS NOT NULL AND to_date IS NOT NULL
                ORDER BY from_date ASC";

        $result = \db_query($sql);
        if ($result === false || \db_num_rows($result) === 0) {
            return $gaps;
        }

        $ranges = [];
        while ($row = \db_fetch_assoc($result)) {
            if ($row !== false) {
                $ranges[] = $row;
            }
        }

        for ($i = 1; $i < count($ranges); $i++) {
            $prevEnd = new DateTimeImmutable($ranges[$i - 1]['to_date']);
            $currStart = new DateTimeImmutable($ranges[$i]['from_date']);
            $gapDay = $prevEnd->modify('+1 day');

            if ($gapDay < $currStart) {
                $gaps[] = [
                    'from_date' => $gapDay->format('Y-m-d'),
                    'to_date' => $currStart->modify('-1 day')->format('Y-m-d'),
                ];
            }
        }

        return $gaps;
    }

    /**
     * Gets the last imported date from the logs.
     *
     * @param string $environment
     * @return string|null Y-m-d format or null
     */
    public function getLastImportedDate(string $environment): ?string
    {
        $tableName = $this->getTableName();
        $sql = "SELECT MAX(to_date) as last_date FROM {$tableName}
                WHERE environment = " . \db_escape($environment) . "
                AND to_date IS NOT NULL
                AND status = 'completed'";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            if ($row !== false && !empty($row['last_date'])) {
                return $row['last_date'];
            }
        }
        return null;
    }

    /**
     * Checks if there are any import log entries.
     *
     * @return bool True if there are log entries, false otherwise
     */
    public function hasLogs(): bool
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->getTableName()}";
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            if ($row !== false && (int)$row['cnt'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inserts a new import log entry (extended with date range tracking).
     *
     * @param string $source Source of import (e.g., 'api')
     * @param int $imported Number of orders imported
     * @param int $skipped Number of orders skipped
     * @param int $failed Number of orders failed
     * @param string $status Status of import (e.g., 'completed')
     * @param string|null $fromDate Import date range start (Y-m-d)
     * @param string|null $toDate Import date range end (Y-m-d)
     * @param string $environment Environment (sandbox/production)
     * @param string $operationType Operation type (direct/stage/process)
     * @param string|null $locationFilter Location filter used
     * @return void
     * @throws Exception if query fails
     */
    public function insertLog(
        string $source,
        int $imported,
        int $skipped,
        int $failed,
        string $status = 'completed',
        ?string $fromDate = null,
        ?string $toDate = null,
        string $environment = 'sandbox',
        string $operationType = self::OP_TYPE_DIRECT,
        ?string $locationFilter = null
    ): void {
        $tableName = $this->getTableName();

        $fields = ['run_date', 'source', 'orders_imported', 'orders_skipped', 'orders_failed', 'status'];
        $values = [
            "'" . date('Y-m-d H:i:s') . "'",
            \db_escape($source),
            $imported,
            $skipped,
            $failed,
            \db_escape($status),
        ];

        if ($fromDate !== null) {
            $fields[] = 'from_date';
            $values[] = \db_escape($fromDate);
        }
        if ($toDate !== null) {
            $fields[] = 'to_date';
            $values[] = \db_escape($toDate);
        }

        $fields[] = 'environment';
        $values[] = \db_escape($environment);

        $fields[] = 'operation_type';
        $values[] = \db_escape($operationType);

        if ($locationFilter !== null) {
            $fields[] = 'location_filter';
            $values[] = \db_escape($locationFilter);
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr})";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert import log: ") . \db_error_msg($db));
        }
    }

    /**
     * Full table name for the refund log.
     *
     * @return string
     */
    public function getRefundLogTableName(): string
    {
        return $this->tablePrefix . 'square_refund_log';
    }

    /**
     * Records a Square refund lifecycle event.
     *
     * RefundService calls this after creating a refund in Square, after
     * cancelling a payment, and again once the FA credit note has been issued.
     * The method DID NOT EXIST while all three callers invoked it, so every one
     * of those paths raised
     *   Error: Call to undefined method SquareImportLogDAO::logRefund()
     * immediately after Square had already accepted the change -- the refund was
     * created at Square but never recorded in FA, and the caller saw an error.
     *
     * Behaviour is an UPSERT keyed on square_refund_id: the first call inserts,
     * and the later "recorded_in_fa" call updates the same row rather than
     * appending a second one. Rows with no square_refund_id (the payment-cancel
     * call) simply insert.
     *
     * Callers pass different subsets of keys, so every field is optional.
     *
     * @param array $data Keys: square_refund_id, square_payment_id,
     *                    fa_credit_note_id, amount, currency, reason, status,
     *                    created_at, updated_at
     * @return int Log row id, or 0 on failure
     */
    public function logRefund(array $data): int
    {
        $tableName = $this->getRefundLogTableName();
        $this->ensureRefundTableExists();

        $existingId = 0;
        $refundId = isset($data['square_refund_id']) ? (string)$data['square_refund_id'] : '';

        if ($refundId !== '') {
            $found = @\db_query(
                "SELECT id FROM {$tableName} WHERE square_refund_id = " . \db_escape($refundId) . " LIMIT 1"
            );
            if ($found && ($row = \db_fetch_assoc($found))) {
                $existingId = (int)$row['id'];
            }
        }

        $columns = [
            'square_refund_id'    => isset($data['square_refund_id']) ? \db_escape((string)$data['square_refund_id']) : null,
            'square_payment_id'   => isset($data['square_payment_id']) ? \db_escape((string)$data['square_payment_id']) : null,
            'fa_credit_note_id'   => isset($data['fa_credit_note_id']) ? (int)$data['fa_credit_note_id'] : null,
            'amount'              => isset($data['amount']) ? (float)$data['amount'] : null,
            'currency'            => isset($data['currency']) && $data['currency'] !== '' ? \db_escape((string)$data['currency']) : null,
            'reason'              => isset($data['reason']) ? \db_escape((string)$data['reason']) : null,
            'status'              => isset($data['status']) ? \db_escape((string)$data['status']) : null,
        ];

        if ($existingId > 0) {
            $sets = [];
            foreach ($columns as $col => $val) {
                if ($val !== null) {
                    $sets[] = "{$col} = " . $val;
                }
            }
            $sets[] = 'updated_at = ' . \db_escape(
                isset($data['updated_at']) ? (string)$data['updated_at'] : date('Y-m-d H:i:s')
            );

            $sql = "UPDATE {$tableName} SET " . implode(', ', $sets) . " WHERE id = {$existingId}";

            return @\db_query($sql) ? $existingId : 0;
        }

        $columns['created_at'] = \db_escape(
            isset($data['created_at']) ? (string)$data['created_at'] : date('Y-m-d H:i:s')
        );
        $columns['updated_at'] = $columns['created_at'];

        $cols = array_keys($columns);
        $vals = array_values($columns);

        $sql = "INSERT INTO {$tableName} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';

        if (!@\db_query($sql)) {
            return 0;
        }

        return (int)\db_insert_id();
    }

    /**
     * Idempotently creates the refund log table.
     *
     * @return void
     */
    private function ensureRefundTableExists(): void
    {
        $tableName = $this->getRefundLogTableName();

        $check = @\db_query("SELECT id FROM {$tableName} LIMIT 1");
        if ($check !== false) {
            return;
        }

        @\db_query(
            "CREATE TABLE IF NOT EXISTS {$tableName} (
                id INT(11) NOT NULL AUTO_INCREMENT,
                square_refund_id VARCHAR(64) DEFAULT NULL,
                square_payment_id VARCHAR(64) DEFAULT NULL,
                fa_credit_note_id INT(11) DEFAULT NULL,
                amount DECIMAL(15,2) DEFAULT NULL,
                currency VARCHAR(8) DEFAULT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_square_refund_id (square_refund_id),
                KEY idx_square_payment_id (square_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

}