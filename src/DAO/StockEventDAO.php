<?php
declare(strict_types=1);

/**
 * Stock Event DAO
 * 
 * Handles database operations for stock event logging and tracking.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.03 - Stock Movements, FR-05.03 - Webhook Logging
 */
class StockEventDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Logs a stock move event.
     * 
     * @param array $stockMove Stock move data
     * @param bool $success Whether the operation was successful
     * @param string|null $error Error message if failed
     * @return int Event ID
     */
    public function logStockMove(array $stockMove, bool $success, ?string $error = null): int
    {
        $data = [
            'event_type' => 'stock_move',
            'stock_id' => $stockMove['item_id'],
            'location_id' => $stockMove['from_location'] ?? null,
            'to_location' => $stockMove['to_location'] ?? null,
            'quantity' => $stockMove['quantity'],
            'reason' => $stockMove['reason'],
            'fa_move_id' => $stockMove['fa_move_id'] ?? null,
            'event_data' => json_encode($stockMove),
            'processed_successfully' => $success,
            'error_message' => $error,
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Logs a stock adjustment event.
     * 
     * @param array $adjustment Adjustment data
     * @param bool $success Whether the operation was successful
     * @param string|null $error Error message if failed
     * @return int Event ID
     */
    public function logStockAdjustment(array $adjustment, bool $success, ?string $error = null): int
    {
        $data = [
            'event_type' => 'stock_adjustment',
            'stock_id' => $adjustment['item_id'],
            'location_id' => null, // Adjustments are location-specific in Square API
            'quantity_change' => $adjustment['quantity_change'],
            'reason' => $adjustment['reason'],
            'fa_adjustment_id' => $adjustment['fa_adjustment_id'] ?? null,
            'event_data' => json_encode($adjustment),
            'processed_successfully' => $success,
            'error_message' => $error,
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Logs a stock count event.
     * 
     * @param array $count Count data
     * @param bool $success Whether the operation was successful
     * @param string|null $error Error message if failed
     * @return int Event ID
     */
    public function logStockCount(array $count, bool $success, ?string $error = null): int
    {
        $data = [
            'event_type' => 'inventory_count',
            'stock_id' => $count['item_id'],
            'counted_quantity' => $count['counted_quantity'],
            'expected_quantity' => $count['expected_quantity'],
            'difference' => $count['difference'],
            'reason' => $count['reason'],
            'fa_count_id' => $count['fa_count_id'] ?? null,
            'event_data' => json_encode($count),
            'processed_successfully' => $success,
            'error_message' => $error,
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Logs an unmapped stock move.
     * 
     * @param array $move Stock move data
     * @return int Event ID
     */
    public function logUnmappedMove(array $move): int
    {
        $data = [
            'event_type' => 'unmapped_move',
            'stock_id' => $move['stock_id'],
            'reason' => 'No Square location mapping found',
            'event_data' => json_encode($move),
            'processed_successfully' => false,
            'error_message' => 'No Square location mapping found',
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Logs an unmapped stock adjustment.
     * 
     * @param array $adjustment Adjustment data
     * @return int Event ID
     */
    public function logUnmappedAdjustment(array $adjustment): int
    {
        $data = [
            'event_type' => 'unmapped_adjustment',
            'stock_id' => $adjustment['stock_id'],
            'reason' => 'No Square item mapping found',
            'event_data' => json_encode($adjustment),
            'processed_successfully' => false,
            'error_message' => 'No Square item mapping found',
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Logs an unmapped stock count.
     * 
     * @param array $count Count data
     * @return int Event ID
     */
    public function logUnmappedCount(array $count): int
    {
        $data = [
            'event_type' => 'unmapped_count',
            'stock_id' => $count['stock_id'],
            'reason' => 'No Square item mapping found',
            'event_data' => json_encode($count),
            'processed_successfully' => false,
            'error_message' => 'No Square item mapping found',
            'processed_at' => date('Y-m-d H:i:s')
        ];

        return $this->insertEvent($data);
    }

    /**
     * Gets all stock events.
     * 
     * @param int $limit Maximum number of events to return
     * @param bool $includeFailed Whether to include failed events
     * @return array Stock events
     */
    public function getAllEvents(int $limit = 100, bool $includeFailed = true): array
    {
        $tableName = $this->getTableName();
        $conditions = ['event_type IN ("stock_move", "stock_adjustment", "inventory_count")'];
        
        if (!$includeFailed) {
            $conditions[] = 'processed_successfully = TRUE';
        }
        
        $sql = "SELECT * FROM {$tableName} 
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY processed_at DESC 
                LIMIT {$limit}";

        $result = db_query($sql);
        $events = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $events[] = $row;
                }
            }
        }

        return $events;
    }

    /**
     * Gets failed stock events.
     * 
     * @param int $limit Maximum number of events to return
     * @return array Failed stock events
     */
    public function getFailedEvents(int $limit = 50): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE event_type IN ('stock_move', 'stock_adjustment', 'inventory_count')
                AND processed_successfully = FALSE
                ORDER BY processed_at DESC 
                LIMIT {$limit}";

        $result = db_query($sql);
        $events = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $events[] = $row;
                }
            }
        }

        return $events;
    }

    /**
     * Gets stock event statistics.
     * 
     * @return array Statistics array
     */
    public function getEventStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total events
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName} 
                    WHERE event_type IN ('stock_move', 'stock_adjustment', 'inventory_count')";
        $totalResult = db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Successful events
        $successSql = "SELECT COUNT(*) as success FROM {$tableName} 
                      WHERE event_type IN ('stock_move', 'stock_adjustment', 'inventory_count')
                      AND processed_successfully = TRUE";
        $successResult = db_query($successSql);
        $success = 0;
        if ($successResult !== false) {
            $row = db_fetch_assoc($successResult);
            $success = (int)($row['success'] ?? 0);
        }
        
        // Failed events
        $failed = $total - $success;
        
        // Events by type
        $typeSql = "SELECT event_type, COUNT(*) as count FROM {$tableName} 
                   WHERE event_type IN ('stock_move', 'stock_adjustment', 'inventory_count')
                   GROUP BY event_type ORDER BY count DESC";
        $typeResult = db_query($typeSql);
        $byType = [];
        if ($typeResult !== false) {
            while ($row = db_fetch_assoc($typeResult)) {
                if ($row !== false) {
                    $byType[$row['event_type']] = (int)$row['count'];
                }
            }
        }
        
        return [
            'total_events' => $total,
            'successful_events' => $success,
            'failed_events' => $failed,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0,
            'events_by_type' => $byType,
        ];
    }

    /**
     * Inserts a stock event.
     * 
     * @param array $data Event data
     * @return int Event ID
     */
    private function insertEvent(array $data): int
    {
        $tableName = $this->getTableName();
        
        $sql = "INSERT INTO {$tableName} (
            event_type, stock_id, location_id, to_location, quantity, quantity_change, 
            counted_quantity, expected_quantity, difference, reason, fa_move_id, 
            fa_adjustment_id, fa_count_id, event_data, processed_successfully, 
            error_message, processed_at
        ) VALUES (
            '{$data['event_type']}', 
            " . ($data['stock_id'] ?? 'NULL') . ", 
            " . ($data['location_id'] ?? 'NULL') . ", 
            " . ($data['to_location'] ?? 'NULL') . ", 
            " . ($data['quantity'] ?? 'NULL') . ", 
            " . ($data['quantity_change'] ?? 'NULL') . ", 
            " . ($data['counted_quantity'] ?? 'NULL') . ", 
            " . ($data['expected_quantity'] ?? 'NULL') . ", 
            " . ($data['difference'] ?? 'NULL') . ", 
            '" . db_escape($data['reason']) . "', 
            " . ($data['fa_move_id'] ?? 'NULL') . ", 
            " . ($data['fa_adjustment_id'] ?? 'NULL') . ", 
            " . ($data['fa_count_id'] ?? 'NULL') . ", 
            '" . db_escape($data['event_data']) . "', 
            " . ($data['processed_successfully'] ? 'TRUE' : 'FALSE') . ", 
            " . ($data['error_message'] ? "'" . db_escape($data['error_message']) . "'" : 'NULL') . ", 
            '{$data['processed_at']}'
        )";

        db_query($sql);
        return db_insert_id($tableName);
    }

    /**
     * Gets the table name.
     * 
     * @return string Table name
     */
    private function getTableName(): string
    {
        return $this->tablePrefix . 'square_stock_events';
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
                event_type VARCHAR(50) NOT NULL,
                stock_id VARCHAR(100) NOT NULL,
                location_id VARCHAR(100),
                to_location VARCHAR(100),
                quantity INT,
                quantity_change INT,
                counted_quantity INT,
                expected_quantity INT,
                difference INT,
                reason TEXT,
                fa_move_id INT,
                fa_adjustment_id INT,
                fa_count_id INT,
                event_data JSON,
                processed_successfully BOOLEAN DEFAULT FALSE,
                error_message TEXT,
                processed_at DATETIME,
                INDEX idx_event_type (event_type),
                INDEX idx_processed_at (processed_at),
                INDEX idx_success (processed_successfully),
                INDEX idx_stock_id (stock_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
        
        // Check for new columns and add if missing
        $this->ensureColumnsExist();
    }

    /**
     * Ensures table columns exist.
     */
    private function ensureColumnsExist(): void
    {
        $tableName = $this->getTableName();
        
        // Check if difference column exists
        $checkSql = "SHOW COLUMNS FROM {$tableName} LIKE 'difference'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Add difference column
            $alterSql = "ALTER TABLE {$tableName} ADD COLUMN difference INT";
            db_query($alterSql);
        }
        
        // Check if fa_count_id column exists
        $checkSql = "SHOW COLUMNS FROM {$tableName} LIKE 'fa_count_id'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Add fa_count_id column
            $alterSql = "ALTER TABLE {$tableName} ADD COLUMN fa_count_id INT";
            db_query($alterSql);
        }
        
        // Check if JSON event_data column exists
        $checkSql = "SHOW COLUMNS FROM {$tableName} LIKE 'event_data'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false && stripos($row['Type'], 'json') === false) {
                // Alter to JSON type if supported
                $alterSql = "ALTER TABLE {$tableName} MODIFY COLUMN event_data JSON";
                db_query($alterSql);
            }
        }
    }
}