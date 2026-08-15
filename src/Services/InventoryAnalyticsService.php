<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Inventory Analytics Service
 * 
 * Handles inventory analytics and reporting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.03 - Inventory Analytics
 */
class InventoryAnalyticsService
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets inventory summary.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory summary
     */
    public function getInventorySummary(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Inventory summary
        $summarySql = "SELECT 
            COUNT(*) as total_items,
            SUM(qty_on_hand) as total_quantity,
            SUM(unit_cost * qty_on_hand) as total_value,
            SUM(CASE WHEN qty_on_hand = 0 THEN 1 END) as out_of_stock,
            SUM(CASE WHEN qty_on_hand < reorder_level THEN 1 END) as low_stock,
            AVG(qty_on_hand) as average_quantity,
            MIN(qty_on_hand) as min_quantity,
            MAX(qty_on_hand) as max_quantity
            FROM {$tableName} 
            WHERE {$dateCondition}";
        
        $result = \db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = [
                'total_items' => (int)($row['total_items'] ?? 0),
                'total_quantity' => (int)($row['total_quantity'] ?? 0),
                'total_value' => (float)($row['total_value'] ?? 0),
                'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
                'low_stock' => (int)($row['low_stock'] ?? 0),
                'average_quantity' => (float)($row['average_quantity'] ?? 0),
                'min_quantity' => (int)($row['min_quantity'] ?? 0),
                'max_quantity' => (int)($row['max_quantity'] ?? 0)
            ];
        }
        
        // Category distribution
        $categorySql = "SELECT 
            category,
            COUNT(*) as item_count,
            SUM(qty_on_hand) as total_quantity,
            SUM(unit_cost * qty_on_hand) as total_value
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY category
            ORDER BY total_value DESC";
        
        $result = \db_query($categorySql);
        $byCategory = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $byCategory[] = [
                        'category' => $row['category'],
                        'item_count' => (int)$row['item_count'],
                        'total_quantity' => (int)$row['total_quantity'],
                        'total_value' => (float)$row['total_value']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'by_category' => $byCategory
        ];
    }

    /**
     * Gets stock turnover.
     * 
     * @param array $filters Filter parameters
     * @return array Stock turnover
     */
    public function getStockTurnover(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Stock turnover by category
        $turnoverSql = "SELECT 
            i.category,
            i.item_code,
            i.qty_on_hand,
            i.unit_cost,
            i.reorder_level,
            COALESCE(s.total_sold, 0) as total_sold,
            CASE 
                WHEN i.qty_on_hand = 0 THEN NULL
                ELSE COALESCE(s.total_sold, 0) / i.qty_on_hand
            END as turnover_ratio,
            CASE 
                WHEN i.qty_on_hand = 0 THEN NULL
                ELSE (i.qty_on_hand / COALESCE(s.total_sold, 1)) * 30
            END as days_of_inventory
            FROM {$tableName} i
            LEFT JOIN (
                SELECT 
                    item_code,
                    SUM(quantity) as total_sold
                    FROM {$salesTable} 
                    WHERE {$dateCondition}
                    GROUP BY item_code
            ) s ON i.item_code = s.item_code
            WHERE {$dateCondition}
            ORDER BY turnover_ratio DESC NULLS LAST";
        
        $result = \db_query($turnoverSql);
        $turnover = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $turnover[] = [
                        'category' => $row['category'],
                        'item_code' => $row['item_code'],
                        'qty_on_hand' => (int)$row['qty_on_hand'],
                        'unit_cost' => (float)$row['unit_cost'],
                        'reorder_level' => (int)$row['reorder_level'],
                        'total_sold' => (int)$row['total_sold'],
                        'turnover_ratio' => (float)$row['turnover_ratio'],
                        'days_of_inventory' => (float)$row['days_of_inventory']
                    ];
                }
            }
        }
        
        return $turnover;
    }

    /**
     * Gets inventory accuracy.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory accuracy
     */
    public function getInventoryAccuracy(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Cycle count accuracy
        $cycleCountSql = "SELECT 
            COUNT(*) as total_counts,
            SUM(CASE WHEN variance = 0 THEN 1 END) as perfect_counts,
            AVG(ABS(variance)) as average_variance,
            SUM(ABS(variance)) as total_variance,
            MIN(ABS(variance)) as min_variance,
            MAX(ABS(variance)) as max_variance
            FROM {$tableName} 
            WHERE {$dateCondition} AND variance IS NOT NULL";
        
        $result = \db_query($cycleCountSql);
        $accuracy = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $accuracy = [
                'total_counts' => (int)($row['total_counts'] ?? 0),
                'perfect_counts' => (int)($row['perfect_counts'] ?? 0),
                'average_variance' => (float)($row['average_variance'] ?? 0),
                'total_variance' => (float)($row['total_variance'] ?? 0),
                'min_variance' => (float)($row['min_variance'] ?? 0),
                'max_variance' => (float)($row['max_variance'] ?? 0),
                'accuracy_percentage' => $this->calculatePercentage(
                    (int)($row['perfect_counts'] ?? 0),
                    (int)($row['total_counts'] ?? 0)
                )
            ];
        }
        
        return $accuracy;
    }

    /**
     * Gets slow-moving items.
     * 
     * @param array $filters Filter parameters
     * @return array Slow-moving items
     */
    public function getSlowMovingItems(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Slow-moving items analysis
        $slowSql = "SELECT 
            i.category,
            i.item_code,
            i.item_description,
            i.qty_on_hand,
            i.reorder_level,
            COALESCE(s.last_sold_date, 'Never') as last_sold_date,
            COALESCE(s.days_since_last_sold, 9999) as days_since_last_sold,
            COALESCE(s.total_sold, 0) as total_sold,
            CASE 
                WHEN i.qty_on_hand = 0 THEN 'Out of Stock'
                WHEN COALESCE(s.days_since_last_sold, 9999) > 180 THEN 'Very Slow'
                WHEN COALESCE(s.days_since_last_sold, 9999) > 90 THEN 'Slow'
                WHEN COALESCE(s.days_since_last_sold, 9999) > 30 THEN 'Normal'
                ELSE 'Fast'
            END as movement_status
            FROM {$tableName} i
            LEFT JOIN (
                SELECT 
                    item_code,
                    MAX(date_1) as last_sold_date,
                    DATEDIFF(NOW(), MAX(date_1)) as days_since_last_sold,
                    SUM(quantity) as total_sold
                    FROM {$salesTable} 
                    WHERE {$dateCondition}
                    GROUP BY item_code
            ) s ON i.item_code = s.item_code
            WHERE COALESCE(s.days_since_last_sold, 9999) > 30
            ORDER BY days_since_last_sold DESC
            LIMIT 50";
        
        $result = \db_query($slowSql);
        $slowItems = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $slowItems[] = [
                        'category' => $row['category'],
                        'item_code' => $row['item_code'],
                        'item_description' => $row['item_description'],
                        'qty_on_hand' => (int)$row['qty_on_hand'],
                        'reorder_level' => (int)$row['reorder_level'],
                        'last_sold_date' => $row['last_sold_date'],
                        'days_since_last_sold' => (int)$row['days_since_last_sold'],
                        'total_sold' => (int)$row['total_sold'],
                        'movement_status' => $row['movement_status']
                    ];
                }
            }
        }
        
        return $slowItems;
    }

    /**
     * Gets stock alerts.
     * 
     * @param array $filters Filter parameters
     * @return array Stock alerts
     */
    public function getStockAlerts(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Stock alerts by severity
        $alertsSql = "SELECT 
            CASE 
                WHEN qty_on_hand = 0 THEN 'Critical'
                WHEN qty_on_hand <= reorder_level * 0.5 THEN 'High'
                WHEN qty_on_hand <= reorder_level THEN 'Medium'
                ELSE 'Low'
            END as alert_severity,
            COUNT(*) as alert_count,
            SUM(qty_on_hand) as total_quantity,
            SUM(unit_cost * qty_on_hand) as total_value
            FROM {$tableName} 
            WHERE {$dateCondition} AND qty_on_hand <= reorder_level
            GROUP BY alert_severity
            ORDER BY alert_severity";
        
        $result = \db_query($alertsSql);
        $alerts = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $alerts[] = [
                        'severity' => $row['alert_severity'],
                        'count' => (int)$row['alert_count'],
                        'total_quantity' => (int)$row['total_quantity'],
                        'total_value' => (float)$row['total_value']
                    ];
                }
            }
        }
        
        // Specific items needing attention
        $specificAlertsSql = "SELECT 
            item_code,
            item_description,
            qty_on_hand,
            reorder_level,
            reorder_level - qty_on_hand as reorder_quantity,
            reorder_level * unit_cost as reorder_value,
            CASE 
                WHEN qty_on_hand = 0 THEN 'Critical - Out of Stock'
                WHEN qty_on_hand <= reorder_level * 0.5 THEN 'High - Very Low'
                ELSE 'Medium - Low Stock'
            END as alert_message
            FROM {$tableName} 
            WHERE {$dateCondition} AND qty_on_hand <= reorder_level
            ORDER BY alert_severity, reorder_value DESC
            LIMIT 20";
        
        $result = \db_query($specificAlertsSql);
        $specificAlerts = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $specificAlerts[] = [
                        'item_code' => $row['item_code'],
                        'item_description' => $row['item_description'],
                        'qty_on_hand' => (int)$row['qty_on_hand'],
                        'reorder_level' => (int)$row['reorder_level'],
                        'reorder_quantity' => (int)$row['reorder_quantity'],
                        'reorder_value' => (float)$row['reorder_value'],
                        'alert_message' => $row['alert_message']
                    ];
                }
            }
        }
        
        return [
            'by_severity' => $alerts,
            'specific_items' => $specificAlerts
        ];
    }

    /**
     * Gets efficiency metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Efficiency metrics
     */
    public function getEfficiencyMetrics(array $filters): array
    {
        $tableName = $this->getInventoryTableName();
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Inventory turnover efficiency
        $turnoverSql = "SELECT 
            AVG(CASE WHEN qty_on_hand = 0 THEN NULL ELSE 
                (COALESCE(s.total_sold, 0) / qty_on_hand) END) as average_turnover,
            COUNT(*) as total_items,
            SUM(CASE WHEN qty_on_hand > 0 THEN 1 ELSE 0 END) as items_with_stock,
            SUM(CASE WHEN qty_on_hand > 0 AND COALESCE(s.total_sold, 0) > 0 THEN 1 ELSE 0 END) as items_with_sales,
            AVG(CASE WHEN qty_on_hand > 0 THEN 
                (COALESCE(s.total_sold, 0) / qty_on_hand) ELSE NULL END) as turnover_ratio
            FROM {$tableName} i
            LEFT JOIN (
                SELECT 
                    item_code,
                    SUM(quantity) as total_sold
                    FROM {$salesTable} 
                    WHERE {$dateCondition}
                    GROUP BY item_code
            ) s ON i.item_code = s.item_code";
        
        $result = \db_query($turnoverSql);
        $efficiency = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $efficiency = [
                'average_turnover' => (float)($row['average_turnover'] ?? 0),
                'total_items' => (int)($row['total_items'] ?? 0),
                'items_with_stock' => (int)($row['items_with_stock'] ?? 0),
                'items_with_sales' => (int)($row['items_with_sales'] ?? 0),
                'turnover_ratio' => (float)($row['turnover_ratio'] ?? 0),
                'stock_utilization' => $this->calculatePercentage(
                    (int)($row['items_with_sales'] ?? 0),
                    (int)($row['items_with_stock'] ?? 0)
                )
            ];
        }
        
        // Days of inventory by category
        $daysSql = "SELECT 
            category,
            AVG(CASE 
                WHEN qty_on_hand = 0 THEN NULL
                ELSE (qty_on_hand / COALESCE(s.total_sold, 1)) * 30
            END) as average_days_inventory,
            COUNT(*) as item_count
            FROM {$tableName} i
            LEFT JOIN (
                SELECT 
                    item_code,
                    SUM(quantity) as total_sold
                    FROM {$salesTable} 
                    WHERE {$dateCondition}
                    GROUP BY item_code
            ) s ON i.item_code = s.item_code
            WHERE qty_on_hand > 0
            GROUP BY category
            ORDER BY average_days_inventory DESC";
        
        $result = \db_query($daysSql);
        $byCategory = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $byCategory[] = [
                        'category' => $row['category'],
                        'average_days_inventory' => (float)$row['average_days_inventory'],
                        'item_count' => (int)$row['item_count']
                    ];
                }
            }
        }
        
        return [
            'overall_efficiency' => $efficiency,
            'by_category' => $byCategory
        ];
    }

    /**
     * Builds date condition for queries.
     * 
     * @param array $filters Filter parameters
     * @return string Date condition
     */
    private function buildDateCondition(array $filters): string
    {
        $conditions = ["1=1"];
        
        if (isset($filters['start_date'])) {
            $conditions[] = "created_at >= '{$filters['start_date']}'";
        }
        
        if (isset($filters['end_date'])) {
            $conditions[] = "created_at <= '{$filters['end_date']}'";
        }
        
        if (isset($filters['location_id'])) {
            $conditions[] = "location_id = '{$filters['location_id']}'";
        }
        
        return implode(' AND ', $conditions);
    }

    /**
     * Calculates percentage.
     * 
     * @param int $value Value
     * @param int $total Total
     * @return float Percentage
     */
    private function calculatePercentage(int $value, int $total): float
    {
        if ($total == 0) return 0;
        return ($value / $total) * 100;
    }

    /**
     * Gets inventory table name.
     * 
     * @return string Table name
     */
    private function getInventoryTableName(): string
    {
        return $this->tablePrefix . 'inventory';
    }

    /**
     * Gets sales table name.
     * 
     * @return string Table name
     */
    private function getSalesTableName(): string
    {
        return $this->tablePrefix . 'sales';
    }
}