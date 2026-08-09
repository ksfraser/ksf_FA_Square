<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Customer Analytics Service
 * 
 * Handles customer analytics and reporting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.02 - Customer Analytics
 */
class CustomerAnalyticsService
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets customer summary.
     * 
     * @param array $filters Filter parameters
     * @return array Customer summary
     */
    public function getCustomerSummary(array $filters): array
    {
        $tableName = $this->getCustomerTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Total customers
        $totalSql = "SELECT 
            COUNT(*) as total_customers,
            COUNT(DISTINCT person_id) as unique_individuals,
            COUNT(DISTINCT company_id) as unique_companies,
            COUNT(DISTINCT location_id) as unique_locations
            FROM {$tableName} 
            WHERE {$dateCondition}";
        
        $result = \db_query($totalSql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = [
                'total_customers' => (int)($row['total_customers'] ?? 0),
                'unique_individuals' => (int)($row['unique_individuals'] ?? 0),
                'unique_companies' => (int)($row['unique_companies'] ?? 0),
                'unique_locations' => (int)($row['unique_locations'] ?? 0)
            ];
        }
        
        return $summary;
    }

    /**
     * Gets customer lifetime value.
     * 
     * @param array $filters Filter parameters
     * @return array Customer lifetime value
     */
    public function getCustomerLifetimeValue(array $filters): array
    {
        $tableName = $this->getCustomerTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        $sql = "SELECT 
            c.debtor_no,
            c.name,
            COUNT(DISTINCT s.payment_id) as total_transactions,
            SUM(s.amount) as total_spent,
            AVG(s.amount) as average_transaction,
            MIN(s.date_1) as first_purchase,
            MAX(s.date_1) as last_purchase,
            DATEDIFF(MAX(s.date_1), MIN(s.date_1)) as days_as_customer,
            SUM(s.amount) / COUNT(DISTINCT s.date_1) as daily_spend,
            COUNT(DISTINCT s.date_1) as days_purchased
            FROM {$tableName} c
            JOIN {$this->getSalesTableName()} s ON c.debtor_no = s.debtor_no
            WHERE {$dateCondition}
            GROUP BY c.debtor_no, c.name
            ORDER BY total_spent DESC
            LIMIT 20";
        
        $result = \db_query($sql);
        $customers = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $customers[] = [
                        'debtor_no' => (int)$row['debtor_no'],
                        'name' => $row['name'],
                        'total_transactions' => (int)$row['total_transactions'],
                        'total_spent' => (float)$row['total_spent'],
                        'average_transaction' => (float)$row['average_transaction'],
                        'first_purchase' => $row['first_purchase'],
                        'last_purchase' => $row['last_purchase'],
                        'days_as_customer' => (int)$row['days_as_customer'],
                        'daily_spend' => (float)$row['daily_spend'],
                        'days_purchased' => (int)$row['days_purchased'],
                        'ltv_score' => $this->calculateLTVScore(
                            (float)$row['total_spent'], 
                            (int)$row['days_as_customer'],
                            (int)$row['total_transactions']
                        )
                    ];
                }
            }
        }
        
        return $customers;
    }

    /**
     * Gets customer segments.
     * 
     * @param array $filters Filter parameters
     * @return array Customer segments
     */
    public function getCustomerSegments(array $filters): array
    {
        $customers = $this->getCustomerLifetimeValue($filters);
        
        // Segment customers by LTV score
        $segments = [
            'high_value' => [],
            'medium_value' => [],
            'low_value' => [],
            'new_customers' => []
        ];
        
        foreach ($customers as $customer) {
            if ($customer['total_transactions'] < 3) {
                $segments['new_customers'][] = $customer;
            } elseif ($customer['ltv_score'] >= 80) {
                $segments['high_value'][] = $customer;
            } elseif ($customer['ltv_score'] >= 50) {
                $segments['medium_value'][] = $customer;
            } else {
                $segments['low_value'][] = $customer;
            }
        }
        
        // Calculate segment statistics
        $segmentStats = [];
        foreach ($segments as $segmentName => $segmentCustomers) {
            $count = count($segmentCustomers);
            $totalSpent = array_sum(array_column($segmentCustomers, 'total_spent'));
            
            $segmentStats[$segmentName] = [
                'count' => $count,
                'percentage' => $count > 0 ? ($count / count($customers)) * 100 : 0,
                'total_spent' => $totalSpent,
                'average_spent' => $count > 0 ? $totalSpent / $count : 0,
                'top_customers' => array_slice($segmentCustomers, 0, 5)
            ];
        }
        
        return $segmentStats;
    }

    /**
     * Gets customer acquisition.
     * 
     * @param array $filters Filter parameters
     * @return array Customer acquisition
     */
    public function getCustomerAcquisition(array $filters): array
    {
        $tableName = $this->getCustomerTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Monthly acquisition
        $monthlySql = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as new_customers,
            COUNT(DISTINCT location_id) as locations
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC";
        
        $result = \db_query($monthlySql);
        $acquisition = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $acquisition[] = [
                        'month' => $row['month'],
                        'new_customers' => (int)$row['new_customers'],
                        'locations' => (int)$row['locations']
                    ];
                }
            }
        }
        
        // Acquisition by source
        $sourceSql = "SELECT 
            source,
            COUNT(*) as new_customers
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY source
            ORDER BY new_customers DESC";
        
        $result = \db_query($sourceSql);
        $bySource = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $bySource[] = [
                        'source' => $row['source'],
                        'new_customers' => (int)$row['new_customers']
                    ];
                }
            }
        }
        
        return [
            'monthly_acquisition' => $acquisition,
            'by_source' => $bySource
        ];
    }

    /**
     * Gets customer retention.
     * 
     * @param array $filters Filter parameters
     * @return array Customer retention
     */
    public function getCustomerRetention(array $filters): array
    {
        $tableName = $this->getCustomerTableName();
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Retention by cohort
        $cohortSql = "SELECT 
            DATE_FORMAT(c.created_at, '%Y-%m') as cohort,
            COUNT(*) as total_customers,
            COUNT(DISTINCT CASE WHEN s.date_1 >= DATE_ADD(c.created_at, INTERVAL 30 DAY) THEN c.debtor_no END) as retained_30_days,
            COUNT(DISTINCT CASE WHEN s.date_1 >= DATE_ADD(c.created_at, INTERVAL 90 DAY) THEN c.debtor_no END) as retained_90_days,
            COUNT(DISTINCT CASE WHEN s.date_1 >= DATE_ADD(c.created_at, INTERVAL 180 DAY) THEN c.debtor_no END) as retained_180_days
            FROM {$tableName} c
            LEFT JOIN {$salesTable} s ON c.debtor_no = s.debtor_no
            WHERE {$dateCondition}
            GROUP BY DATE_FORMAT(c.created_at, '%Y-%m')
            ORDER BY cohort ASC";
        
        $result = \db_query($cohortSql);
        $retention = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $retention[] = [
                        'cohort' => $row['cohort'],
                        'total_customers' => (int)$row['total_customers'],
                        'retained_30_days' => (int)$row['retained_30_days'],
                        'retained_90_days' => (int)$row['retained_90_days'],
                        'retained_180_days' => (int)$row['retained_180_days'],
                        'retention_30_days' => $this->calculatePercentage(
                            (int)$row['retained_30_days'], 
                            (int)$row['total_customers']
                        ),
                        'retention_90_days' => $this->calculatePercentage(
                            (int)$row['retained_90_days'], 
                            (int)$row['total_customers']
                        ),
                        'retention_180_days' => $this->calculatePercentage(
                            (int)$row['retained_180_days'], 
                            (int)$row['total_customers']
                        )
                    ];
                }
            }
        }
        
        return $retention;
    }

    /**
     * Gets satisfaction metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Satisfaction metrics
     */
    public function getSatisfactionMetrics(array $filters): array
    {
        $tableName = $this->getCustomerFeedbackTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Overall satisfaction
        $overallSql = "SELECT 
            AVG(rating) as average_rating,
            COUNT(*) as total_responses,
            COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive_responses,
            COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative_responses
            FROM {$tableName} 
            WHERE {$dateCondition}";
        
        $result = \db_query($overallSql);
        $overall = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $overall = [
                'average_rating' => (float)($row['average_rating'] ?? 0),
                'total_responses' => (int)($row['total_responses'] ?? 0),
                'positive_responses' => (int)($row['positive_responses'] ?? 0),
                'negative_responses' => (int)($row['negative_responses'] ?? 0),
                'positive_percentage' => $this->calculatePercentage(
                    (int)($row['positive_responses'] ?? 0),
                    (int)($row['total_responses'] ?? 0)
                )
            ];
        }
        
        // Satisfaction by category
        $categorySql = "SELECT 
            category,
            AVG(rating) as average_rating,
            COUNT(*) as responses
            FROM {$tableName} 
            WHERE {$dateCondition}
            GROUP BY category
            ORDER BY average_rating DESC";
        
        $result = \db_query($categorySql);
        $byCategory = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $byCategory[] = [
                        'category' => $row['category'],
                        'average_rating' => (float)$row['average_rating'],
                        'responses' => (int)$row['responses']
                    ];
                }
            }
        }
        
        return [
            'overall' => $overall,
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
     * Calculates LTV score.
     * 
     * @param float $totalSpent Total spent
     * @param int $daysAsCustomer Days as customer
     * @param int $totalTransactions Total transactions
     * @return int LTV score (0-100)
     */
    private function calculateLTVScore(float $totalSpent, int $daysAsCustomer, int $totalTransactions): int
    {
        if ($daysAsCustomer == 0) return 0;
        
        // Calculate LTV based on spending frequency and amount
        $dailySpend = $totalSpent / $daysAsCustomer;
        $transactionFrequency = $totalTransactions / $daysAsCustomer;
        
        // Score calculation (0-100)
        $score = min(100, ($dailySpend * 10) + ($transactionFrequency * 50));
        
        return (int)$score;
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
     * Gets customer table name.
     * 
     * @return string Table name
     */
    private function getCustomerTableName(): string
    {
        return $this->tablePrefix . 'customers';
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
     * Gets customer feedback table name.
     * 
     * @return string Table name
     */
    private function getCustomerFeedbackTableName(): string
    {
        return $this->tablePrefix . 'customer_feedback';
    }
}