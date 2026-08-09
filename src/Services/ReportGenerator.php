<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Report Generator
 * 
 * Handles custom report generation.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.05 - Custom Reports
 */
class ReportGenerator
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Generates custom report.
     * 
     * @param array $reportData Report data
     * @return array Report results
     */
    public function generateReport(array $reportData): array
    {
        $startTime = microtime(true);
        
        try {
            $reportType = $reportData['report_type'];
            $filters = $reportData['filters'] ?? [];
            
            // Generate report based on type
            switch ($reportType) {
                case 'sales':
                    $report = $this->generateSalesReport($filters);
                    break;
                case 'customer':
                    $report = $this->generateCustomerReport($filters);
                    break;
                case 'inventory':
                    $report = $this->generateInventoryReport($filters);
                    break;
                case 'financial':
                    $report = $this->generateFinancialReport($filters);
                    break;
                case 'performance':
                    $report = $this->generatePerformanceReport($filters);
                    break;
                default:
                    throw new \Exception("Unsupported report type: {$reportType}");
            }
            
            $executionTime = microtime(true) - $startTime;
            
            return [
                'report_type' => $reportType,
                'generated_at' => date('Y-m-d H:i:s'),
                'execution_time' => $executionTime,
                'filters' => $filters,
                'data' => $report
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Report generation failed: " . $e->getMessage());
        }
    }

    /**
     * Generates sales report.
     * 
     * @param array $filters Filter parameters
     * @return array Sales report data
     */
    private function generateSalesReport(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Sales summary
        $summarySql = "SELECT 
            COUNT(*) as total_transactions,
            SUM(amount) as total_revenue,
            AVG(amount) as average_transaction,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount,
            COUNT(DISTINCT debtor_no) as unique_customers,
            COUNT(DISTINCT location_id) as unique_locations
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = \db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = $row ? [
                'total_transactions' => (int)$row['total_transactions'],
                'total_revenue' => (float)$row['total_revenue'],
                'average_transaction' => (float)$row['average_transaction'],
                'min_amount' => (float)$row['min_amount'],
                'max_amount' => (float)$row['max_amount'],
                'unique_customers' => (int)$row['unique_customers'],
                'unique_locations' => (int)$row['unique_locations']
            ] : [];
        }
        
        // Sales by payment method
        $methodSql = "SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount
            FROM {$salesTable} 
            WHERE {$dateCondition}
            GROUP BY payment_method
            ORDER BY count DESC";
        
        $result = \db_query($methodSql);
        $byMethod = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row) {
                    $byMethod[] = [
                        'payment_method' => $row['payment_method'],
                        'count' => (int)$row['count'],
                        'total_amount' => (float)$row['total_amount'],
                        'average_amount' => (float)$row['average_amount']
                    ];
                }
            }
        }
        
        // Sales by location
        $locationSql = "SELECT 
            location_id,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount
            FROM {$salesTable} 
            WHERE {$dateCondition}
            GROUP BY location_id
            ORDER BY total_amount DESC";
        
        $result = \db_query($locationSql);
        $byLocation = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row) {
                    $byLocation[] = [
                        'location_id' => $row['location_id'],
                        'count' => (int)$row['count'],
                        'total_amount' => (float)$row['total_amount'],
                        'average_amount' => (float)$row['average_amount']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'by_payment_method' => $byMethod,
            'by_location' => $byLocation
        ];
    }

    /**
     * Generates customer report.
     * 
     * @param array $filters Filter parameters
     * @return array Customer report data
     */
    private function generateCustomerReport(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        $customerTable = $this->getCustomerTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Customer summary
        $summarySql = "SELECT 
            COUNT(*) as total_customers,
            COUNT(DISTINCT debtor_no) as unique_customers,
            COUNT(DISTINCT person_id) as unique_individuals,
            COUNT(DISTINCT company_id) as unique_companies
            FROM {$customerTable} c";
        
        $result = \db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = $row ? [
                'total_customers' => (int)$row['total_customers'],
                'unique_customers' => (int)$row['unique_customers'],
                'unique_individuals' => (int)$row['unique_individuals'],
                'unique_companies' => (int)$row['unique_companies']
            ] : [];
        }
        
        // Customer spending analysis
        $spendingSql = "SELECT 
            c.debtor_no,
            c.name,
            COUNT(DISTINCT s.payment_id) as transaction_count,
            SUM(s.amount) as total_spent,
            AVG(s.amount) as average_transaction,
            MIN(s.date_1) as first_purchase,
            MAX(s.date_1) as last_purchase
            FROM {$customerTable} c
            LEFT JOIN {$salesTable} s ON c.debtor_no = s.debtor_no
            WHERE {$dateCondition}
            GROUP BY c.debtor_no, c.name
            ORDER BY total_spent DESC
            LIMIT 20";
        
        $result = \db_query($spendingSql);
        $spending = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row) {
                    $spending[] = [
                        'debtor_no' => (int)$row['debtor_no'],
                        'name' => $row['name'],
                        'transaction_count' => (int)$row['transaction_count'],
                        'total_spent' => (float)$row['total_spent'],
                        'average_transaction' => (float)$row['average_transaction'],
                        'first_purchase' => $row['first_purchase'],
                        'last_purchase' => $row['last_purchase']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'spending_analysis' => $spending
        ];
    }

    /**
     * Generates inventory report.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory report data
     */
    private function generateInventoryReport(array $filters): array
    {
        $inventoryTable = $this->getInventoryTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Inventory summary
        $summarySql = "SELECT 
            COUNT(*) as total_items,
            SUM(qty_on_hand) as total_quantity,
            SUM(unit_cost * qty_on_hand) as total_value,
            AVG(qty_on_hand) as average_quantity,
            MIN(qty_on_hand) as min_quantity,
            MAX(qty_on_hand) as max_quantity,
            SUM(CASE WHEN qty_on_hand = 0 THEN 1 END) as out_of_stock,
            SUM(CASE WHEN qty_on_hand < reorder_level THEN 1 END) as low_stock
            FROM {$inventoryTable} 
            WHERE {$dateCondition}";
        
        $result = \db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = $row ? [
                'total_items' => (int)$row['total_items'],
                'total_quantity' => (int)$row['total_quantity'],
                'total_value' => (float)$row['total_value'],
                'average_quantity' => (float)$row['average_quantity'],
                'min_quantity' => (int)$row['min_quantity'],
                'max_quantity' => (int)$row['max_quantity'],
                'out_of_stock' => (int)$row['out_of_stock'],
                'low_stock' => (int)$row['low_stock']
            ] : [];
        }
        
        // Inventory by category
        $categorySql = "SELECT 
            category,
            COUNT(*) as item_count,
            SUM(qty_on_hand) as total_quantity,
            SUM(unit_cost * qty_on_hand) as total_value,
            AVG(qty_on_hand) as average_quantity
            FROM {$inventoryTable} 
            WHERE {$dateCondition}
            GROUP BY category
            ORDER BY total_value DESC";
        
        $result = \db_query($categorySql);
        $byCategory = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row) {
                    $byCategory[] = [
                        'category' => $row['category'],
                        'item_count' => (int)$row['item_count'],
                        'total_quantity' => (int)$row['total_quantity'],
                        'total_value' => (float)$row['total_value'],
                        'average_quantity' => (float)$row['average_quantity']
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
     * Generates financial report.
     * 
     * @param array $filters Filter parameters
     * @return array Financial report data
     */
    private function generateFinancialReport(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        $paymentsTable = $this->getPaymentsTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Financial summary
        $summarySql = "SELECT 
            COUNT(*) as total_transactions,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            MIN(amount) as min_amount,
            MAX(amount) as max_amount,
            COUNT(DISTINCT debtor_no) as unique_customers
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = \db_query($summarySql);
        $summary = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $summary = $row ? [
                'total_transactions' => (int)$row['total_transactions'],
                'total_amount' => (float)$row['total_amount'],
                'average_amount' => (float)$row['average_amount'],
                'min_amount' => (float)$row['min_amount'],
                'max_amount' => (float)$row['max_amount'],
                'unique_customers' => (int)$row['unique_customers']
            ] : [];
        }
        
        // Cash flow analysis
        $cashFlowSql = "SELECT 
            date_1 as date,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE 0 END) as cash_in,
            SUM(CASE WHEN bank_trans_type = 'Payment' THEN amount ELSE 0 END) as cash_out,
            SUM(CASE WHEN bank_trans_type = 'Receipt' THEN amount ELSE -amount END) as net_flow
            FROM {$paymentsTable} 
            WHERE {$dateCondition}
            GROUP BY date_1
            ORDER BY date_1 ASC";
        
        $result = \db_query($cashFlowSql);
        $cashFlow = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row) {
                    $cashFlow[] = [
                        'date' => $row['date'],
                        'cash_in' => (float)$row['cash_in'],
                        'cash_out' => (float)$row['cash_out'],
                        'net_flow' => (float)$row['net_flow']
                    ];
                }
            }
        }
        
        return [
            'summary' => $summary,
            'cash_flow' => $cashFlow
        ];
    }

    /**
     * Generates performance report.
     * 
     * @param array $filters Filter parameters
     * @return array Performance report data
     */
    private function generatePerformanceReport(array $filters): array
    {
        $salesTable = $this->getSalesTableName();
        
        // Build date range query
        $dateCondition = $this->buildDateCondition($filters);
        
        // Performance metrics
        $metricsSql = "SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_revenue,
            AVG(amount) as average_sale,
            COUNT(DISTINCT debtor_no) as unique_customers,
            COUNT(DISTINCT location_id) as unique_locations,
            AVG(amount) as average_transaction_value
            FROM {$salesTable} 
            WHERE {$dateCondition}";
        
        $result = \db_query($metricsSql);
        $metrics = [];
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            $metrics = $row ? [
                'total_sales' => (int)$row['total_sales'],
                'total_revenue' => (float)$row['total_revenue'],
                'average_sale' => (float)$row['average_sale'],
                'unique_customers' => (int)$row['unique_customers'],
                'unique_locations' => (int)$row['unique_locations'],
                'average_transaction_value' => (float)$row['average_transaction_value']
            ] : [];
        }
        
        return [
            'performance_metrics' => $metrics
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
     * Gets sales table name.
     * 
     * @return string Table name
     */
    private function getSalesTableName(): string
    {
        return $this->tablePrefix . 'sales';
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
     * Gets inventory table name.
     * 
     * @return string Table name
     */
    private function getInventoryTableName(): string
    {
        return $this->tablePrefix . 'inventory';
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
}