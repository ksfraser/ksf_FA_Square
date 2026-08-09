<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

/**
 * Square Customer DAO
 * 
 * Handles database operations for Square customer mappings.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-01.01 - Customer Synchronization
 */
class SquareCustomerDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets customer by Square customer ID.
     * 
     * @param string $squareCustomerId Square customer ID
     * @return array|null Customer data or null if not found
     */
    public function getBySquareId(string $squareCustomerId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_customer_id = '" . \db_escape($squareCustomerId) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets customer by FA debtor number.
     * 
     * @param int $debtorNo FA debtor number
     * @return array|null Customer data or null if not found
     */
    public function getByDebtorNo(int $debtorNo): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE fa_debtor_no = {$debtorNo}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Updates mapping by Square customer ID.
     * 
     * @param string $squareCustomerId Square customer ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMappingBySquareId(string $squareCustomerId, array $data): bool
    {
        $tableName = $this->getTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'sync_at' || $key === 'crm_sync_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE square_customer_id = '" . \db_escape($squareCustomerId) . "'";
        
        return \db_query($sql) !== false;
    }

    /**
     * Updates mapping by FA debtor number.
     * 
     * @param int $debtorNo FA debtor number
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMappingByDebtorNo(int $debtorNo, array $data): bool
    {
        $tableName = $this->getTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'sync_at' || $key === 'crm_sync_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE fa_debtor_no = {$debtorNo}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Inserts customer mapping.
     * 
     * @param array $data Mapping data
     * @return int Mapping ID
     */
    public function insertMapping(array $data): int
    {
        $tableName = $this->getTableName();
        
        $sql = "INSERT INTO {$tableName} (
            fa_debtor_no, square_customer_id, sync_at, sync_direction, crm_sync_at, created_at
        ) VALUES (
            {$data['fa_debtor_no']},
            '" . \db_escape($data['square_customer_id']) . "',
            '{$data['sync_at']}',
            '" . \db_escape($data['sync_direction'] ?? 'bidirectional') . "',
            " . (isset($data['crm_sync_at']) ? "'" . \db_escape($data['crm_sync_at']) . "'" : 'NULL') . ",
            '{$data['created_at']}'
        )";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Gets all customer mappings.
     * 
     * @param int $limit Maximum number of mappings to return
     * @return array Customer mappings
     */
    public function getAllMappings(int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} ORDER BY created_at DESC LIMIT {$limit}";

        $result = \db_query($sql);
        $mappings = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $mappings[] = $row;
                }
            }
        }

        return $mappings;
    }

    /**
     * Gets customer mapping statistics.
     * 
     * @return array Statistics array
     */
    public function getMappingStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total mappings
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Mapped by direction
        $directionSql = "SELECT sync_direction, COUNT(*) as count FROM {$tableName} 
                        GROUP BY sync_direction";
        $directionResult = \db_query($directionSql);
        $byDirection = [];
        if ($directionResult !== false) {
            while ($row = \db_fetch_assoc($directionResult)) {
                if ($row !== false) {
                    $byDirection[$row['sync_direction']] = (int)$row['count'];
                }
            }
        }
        
        // Recent syncs
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_mappings' => $total,
            'sync_directions' => $byDirection,
            'recent_syncs' => $recent,
        ];
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_debtor_no INT NOT NULL,
                square_customer_id VARCHAR(100) NOT NULL,
                sync_at DATETIME,
                sync_direction VARCHAR(20) DEFAULT 'bidirectional',
                crm_sync_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_mapping (fa_debtor_no, square_customer_id),
                INDEX idx_square_id (square_customer_id),
                INDEX idx_debtor_no (fa_debtor_no),
                INDEX idx_sync_at (sync_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Gets the table name.
     * 
     * @return string Table name
     */
    private function getTableName(): string
    {
        return $this->tablePrefix . 'square_customer_mappings';
    }
}