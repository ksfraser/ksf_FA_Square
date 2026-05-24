<?php
declare(strict_types=1);

/**
 * Location Mapping DAO
 * 
 * Handles database operations for location mapping between FA and Square.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.02 - Location Mapping
 */
class LocationMappingDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets Square location ID for FA stock ID.
     * 
     * @param int $stockId FA stock ID
     * @return string|null Square location ID or null if not found
     */
    public function getSquareLocationId(int $stockId): ?string
    {
        $tableName = $this->getTableName();
        $sql = "SELECT square_location_id FROM {$tableName} WHERE fa_stock_id = {$stockId}";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row !== false ? $row['square_location_id'] : null;
        }

        return null;
    }

    /**
     * Gets Square item ID for FA stock ID.
     * 
     * @param int $stockId FA stock ID
     * @return string|null Square item ID or null if not found
     */
    public function getSquareItemId(int $stockId): ?string
    {
        $tableName = $this->getTableName();
        $sql = "SELECT square_item_id FROM {$tableName} WHERE fa_stock_id = {$stockId}";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row !== false ? $row['square_item_id'] : null;
        }

        return null;
    }

    /**
     * Gets FA stock ID for Square location ID.
     * 
     * @param string $squareLocationId Square location ID
     * @return array Array of FA stock IDs
     */
    public function getFAStockIds(string $squareLocationId): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT fa_stock_id FROM {$tableName} WHERE square_location_id = '" . db_escape($squareLocationId) . "'";
        
        $result = db_query($sql);
        $stockIds = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $stockIds[] = (int)$row['fa_stock_id'];
                }
            }
        }

        return $stockIds;
    }

    /**
     * Creates location mapping.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     */
    public function createMapping(array $mappingData): int
    {
        $tableName = $this->getTableName();
        
        $sql = "INSERT INTO {$tableName} (
            fa_stock_id, square_location_id, square_item_id, created_at
        ) VALUES (
            {$mappingData['fa_stock_id']},
            '" . db_escape($mappingData['square_location_id']) . "',
            '" . db_escape($mappingData['square_item_id']) . "',
            '{$mappingData['created_at']}'
        )";

        db_query($sql);
        return db_insert_id($tableName);
    }

    /**
     * Updates location mapping.
     * 
     * @param int $stockId FA stock ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMapping(int $stockId, array $data): bool
    {
        $tableName = $this->getTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = '" . db_escape($value) . "'";
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE fa_stock_id = {$stockId}";
        
        return db_query($sql) !== false;
    }

    /**
     * Gets all location mappings.
     * 
     * @param int $limit Maximum number of mappings to return
     * @return array Location mappings
     */
    public function getAllMappings(int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} ORDER BY created_at DESC LIMIT {$limit}";

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
     * Gets location mapping statistics.
     * 
     * @return array Statistics array
     */
    public function getMappingStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total mappings
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Mappings by Square location
        $locationSql = "SELECT square_location_id, COUNT(*) as count FROM {$tableName} 
                       GROUP BY square_location_id ORDER BY count DESC";
        $locationResult = db_query($locationSql);
        $byLocation = [];
        if ($locationResult !== false) {
            while ($row = db_fetch_assoc($locationResult)) {
                if ($row !== false) {
                    $byLocation[$row['square_location_id']] = (int)$row['count'];
                }
            }
        }
        
        // Mappings for "*ALL*" location
        $allSql = "SELECT COUNT(*) as all_count FROM {$tableName} 
                 WHERE square_location_id = '*ALL*'";
        $allResult = db_query($allSql);
        $allCount = 0;
        if ($allResult !== false) {
            $row = db_fetch_assoc($allResult);
            $allCount = (int)($row['all_count'] ?? 0);
        }
        
        return [
            'total_mappings' => $total,
            'by_location' => $byLocation,
            'all_location_count' => $allCount,
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
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_stock_id INT NOT NULL,
                square_location_id VARCHAR(100) NOT NULL,
                square_item_id VARCHAR(100) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_mapping (fa_stock_id, square_location_id),
                INDEX idx_fa_stock_id (fa_stock_id),
                INDEX idx_square_location_id (square_location_id),
                INDEX idx_square_item_id (square_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Gets the table name.
     * 
     * @return string Table name
     */
    private function getTableName(): string
    {
        return $this->tablePrefix . 'square_location_mappings';
    }
}