<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use DateTimeInterface;
use ksfraser\FrontAccounting\ImportStaging\Contracts\AuditLogRepositoryInterface;

/**
 * Adapts Square's import log to ISU's AuditLogRepositoryInterface.
 *
 * Stores audit entries in the ISU staging_log table, providing a unified
 * audit trail across Square and other ISU sources.
 *
 * @requirement FR-SQUARE-ISU-ADAPTER Audit Log Repository Adapter
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class AuditLogRepositoryAdapter implements AuditLogRepositoryInterface
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function log(
        string $recordType,
        int $recordId,
        string $action,
        ?string $source = null,
        array $details = []
    ): int {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "INSERT INTO {$tableName} (record_type, record_id, action, source, details)
                VALUES (" . \db_escape($recordType) . ","
             . (int)$recordId . ","
             . \db_escape($action) . ","
             . \db_escape($source, true) . ","
             . (!empty($details) ? \db_escape(json_encode($details)) : "NULL")
             . ")";
        \db_query($sql);
        return (int)\db_insert_id();
    }

    public function findByRecord(string $recordType, int $recordId): array
    {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "SELECT * FROM {$tableName} WHERE record_type = " . \db_escape($recordType)
             . " AND record_id = " . (int)$recordId . " ORDER BY created_at DESC";
        return $this->fetchRows($sql);
    }

    public function findByAction(string $action, ?string $source = null, int $limit = 100): array
    {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "SELECT * FROM {$tableName} WHERE action = " . \db_escape($action);
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
        return $this->fetchRows($sql);
    }

    public function findByDateRange(DateTimeInterface $from, DateTimeInterface $to, ?string $action = null): array
    {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "SELECT * FROM {$tableName} WHERE created_at BETWEEN "
             . \db_escape($from->format('Y-m-d H:i:s')) . " AND "
             . \db_escape($to->format('Y-m-d H:i:s'));
        if ($action !== null) {
            $sql .= " AND action = " . \db_escape($action);
        }
        $sql .= " ORDER BY created_at DESC";
        return $this->fetchRows($sql);
    }

    public function getRecent(int $limit = 50): array
    {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "SELECT * FROM {$tableName} ORDER BY created_at DESC LIMIT " . (int)$limit;
        return $this->fetchRows($sql);
    }

    public function countByAction(?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_log';
        $sql = "SELECT action, COUNT(*) as count FROM {$tableName}";
        if ($source !== null) {
            $sql .= " WHERE source = " . \db_escape($source);
        }
        $sql .= " GROUP BY action";
        $result = \db_query($sql);
        $counts = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $counts[$row['action']] = (int)$row['count'];
                }
            }
        }
        return $counts;
    }

    /**
     * Execute a SQL query and return all rows.
     *
     * @param string $sql
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(string $sql): array
    {
        $result = \db_query($sql);
        $rows = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }
}
