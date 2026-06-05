<?php
declare(strict_types=1);

/**
 * Tax Mapping DAO
 * 
 * Handles database operations for tax rate mappings between Square and FA.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.02 - Tax Mapping
 */
class TaxMappingDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets mapping by FA tax ID.
     * 
     * @param int $faTaxId FA tax type ID
     * @return array|null Mapping data or null if not found
     */
    public function getMappingByFaId(int $faTaxId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE fa_tax_id = {$faTaxId}";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets mapping by Square tax ID.
     * 
     * @param string $squareTaxId Square tax rate ID
     * @return array|null Mapping data or null if not found
     */
    public function getMappingBySquareId(string $squareTaxId): ?array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_tax_id = '" . db_escape($squareTaxId) . "'";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
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

        $result = db_query($sql);
        $mappings = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $mappings[] = $row;
                }
            }
        }

        return $mappings;
    }

    /**
     * Inserts new mapping.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     */
    public function insertMapping(array $mappingData): int
    {
        $tableName = $this->getMappingsTableName();
        
        $sql = "INSERT INTO {$tableName} (
            fa_tax_id, square_tax_id, mapping_data, created_at
        ) VALUES (
            {$mappingData['fa_tax_id']},
            '" . db_escape($mappingData['square_tax_id']) . "',
            '" . db_escape($mappingData['mapping_data']) . "',
            '{$mappingData['created_at']}'
        )";

        db_query($sql);
        return db_insert_id($tableName);
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
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE id = {$id}";
        
        return db_query($sql) !== false;
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
        
        return db_query($sql) !== false;
    }

    /**
     * Gets mappings by FA tax ID range.
     * 
     * @param int $startId Start ID
     * @param int $endId End ID
     * @return array Mappings in range
     */
    public function getMappingsByIdRange(int $startId, int $endId): array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE fa_tax_id BETWEEN {$startId} AND {$endId}
                ORDER BY fa_tax_id";

        $result = db_query($sql);
        $mappings = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $mappings[] = $row;
                }
            }
        }

        return $mappings;
    }

    /**
     * Gets mappings by Square tax ID pattern.
     * 
     * @param string $pattern Square tax ID pattern
     * @return array Matching mappings
     */
    public function getMappingsBySquareIdPattern(string $pattern): array
    {
        $tableName = $this->getMappingsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE square_tax_id LIKE '%" . db_escape($pattern) . "%'
                ORDER BY square_tax_id";

        $result = db_query($sql);
        $mappings = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
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
        $totalResult = db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Mappings by environment
        $envSql = "SELECT COUNT(*) as count FROM {$tableName} 
                  WHERE mapping_data LIKE '%\"environment\":\"production\"%'";
        $envResult = db_query($envSql);
        $production = 0;
        if ($envResult !== false) {
            $row = db_fetch_assoc($envResult);
            $production = (int)($row['count'] ?? 0);
        }
        
        // Recent mappings
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $recentResult = db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_mappings' => $total,
            'production_mappings' => $production,
            'sandbox_mappings' => $total - $production,
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
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_tax_id INT NOT NULL,
                square_tax_id VARCHAR(100) NOT NULL,
                mapping_data JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_mapping (fa_tax_id, square_tax_id),
                INDEX idx_fa_tax_id (fa_tax_id),
                INDEX idx_square_tax_id (square_tax_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Gets mappings table name.
     * 
     * @return string Table name
     */
    private function getMappingsTableName(): string
    {
        return $this->tablePrefix . 'tax_mappings';
    }
}