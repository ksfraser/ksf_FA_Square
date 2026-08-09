<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

/**
 * Payment Mapping DAO
 * 
 * Handles database operations for payment mappings between Square and FA.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Payment Mapping
 */
class PaymentMappingDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets payment by Square payment ID.
     * 
     * @param string $squarePaymentId Square payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentBySquareId(string $squarePaymentId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_payment_id = '" . \db_escape($squarePaymentId) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets refund by Square refund ID.
     * 
     * @param string $squareRefundId Square refund ID
     * @return array|null Refund data or null if not found
     */
    public function getRefundBySquareId(string $squareRefundId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_refund_id = '" . \db_escape($squareRefundId) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets payment by FA payment ID.
     * 
     * @param int $faPaymentId FA payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentByFaId(int $faPaymentId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE fa_payment_id = {$faPaymentId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets refund by FA refund ID.
     * 
     * @param int $faRefundId FA refund ID
     * @return array|null Refund data or null if not found
     */
    public function getRefundByFaId(int $faRefundId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE fa_refund_id = {$faRefundId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets all mappings.
     * 
     * @return array All mappings
     */
    public function getAllMappings(): array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} ORDER BY created_at DESC";

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
     * Creates new mapping.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     */
    public function createMapping(array $mappingData): int
    {
        $tableName = $this->getMappingsTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($mappingData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = "'" . \db_escape($value) . "'";
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Updates mapping.
     * 
     * @param int $id Mapping ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMapping(int $id, array $data): bool
    {
        $tableName = $this->getMappingsTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE id = {$id}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes mapping.
     * 
     * @param int $id Mapping ID
     * @return bool Success status
     */
    public function deleteMapping(int $id): bool
    {
        $tableName = $this->getMappingsTableName();
        $sql = "DELETE FROM {$tableName} WHERE id = {$id}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets mappings by FA payment ID range.
     * 
     * @param int $startId Start ID
     * @param int $endId End ID
     * @return array Mappings in range
     */
    public function getMappingsByFaIdRange(int $startId, int $endId): array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE fa_payment_id BETWEEN {$startId} AND {$endId}
                ORDER BY fa_payment_id";

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
     * Gets mappings by Square payment ID pattern.
     * 
     * @param string $pattern Square payment ID pattern
     * @return array Matching mappings
     */
    public function getMappingsBySquareIdPattern(string $pattern): array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE square_payment_id LIKE '%" . \db_escape($pattern) . "%'
                ORDER BY square_payment_id";

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
     * Gets mapping statistics.
     * 
     * @return array Statistics array
     */
    public function getMappingStatistics(): array
    {
        $tableName = $this->getMappingsTableName();
        
        // Total mappings
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Payment mappings
        $paymentSql = "SELECT COUNT(*) as payments FROM {$tableName} WHERE fa_payment_id IS NOT NULL";
        $paymentResult = \db_query($paymentSql);
        $payments = 0;
        if ($paymentResult !== false) {
            $row = \db_fetch_assoc($paymentResult);
            $payments = (int)($row['payments'] ?? 0);
        }
        
        // Refund mappings
        $refundSql = "SELECT COUNT(*) as refunds FROM {$tableName} WHERE fa_refund_id IS NOT NULL";
        $refundResult = \db_query($refundSql);
        $refunds = 0;
        if ($refundResult !== false) {
            $row = \db_fetch_assoc($refundResult);
            $refunds = (int)($row['refunds'] ?? 0);
        }
        
        // Recent mappings
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_mappings' => $total,
            'payment_mappings' => $payments,
            'refund_mappings' => $refunds,
            'recent_mappings' => $recent,
        ];
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getMappingsTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_payment_id INT,
                fa_refund_id INT,
                square_payment_id VARCHAR(100),
                square_refund_id VARCHAR(100),
                original_fa_payment_id INT,
                mapping_data JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fa_payment_id (fa_payment_id),
                INDEX idx_fa_refund_id (fa_refund_id),
                INDEX idx_square_payment_id (square_payment_id),
                INDEX idx_square_refund_id (square_refund_id),
                INDEX idx_original_fa_payment_id (original_fa_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Gets mappings table name.
     * 
     * @return string Table name
     */
    private function getMappingsTableName(): string
    {
        return $this->tablePrefix . 'payment_mappings';
    }
}