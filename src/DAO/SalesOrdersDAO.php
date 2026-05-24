<?php
declare(strict_types=1);

/**
 * Sales Orders DAO
 * 
 * Handles database operations for sales orders and order items.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-02.01 - Order Synchronization, FR-02.02 - Order Status Tracking
 */
class SalesOrdersDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets sales order by order ID.
     * 
     * @param int $orderId Order ID
     * @return array|null Order data or null if not found
     */
    public function getOrder(int $orderId): ?array
    {
        $tableName = $this->getOrdersTableName();
        $sql = "SELECT * FROM {$tableName} WHERE order_id = {$orderId}";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets sales order by Square order ID.
     * 
     * @param string $squareOrderId Square order ID
     * @return array|null Order data or null if not found
     */
    public function getBySquareId(string $squareOrderId): ?array
    {
        $tableName = $this->getOrdersTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_order_id = '" . db_escape($squareOrderId) . "'";
        
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Inserts new sales order.
     * 
     * @param array $orderData Order data
     * @return int Order ID
     */
    public function insertOrder(array $orderData): int
    {
        $tableName = $this->getOrdersTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($orderData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = "'" . db_escape($value) . "'";
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        db_query($sql);
        return db_insert_id($tableName);
    }

    /**
     * Updates sales order.
     * 
     * @param int $orderId Order ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateOrder(int $orderId, array $data): bool
    {
        $tableName = $this->getOrdersTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE order_id = {$orderId}";
        
        return db_query($sql) !== false;
    }

    /**
     * Gets order items for an order.
     * 
     * @param int $orderId Order ID
     * @return array Order items
     */
    public function getOrderItems(int $orderId): array
    {
        $tableName = $this->getOrderItemsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE order_id = {$orderId} ORDER BY sequence";

        $result = db_query($sql);
        $items = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $items[] = $row;
                }
            }
        }

        return $items;
    }

    /**
     * Inserts order item.
     * 
     * @param array $itemData Item data
     * @return int Item ID
     */
    public function insertOrderItem(array $itemData): int
    {
        $tableName = $this->getOrderItemsTableName();
        
        $sql = "INSERT INTO {$tableName} (
            order_id, item_code, description, quantity, unit_price, 
            line_total, tax_amount, discount_amount, notes, sequence
        ) VALUES (
            {$itemData['order_id']},
            '" . db_escape($itemData['item_code']) . "',
            '" . db_escape($itemData['description']) . "',
            {$itemData['quantity']},
            " . (float)$itemData['unit_price'] . ",
            " . (float)$itemData['line_total'] . ",
            " . (float)$itemData['tax_amount'] . ",
            " . (float)$itemData['discount_amount'] . ",
            '" . db_escape($itemData['notes']) . "',
            {$itemData['sequence']}
        )";

        db_query($sql);
        return db_insert_id($tableName);
    }

    /**
     * Updates mapping by Square order ID.
     * 
     * @param string $squareOrderId Square order ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMappingBySquareId(string $squareOrderId, array $data): bool
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
                WHERE square_order_id = '" . db_escape($squareOrderId) . "'";
        
        return db_query($sql) !== false;
    }

    /**
     * Updates mapping by credit note ID.
     * 
     * @param int $creditNoteId Credit note ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateMappingByCreditNoteId(int $creditNoteId, array $data): bool
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
                WHERE credit_note_id = {$creditNoteId}";
        
        return db_query($sql) !== false;
    }

    /**
     * Logs order event.
     * 
     * @param array $eventData Event data
     * @return int Event ID
     */
    public function logOrderEvent(array $eventData): int
    {
        $tableName = $this->getEventLogTableName();
        
        $sql = "INSERT INTO {$tableName} (
            fa_order_id, original_order_id, square_order_id, event_type, 
            event_data, timestamp
        ) VALUES (
            {$eventData['fa_order_id']},
            " . (isset($eventData['original_order_id']) ? (int)$eventData['original_order_id'] : 'NULL') . ",
            " . (isset($eventData['square_order_id']) ? "'" . db_escape($eventData['square_order_id']) . "'" : 'NULL') . ",
            '{$eventData['event_type']}',
            '" . db_escape($eventData['event_data']) . "',
            '{$eventData['timestamp']}'
        )";

        db_query($sql);
        return db_insert_id($tableName);
    }

    /**
     * Gets order statistics.
     * 
     * @return array Statistics array
     */
    public function getOrderStatistics(): array
    {
        // Total orders
        $totalSql = "SELECT COUNT(*) as total FROM {$this->getOrdersTableName()}";
        $totalResult = db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Orders by type
        $typeSql = "SELECT type, COUNT(*) as count FROM {$this->getOrdersTableName()} 
                   GROUP BY type ORDER BY count DESC";
        $typeResult = db_query($typeSql);
        $byType = [];
        if ($typeResult !== false) {
            while ($row = db_fetch_assoc($typeResult)) {
                if ($row !== false) {
                    $byType[$row['type']] = (int)$row['count'];
                }
            }
        }
        
        // Recent orders
        $recentSql = "SELECT COUNT(*) as recent FROM {$this->getOrdersTableName()} 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $recentResult = db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        // Event log statistics
        $eventSql = "SELECT event_type, COUNT(*) as count FROM {$this->getEventLogTableName()} 
                    GROUP BY event_type ORDER BY count DESC";
        $eventResult = db_query($eventSql);
        $byEventType = [];
        if ($eventResult !== false) {
            while ($row = db_fetch_assoc($eventResult)) {
                if ($row !== false) {
                    $byEventType[$row['event_type']] = (int)$row['count'];
                }
            }
        }
        
        return [
            'total_orders' => $total,
            'by_type' => $byType,
            'recent_orders' => $recent,
            'event_statistics' => $byEventType,
        ];
    }

    /**
     * Ensures all tables exist.
     */
    public function ensureTablesExist(): void
    {
        $this->ensureOrdersTableExists();
        $this->ensureOrderItemsTableExists();
        $this->ensureMappingsTableExists();
        $this->ensureEventLogTableExists();
    }

    /**
     * Ensures orders table exists.
     */
    private function ensureOrdersTableExists(): void
    {
        $tableName = $this->getOrdersTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                order_id INT AUTO_INCREMENT PRIMARY KEY,
                debtor_no INT NOT NULL,
                type INT NOT NULL,
                order_date DATE NOT NULL,
                due_date DATE,
                order_ref VARCHAR(50) UNIQUE,
                reference TEXT,
                tax_included BOOLEAN DEFAULT FALSE,
                total DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_debtor_no (debtor_no),
                INDEX idx_type (type),
                INDEX idx_order_date (order_date),
                INDEX idx_order_ref (order_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Ensures order items table exists.
     */
    private function ensureOrderItemsTableExists(): void
    {
        $tableName = $this->getOrderItemsTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                item_id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                item_code VARCHAR(50) NOT NULL,
                description TEXT,
                quantity DECIMAL(15,3) NOT NULL,
                unit_price DECIMAL(15,2) NOT NULL,
                line_total DECIMAL(15,2) NOT NULL,
                tax_amount DECIMAL(15,2) DEFAULT 0,
                discount_amount DECIMAL(15,2) DEFAULT 0,
                notes TEXT,
                sequence INT DEFAULT 0,
                FOREIGN KEY (order_id) REFERENCES {$this->getOrdersTableName()}(order_id),
                INDEX idx_order_id (order_id),
                INDEX idx_item_code (item_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Ensures mappings table exists.
     */
    private function ensureMappingsTableExists(): void
    {
        $tableName = $this->getMappingsTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_order_id INT,
                credit_note_id INT,
                square_order_id VARCHAR(100),
                original_order_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_order_mapping (fa_order_id, square_order_id),
                INDEX idx_square_order_id (square_order_id),
                INDEX idx_original_order_id (original_order_id),
                INDEX idx_credit_note_id (credit_note_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Ensures event log table exists.
     */
    private function ensureEventLogTableExists(): void
    {
        $tableName = $this->getEventLogTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = db_query($checkSql);
        
        if ($result !== false && db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                fa_order_id INT,
                original_order_id INT,
                square_order_id VARCHAR(100),
                event_type VARCHAR(50) NOT NULL,
                event_data JSON,
                timestamp DATETIME NOT NULL,
                INDEX idx_fa_order_id (fa_order_id),
                INDEX idx_original_order_id (original_order_id),
                INDEX idx_square_order_id (square_order_id),
                INDEX idx_event_type (event_type),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            db_query($createSql);
        }
    }

    /**
     * Gets orders table name.
     * 
     * @return string Table name
     */
    private function getOrdersTableName(): string
    {
        return $this->tablePrefix . 'square_sales_orders';
    }

    /**
     * Gets order items table name.
     * 
     * @return string Table name
     */
    private function getOrderItemsTableName(): string
    {
        return $this->tablePrefix . 'square_sales_order_items';
    }

    /**
     * Gets mappings table name.
     * 
     * @return string Table name
     */
    private function getMappingsTableName(): string
    {
        return $this->tablePrefix . 'square_order_mappings';
    }

    /**
     * Gets event log table name.
     * 
     * @return string Table name
     */
    private function getEventLogTableName(): string
    {
        return $this->tablePrefix . 'square_order_events';
    }
}