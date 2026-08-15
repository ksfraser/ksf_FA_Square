<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Communication DAO
 * 
 * Handles database operations for customer communication tracking.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Customer Communication
 */
class CommunicationDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Records customer communication.
     * 
     * @param array $communicationData Communication data
     * @return int Communication ID
     */
    public function recordCommunication(array $communicationData): int
    {
        $tableName = $this->getTableName();
        
        $sql = "INSERT INTO {$tableName} (
            debtor_no, type, message, timestamp, created_at
        ) VALUES (
            {$communicationData['debtor_no']},
            '{$communicationData['type']}',
            '" . \db_escape($communicationData['message']) . "',
            '{$communicationData['timestamp']}',
            '{$communicationData['timestamp']}'
        )";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Gets customer communications.
     * 
     * @param int $debtorNo Debtor number
     * @param int $limit Maximum number of communications to return
     * @return array Customer communications
     */
    public function getCommunications(int $debtorNo, int $limit = 50): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE debtor_no = {$debtorNo} 
                ORDER BY timestamp DESC 
                LIMIT {$limit}";

        $result = \db_query($sql);
        $communications = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $communications[] = $row;
                }
            }
        }

        return $communications;
    }

    /**
     * Gets communications by type.
     * 
     * @param string $type Communication type
     * @param int $limit Maximum number of communications to return
     * @return array Communications
     */
    public function getCommunicationsByType(string $type, int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE type = '{$type}' 
                ORDER BY timestamp DESC 
                LIMIT {$limit}";

        $result = \db_query($sql);
        $communications = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $communications[] = $row;
                }
            }
        }

        return $communications;
    }

    /**
     * Gets communication statistics.
     * 
     * @return array Statistics array
     */
    public function getCommunicationStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total communications
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Communications by type
        $typeSql = "SELECT type, COUNT(*) as count FROM {$tableName} 
                   GROUP BY type ORDER BY count DESC";
        $typeResult = \db_query($typeSql);
        $byType = [];
        if ($typeResult !== false) {
            while ($row = \db_fetch_assoc($typeResult)) {
                if ($row !== false) {
                    $byType[$row['type']] = (int)$row['count'];
                }
            }
        }
        
        // Recent communications
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_communications' => $total,
            'by_type' => $byType,
            'recent_communications' => $recent,
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
                type VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                timestamp DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_debtor_no (debtor_no),
                INDEX idx_type (type),
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
        return $this->tablePrefix . 'customer_communications';
    }
}