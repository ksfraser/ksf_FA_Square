<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Customer History DAO
 * 
 * Handles database operations for customer history tracking.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Customer Communication
 */
class CustomerHistoryDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Records customer update in history.
     * 
     * @param array $historyData History data
     * @return int History ID
     */
    public function recordUpdate(array $historyData): int
    {
        $tableName = $this->getTableName();
        
        $sql = "INSERT INTO {$tableName} (
            debtor_no, action, source, details, timestamp
        ) VALUES (
            {$historyData['debtor_no']},
            '{$historyData['action']}',
            '{$historyData['source']}',
            '" . \db_escape($historyData['details']) . "',
            '{$historyData['timestamp']}'
        )";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Gets customer history.
     * 
     * @param int $debtorNo Debtor number
     * @param int $limit Maximum number of history records to return
     * @return array Customer history
     */
    public function getCustomerHistory(int $debtorNo, int $limit = 50): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE debtor_no = {$debtorNo} 
                ORDER BY timestamp DESC 
                LIMIT {$limit}";

        $result = \db_query($sql);
        $history = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $history[] = $row;
                }
            }
        }

        return $history;
    }

    /**
     * Gets history by action type.
     * 
     * @param string $action Action type
     * @param int $limit Maximum number of records to return
     * @return array History records
     */
    public function getHistoryByAction(string $action, int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE action = '{$action}' 
                ORDER BY timestamp DESC 
                LIMIT {$limit}";

        $result = \db_query($sql);
        $history = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $history[] = $row;
                }
            }
        }

        return $history;
    }

    /**
     * Gets history statistics.
     * 
     * @return array Statistics array
     */
    public function getHistoryStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total history records
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // History by action
        $actionSql = "SELECT action, COUNT(*) as count FROM {$tableName} 
                     GROUP BY action ORDER BY count DESC";
        $actionResult = \db_query($actionSql);
        $byAction = [];
        if ($actionResult !== false) {
            while ($row = \db_fetch_assoc($actionResult)) {
                if ($row !== false) {
                    $byAction[$row['action']] = (int)$row['count'];
                }
            }
        }
        
        // Recent history
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_records' => $total,
            'by_action' => $byAction,
            'recent_records' => $recent,
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
                debtor_no INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                source VARCHAR(50) NOT NULL,
                details TEXT,
                timestamp DATETIME NOT NULL,
                INDEX idx_debtor_no (debtor_no),
                INDEX idx_action (action),
                INDEX idx_timestamp (timestamp)
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
        return $this->tablePrefix . 'customer_history';
    }
}