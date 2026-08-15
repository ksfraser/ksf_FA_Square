<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Sales Analytics Service
 * 
 * Handles sales analytics and reporting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.01 - Sales Analytics
 */
class SalesAnalyticsService
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets sales summary.
     * 
     * @param array $filters Filter parameters
     * @return array Sales summary
     */
    public function getSalesSummary(array $filters): array
    {
        $tableName = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Total sales
        $totalSql = "SELECT 
            COUNT(*) as total_transactions,
            SUM(amount) as total_amount,
            AVG(amount) as average_transaction,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount
            FROM {$tableName} 
            WHERE {$dateCondition}";
        
        $result = \db_query($totalSql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = [
                'total_transactions' => (int)($row['total_transactions'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'average_transaction' => (float)($row['average_transaction'] ?? 0),
                'min_amount' => (float)($row['min_amount'] ?? 0),
                'max_amount' => (float)($row['max_amount'] ?? 0)
            ];
        }
        
        return $summary;
    }

    /**
     * Gets sales trends.
     * 
     * @param array $filters Filter parameters
     * @return array Sales trends
     */
    public function getSalesTrends(array $filters): array
    {
        $tableName = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Daily trends
        $dailySql = "SELECT 
            DATE(date_1) as date,
            COUNT(*) as transactions,
            SUM(amount) as total_amount,
            COUNT(DISTINCT debtor_no) as unique_customers
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY DATE(date_1)
            ORDER BY date ASC
            LIMIT 30";
        
        $result = \db_query($dailySql);
        $trends = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $trends[] = [
                        'date' => $row['date'],
                        'transactions' => (int)$row['transactions'],
                        'total_amount' => (float)$row['total_amount'],
                        'unique_customers' => (int)$row['unique_customers']
                    ];
                }
            }
        }
        
        return $trends;
    }

    /**
     * Gets top products.
     * 
     * @param array $filters Filter parameters
     * @return array Top products
     */
    public function getTopProducts(array $filters): array
    {
        $tableName = $this->getSalesDetailsTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        $sql = "SELECT 
            item_code,
            COUNT(*) as quantity_sold,
            SUM(line_total) as total_revenue,
            AVG(unit_price) as average_price
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY item_code
            ORDER BY total_revenue DESC
            LIMIT 10";
        
        $result = \db_query($sql);
        $products = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $products[] = [
                        'item_code' => $row['item_code'],
                        'quantity_sold' => (int)$row['quantity_sold'],
                        'total_revenue' => (float)$row['total_revenue'],
                        'average_price' => (float)$row['average_price']
                    ];
                }
            }
        }
        
        return $products;
    }

    /**
     * Gets location performance.
     * 
     * @param array $filters Filter parameters
     * @return array Location performance
     */
    public function getLocationPerformance(array $filters): array
    {
        $tableName = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        $sql = "SELECT 
            location_id,
            COUNT(*) as transactions,
            SUM(amount) as total_amount,
            AVG(amount) as average_transaction,
            COUNT(DISTINCT debtor_no) as unique_customers
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY location_id
            ORDER BY total_amount DESC";
        
        $result = \db_query($sql);
        $locations = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $locations[] = [
                        'location_id' => $row['location_id'],
                        'transactions' => (int)$row['transactions'],
                        'total_amount' => (float)$row['total_amount'],
                        'average_transaction' => (float)$row['average_transaction'],
                        'unique_customers' => (int)$row['unique_customers']
                    ];
                }
            }
        }
        
        return $locations;
    }

    /**
     * Gets payment method distribution.
     * 
     * @param array $filters Filter parameters
     * @return array Payment method distribution
     */
    public function getPaymentMethodDistribution(array $filters): array
    {
        $tableName = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        $sql = "SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY payment_method
            ORDER BY count DESC";
        
        $result = \db_query($sql);
        $distribution = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $distribution[] = [
                        'payment_method' => $row['payment_method'],
                        'count' => (int)$row['count'],
                        'total_amount' => (float)$row['total_amount'],
                        'average_amount' => (float)$row['average_amount'],
                        'percentage' => $this->calculatePercentage($row['count'], $row['total_amount'])
                    ];
                }
            }
        }
        
        return $distribution;
    }

    /**
     * Gets performance metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Performance metrics
     */
    public function getPerformanceMetrics(array $filters): array
    {
        $tableName = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Peak hours
        $peakHoursSql = "SELECT 
            HOUR(created_at) as hour,
            COUNT(*) as transactions,
            SUM(amount) as total_amount
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY HOUR(created_at)
            ORDER BY transactions DESC";
        
        $result = \db_query($peakHoursSql);
        $peakHours = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $peakHours[] = [
                        'hour' => $row['hour'],
                        'transactions' => (int)$row['transactions'],
                        'total_amount' => (float)$row['total_amount']
                    ];
                }
            }
        }
        
        // Day of week analysis
        $dayOfWeekSql = "SELECT 
            DAYOFWEEK(date_1) as day_of_week,
            COUNT(*) as transactions,
            SUM(amount) as total_amount
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY DAYOFWEEK(date_1)
            ORDER BY day_of_week";
        
        $result = \db_query($dayOfWeekSql);
        $dayOfWeek = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $dayOfWeek[] = [
                        'day_of_week' => $row['day_of_week'],
                        'day_name' => $this->getDayName($row['day_of_week']),
                        'transactions' => (int)$row['transactions'],
                        'total_amount' => (float)$row['total_amount']
                    ];
                }
            }
        }
        
        return [
            'peak_hours' => $peakHours,
            'day_of_week' => $dayOfWeek
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
            $conditions[] = "date_1 >= '{$filters['start_date']}'";
        }
        
        if (isset($filters['end_date'])) {
            $conditions[] = "date_1 <= '{$filters['end_date']}'";
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
     * @param float $total Total
     * @return float Percentage
     */
    private function calculatePercentage(int $value, float $total): float
    {
        if ($total == 0) return 0;
        return ($value / $total) * 100;
    }

    /**
     * Gets day name from day of week.
     * 
     * @param int $dayOfWeek Day of week
     * @return string Day name
     */
    private function getDayName(int $dayOfWeek): string
    {
        $days = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $days[$dayOfWeek] ?? 'Unknown';
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

    /**
     * Gets sales details table name.
     * 
     * @return string Table name
     */
    private function getSalesDetailsTableName(): string
    {
        return $this->tablePrefix . 'sales_details';
    }
}