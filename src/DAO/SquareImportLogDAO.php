<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

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
        $sql = "SELECT * FROM {$tableName} WHERE environment = '" . \db_escape($environment) . "' ORDER BY run_date DESC LIMIT " . (int)$limit;
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
                WHERE environment = '" . \db_escape($environment) . "'
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
                WHERE environment = '" . \db_escape($environment) . "'
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
            $values[] = "'" . \db_escape($fromDate) . "'";
        }
        if ($toDate !== null) {
            $fields[] = 'to_date';
            $values[] = "'" . \db_escape($toDate) . "'";
        }

        $fields[] = 'environment';
        $values[] = "'" . \db_escape($environment) . "'";

        $fields[] = 'operation_type';
        $values[] = "'" . \db_escape($operationType) . "'";

        if ($locationFilter !== null) {
            $fields[] = 'location_filter';
            $values[] = "'" . \db_escape($locationFilter) . "'";
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr})";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert import log: ") . db_error());
        }
    }
}