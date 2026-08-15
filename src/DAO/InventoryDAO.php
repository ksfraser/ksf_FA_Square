<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Inventory DAO
 * 
 * Handles database operations for inventory items and stock levels.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.01 - Inventory Management
 */
class InventoryDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets inventory item by ID.
     * 
     * @param int $itemId Item ID
     * @return array|null Item data or null if not found
     */
    public function getItemById(int $itemId): ?array
    {
        $tableName = $this->getInventoryTableName();
        $sql = "SELECT * FROM {$tableName} WHERE item_id = {$itemId}";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets current stock level for an item.
     * 
     * @param int $itemId Item ID
     * @param string $locationId Location ID
     * @return float Current stock level
     */
    public function getStockLevel(int $itemId, string $locationId): float
    {
        $tableName = $this->getStockMovesTableName();
        $sql = "SELECT SUM(quantity) AS total_qty
                FROM {$tableName}
                WHERE item_id = {$itemId} AND location_id = '" . \db_escape($locationId) . "'";

        $result = \db_query($sql);
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            return (float)($row['total_qty'] ?? 0);
        }

        return 0.0;
    }

    /**
     * Gets inventory items.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory items
     */
    public function getInventoryItems(array $filters = []): array
    {
        $tableName = $this->getInventoryTableName();
        $where = [];

        if (!empty($filters['category_id'])) {
            $where[] = "category_id = " . (int)$filters['category_id'];
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM {$tableName}{$whereClause}";

        $result = \db_query($sql);
        $items = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * Gets inventory table name.
     * 
     * @return string Table name
     */
    private function getInventoryTableName(): string
    {
        return $this->tablePrefix . 'stock_master';
    }

    /**
     * Gets stock moves table name.
     * 
     * @return string Table name
     */
    private function getStockMovesTableName(): string
    {
        return $this->tablePrefix . 'stock_moves';
    }
}
