<?php
declare(strict_types=1);

/**
 * Financial Analytics Service
 * 
 * Handles financial analytics and reporting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.04 - Financial Analytics
 */
class FinancialAnalyticsService
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets revenue summary.
     * 
     * @param array $filters Filter parameters
     * @return array Revenue summary
     */
    public function getRevenueSummary(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Revenue summary
        $summarySql = "SELECT 
            COUNT(*) as total_transactions,
            SUM(amount) as total_revenue,
            AVG(amount) as average_transaction,
            MIN(amount) as min_transaction,
            MAX(amount) as max_transaction,
            SUM(CASE WHEN payment_method = 'Credit Card' THEN amount ELSE 0 END) as credit_card_revenue,
            SUM(CASE WHEN payment_method = 'Cash' THEN amount ELSE 0 END) as cash_revenue,
            SUM(CASE WHEN payment_method = 'Check' THEN amount ELSE 0 END) as check_revenue,
            COUNT(DISTINCT debtor_no) as unique_customers
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $summary = [
                'total_transactions' => (int)($row['total_transactions'] ?? 0),
                'total_revenue' => (float)($row['total_revenue'] ?? 0),
                'average_transaction' => (float)($row['average_transaction'] ?? 0),
                'min_transaction' => (float)($row['min_transaction'] ?? 0),
                'max_transaction' => (float)($row['max_transaction'] ?? 0),
                'credit_card_revenue' => (float)($row['credit_card_revenue'] ?? 0),
                'cash_revenue' => (float)($row['cash_revenue'] ?? 0),
                'check_revenue' => (float)($row['check_revenue'] ?? 0),
                'unique_customers' => (int)($row['unique_customers'] ?? 0)
            ];
        }
        
        // Monthly revenue trend
        $monthlySql = "SELECT 
            DATE_FORMAT(date_1, '%Y-%m') as month,
            COUNT(*) as transactions,
            SUM(amount) as revenue,
            COUNT(DISTINCT debtor_no) as customers
            FROM {$salesTable} 
            WHERE {$dateCondition}
            GROUP BY DATE_FORMAT(date_1, '%Y-%m')
            ORDER BY month ASC";
        
        $result = db_query($monthlySql);
        $trends = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $trends[] = [
                        'month' => $row['month'],
                        'transactions' => (int)$row['transactions'],
                        'revenue' => (float)$row['revenue'],
                        'customers' => (int)$row['customers']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'monthly_trends' => $trends
        ];
    }

    /**
     * Gets profit analysis.
     * 
     * @param array $filters Filter parameters
     * @return array Profit analysis
     */
    public function getProfitAnalysis(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        $detailsTable = $this->getSalesDetailsTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Profit summary
        $summarySql = "SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_revenue,
            SUM(cost_of_goods) as total_cogs,
            SUM(amount) - SUM(cost_of_goods) as gross_profit,
            (SUM(amount) - SUM(cost_of_goods)) / SUM(amount) * 100 as gross_margin_percentage,
            AVG(amount) as average_revenue,
            AVG(cost_of_goods) as average_cogs,
            AVG(amount - cost_of_goods) as average_profit
            FROM {$salesTable} s
            JOIN {$detailsTable} d ON s.payment_id = d.payment_id
            WHERE {$dateCondition}";
        
        $result = db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $summary = [
                'total_sales' => (int)($row['total_sales'] ?? 0),
                'total_revenue' => (float)($row['total_revenue'] ?? 0),
                'total_cogs' => (float)($row['total_cogs'] ?? 0),
                'gross_profit' => (float)($row['gross_profit'] ?? 0),
                'gross_margin_percentage' => (float)($row['gross_margin_percentage'] ?? 0),
                'average_revenue' => (float)($row['average_revenue'] ?? 0),
                'average_cogs' => (float)($row['average_cogs'] ?? 0),
                'average_profit' => (float)($row['average_profit'] ?? 0)
            ];
        }
        
        // Profit by product
        $productSql = "SELECT 
            d.item_code,
            d.item_description,
            SUM(d.quantity) as total_quantity,
            SUM(d.line_total) as total_revenue,
            SUM(d.cost_of_goods) as total_cogs,
            SUM(d.line_total - d.cost_of_goods) as profit,
            SUM(d.line_total - d.cost_of_goods) / SUM(d.line_total) * 100 as margin_percentage
            FROM {$salesTable} s
            JOIN {$detailsTable} d ON s.payment_id = d.payment_id
            WHERE {$dateCondition}
            GROUP BY d.item_code, d.item_description
            ORDER BY profit DESC
            LIMIT 20";
        
        $result = db_query($productSql);
        $byProduct = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $byProduct[] = [
                        'item_code' => $row['item_code'],
                        'item_description' => $row['item_description'],
                        'total_quantity' => (int)$row['total_quantity'],
                        'total_revenue' => (float)$row['total_revenue'],
                        'total_cogs' => (float)$row['total_cogs'],
                        'profit' => (float)$row['profit'],
                        'margin_percentage' => (float)$row['margin_percentage']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'by_product' => $byProduct
        ];
    }

    /**
     * Gets cash flow.
     * 
     * @param array $filters Filter parameters
     * @return array Cash flow
     */
    public function getCashFlow(array $filters): array
    {
        $paymentsTable = $this->getPaymentsTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Daily cash flow
        $dailySql = "SELECT 
            date_1 as date,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE 0 END) as cash_in,
            SUM(CASE WHEN bank_trans_type = 'Payment' THEN amount ELSE 0 END) as cash_out,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE -amount END) as net_cash_flow,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE -amount END) OVER (ORDER BY date_1) as running_balance
            FROM {$paymentsTable} 
            WHERE {$dateCondition}
            GROUP BY date_1
            ORDER BY date_1 ASC";
        
        $result = db_query($dailySql);
        $dailyFlow = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $dailyFlow[] = [
                        'date' => $row['date'],
                        'cash_in' => (float)$row['cash_in'],
                        'cash_out' => (float)$row['cash_out'],
                        'net_cash_flow' => (float)$row['net_cash_flow'],
                        'running_balance' => (float)$row['running_balance']
                    ];
                }
            }
        }
        
        // Weekly cash flow
        $weeklySql = "SELECT 
            DATE_FORMAT(date_1, '%Y-%u') as week,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE 0 END) as cash_in,
            SUM(CASE WHEN bank_trans_type = 'Payment' THEN amount ELSE 0 END) as cash_out,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE -amount END) as net_cash_flow
            FROM {$paymentsTable} 
            WHERE {$dateCondition}
            GROUP BY DATE_FORMAT(date_1, '%Y-%u')
            ORDER BY week ASC";
        
        $result = db_query($weeklySql);
        $weeklyFlow = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $weeklyFlow[] = [
                        'week' => $row['week'],
                        'cash_in' => (float)$row['cash_in'],
                        'cash_out' => (float)$row['cash_out'],
                        'net_cash_flow' => (float)$row['net_cash_flow']
                    ];
                }
            }
        }
        
        return [
            'daily_flow' => $dailyFlow,
            'weekly_flow' => $weeklyFlow
        ];
    }

    /**
     * Gets expense analysis.
     * 
     * @param array $filters Filter parameters
     * @return array Expense analysis
     */
    public function getExpenseAnalysis(array $filters): array
    {
        $expensesTable = $this->getExpensesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Expense summary
        $summarySql = "SELECT 
            COUNT(*) as total_expenses,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount,
            COUNT(DISTINCT category) as unique_categories
            FROM {$expensesTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $summary = [
                'total_expenses' => (int)($row['total_expenses'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'average_amount' => (float)($row['average_amount'] ?? 0),
                'min_amount' => (float)($row['min_amount'] ?? 0),
                'max_amount' => (float)($row['max_amount'] ?? 0),
                'unique_categories' => (int)($row['unique_categories'] ?? 0)
            ];
        }
        
        // Expenses by category
        $categorySql = "SELECT 
            category,
            COUNT(*) as expense_count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            MAX(amount) as max_amount
            FROM {$expensesTable} 
            WHERE {$dateCondition}
            GROUP BY category
            ORDER BY total_amount DESC";
        
        $result = db_query($categorySql);
        $byCategory = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $byCategory[] = [
                        'category' => $row['category'],
                        'expense_count' => (int)$row['expense_count'],
                        'total_amount' => (float)$row['total_amount'],
                        'average_amount' => (float)$row['average_amount'],
                        'max_amount' => (float)$row['max_amount']
                    ];
                }
            }
        }
        
        // Monthly expense trends
        $monthlySql = "SELECT 
            DATE_FORMAT(date_1, '%Y-%m') as month,
            COUNT(*) as expense_count,
            SUM(amount) as total_amount
            FROM {$expensesTable} 
            WHERE {$dateCondition}
            GROUP BY DATE_FORMAT(date_1, '%Y-%m')
            ORDER BY month ASC";
        
        $result = db_query($monthlySql);
        $monthlyTrends = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $monthlyTrends[] = [
                        'month' => $row['month'],
                        'expense_count' => (int)$row['expense_count'],
                        'total_amount' => (float)$row['total_amount']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'by_category' => $byCategory,
            'monthly_trends' => $monthlyTrends
        ];
    }

    /**
     * Gets financial health.
     * 
     * @param array $filters Filter parameters
     * @return array Financial health
     */
    public function getFinancialHealth(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        $paymentsTable = $this->getPaymentsTableName();
        $expensesTable = $this->getExpensesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Calculate financial health metrics
        $revenueSql = "SELECT 
            SUM(amount) as total_revenue,
            COUNT(*) as transaction_count,
            AVG(amount) as average_transaction
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($revenueSql);
        $revenue = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $revenue = [
                'total_revenue' => (float)($row['total_revenue'] ?? 0),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'average_transaction' => (float)($row['average_transaction'] ?? 0)
            ];
        }
        
        $expensesSql = "SELECT 
            SUM(amount) as total_expenses,
            COUNT(*) as expense_count
            FROM {$expensesTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($expensesSql);
        $expenses = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $expenses = [
                'total_expenses' => (float)($row['total_expenses'] ?? 0),
                'expense_count' => (int)($row['expense_count'] ?? 0)
            ];
        }
        
        $cashFlowSql = "SELECT 
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE 0 END) as total_cash_in,
            SUM(CASE WHEN bank_trans_type = 'Payment' THEN amount ELSE 0 END) as total_cash_out
            FROM {$paymentsTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($cashFlowSql);
        $cashFlow = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $cashFlow = [
                'total_cash_in' => (float)($row['total_cash_in'] ?? 0),
                'total_cash_out' => (float)($row['total_cash_out'] ?? 0),
                'net_cash_flow' => (float)($row['total_cash_in'] ?? 0) - (float)($row['total_cash_out'] ?? 0)
            ];
        }
        
        // Calculate financial health score
        $healthScore = $this->calculateFinancialHealthScore(
            $revenue['total_revenue'],
            $expenses['total_expenses'],
            $cashFlow['net_cash_flow'],
            $revenue['transaction_count'],
            $expenses['expense_count']
        );
        
        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'cash_flow' => $cashFlow,
            'health_score' => $healthScore
        ];
    }

    /**
     * Gets performance metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Performance metrics
     */
    public function getPerformanceMetrics(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Sales performance
        $performanceSql = "SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_revenue,
            AVG(amount) as average_sale,
            COUNT(DISTINCT debtor_no) as unique_customers,
            COUNT(DISTINCT location_id) as unique_locations,
            SUM(CASE WHEN payment_method = 'Credit Card' THEN amount ELSE 0 END) as credit_card_sales,
            SUM(CASE WHEN payment_method = 'Cash' THEN amount ELSE 0 END) as cash_sales
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = db_query($performanceSql);
        $metrics = [];
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            $metrics = [
                'total_sales' => (int)($row['total_sales'] ?? 0),
                'total_revenue' => (float)($row['total_revenue'] ?? 0),
                'average_sale' => (float)($row['average_sale'] ?? 0),
                'unique_customers' => (int)($row['unique_customers'] ?? 0),
                'unique_locations' => (int)($row['unique_locations'] ?? 0),
                'credit_card_sales' => (float)($row['credit_card_sales'] ?? 0),
                'cash_sales' => (float)($row['cash_sales'] ?? 0)
            ];
        }
        
        // Performance trends
        $trendSql = "SELECT 
            DATE(date_1) as date,
            COUNT(*) as daily_sales,
            SUM(amount) as daily_revenue,
            COUNT(DISTINCT debtor_no) as daily_customers
            FROM {$salesTable} 
            WHERE {$dateCondition}
            GROUP BY DATE(date_1)
            ORDER BY date ASC
            LIMIT 30";
        
        $result = db_query($trendSql);
        $trends = [];
        
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $trends[] = [
                        'date' => $row['date'],
                        'daily_sales' => (int)$row['daily_sales'],
                        'daily_revenue' => (float)$row['daily_revenue'],
                        'daily_customers' => (int)$row['daily_customers']
                    ];
                }
            }
        }
        
        return [
            'overall_metrics' => $metrics,
            'trends' => $trends
        ];
    }

    /**
     * Calculates financial health score.
     * 
     * @param float $revenue Total revenue
     * @param float $expenses Total expenses
     * @param float $netCashFlow Net cash flow
     * @param int $salesCount Sales count
     * @param int $expenseCount Expense count
     * @return array Health score components
     */
    private function calculateFinancialHealthScore(float $revenue, float $expenses, float $netCashFlow, int $salesCount, int $expenseCount): array
    {
        // Calculate ratios
        $profitMargin = $revenue > 0 ? (($revenue - $expenses) / $revenue) * 100 : 0;
        $cashFlowRatio = $revenue > 0 ? ($netCashFlow / $revenue) * 100 : 0;
        $salesEfficiency = $expenseCount > 0 ? $salesCount / $expenseCount : 0;
        
        // Calculate individual scores (0-100)
        $profitScore = min(100, max(0, ($profitMargin + 50))); // Scale: -50% to 50% -> 0 to 100
        $cashFlowScore = min(100, max(0, $cashFlowRatio + 50)); // Scale: -50% to 50% -> 0 to 100
        $efficiencyScore = min(100, max(0, $salesEfficiency * 10)); // Scale: 0 to 10 -> 0 to 100
        
        // Calculate overall score
        $overallScore = ($profitScore + $cashFlowScore + $efficiencyScore) / 3;
        
        return [
            'overall_score' => (int)$overallScore,
            'profit_margin' => $profitMargin,
            'cash_flow_ratio' => $cashFlowRatio,
            'sales_efficiency' => $salesEfficiency,
            'profit_score' => (int)$profitScore,
            'cash_flow_score' => (int)$cashFlowScore,
            'efficiency_score' => (int)$efficiencyScore,
            'rating' => $this->getHealthRating($overallScore)
        ];
    }

    /**
     * Gets health rating based on score.
     * 
     * @param float $score Health score
     * @return string Rating
     */
    private function getHealthRating(float $score): string
    {
        if ($score >= 80) return 'Excellent';
        if ($score >= 60) return 'Good';
        if ($score >= 40) return 'Fair';
        if ($score >= 20) return 'Poor';
        return 'Critical';
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

    /**
     * Gets payments table name.
     * 
     * @return string Table name
     */
    private function getPaymentsTableName(): string
    {
        return $this->tablePrefix . 'payments';
    }

    /**
     * Gets expenses table name.
     * 
     * @return string Table name
     */
    private function getExpensesTableName(): string
    {
        return $this->tablePrefix . 'expenses';
    }
}