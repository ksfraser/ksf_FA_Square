<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Ksfraser\Frontaccounting\SquareUp\Contracts\BusinessIntelligenceInterface;

use Ksfraser\Frontaccounting\SquareUp\Exceptions\AnalyticsException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\ReportGenerationException;
/**
 * Business Intelligence Service
 * 
 * Handles business intelligence and analytics for Square and FA data.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.01 - Sales Analytics, FR-08.02 - Customer Analytics
 */
class BusinessIntelligenceService implements BusinessIntelligenceInterface
{
    private SalesAnalyticsService $salesAnalytics;
    private CustomerAnalyticsService $customerAnalytics;
    private InventoryAnalyticsService $inventoryAnalytics;
    private FinancialAnalyticsService $financialAnalytics;
    private ReportGenerator $reportGenerator;
    private string $tablePrefix;

    public function __construct(
        SalesAnalyticsService $salesAnalytics,
        CustomerAnalyticsService $customerAnalytics,
        InventoryAnalyticsService $inventoryAnalytics,
        FinancialAnalyticsService $financialAnalytics,
        ReportGenerator $reportGenerator
    ) {
        $this->salesAnalytics = $salesAnalytics;
        $this->customerAnalytics = $customerAnalytics;
        $this->inventoryAnalytics = $inventoryAnalytics;
        $this->financialAnalytics = $financialAnalytics;
        $this->reportGenerator = $reportGenerator;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Gets sales analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Sales analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getSalesAnalytics(array $filters): array
    {
        try {
            // Validate filters
            $this->validateFilters($filters);
            
            // Get sales summary across analytical dimensions
            $summaryParts = [];
            foreach (['overall', 'trends', 'top_products', 'location_performance', 'payment_distribution'] as $dimension) {
                $summaryParts[] = $this->salesAnalytics->getSalesSummary($filters + ['summary_dimension' => $dimension]);
            }
            $salesSummary = array_merge(...$summaryParts);
            
            // Get sales trends
            $salesTrends = $this->salesAnalytics->getSalesTrends($filters);
            
            // Get top products
            $topProducts = $this->salesAnalytics->getTopProducts($filters);
            
            // Get location performance
            $locationPerformance = $this->salesAnalytics->getLocationPerformance($filters);
            
            // Get payment method distribution
            $paymentDistribution = $this->salesAnalytics->getPaymentMethodDistribution($filters);
            
            return [
                'summary' => $salesSummary,
                'trends' => $salesTrends,
                'top_products' => $topProducts,
                'location_performance' => $locationPerformance,
                'payment_distribution' => $paymentDistribution,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new AnalyticsException("Failed to generate sales analytics: " . $e->getMessage());
        }
    }

    /**
     * Gets customer analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Customer analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getCustomerAnalytics(array $filters): array
    {
        try {
            // Validate filters
            $this->validateFilters($filters);
            
            // Get customer summary across analytical dimensions
            $summaryParts = [];
            foreach (['overall', 'lifetime_value', 'segments', 'acquisition', 'retention'] as $dimension) {
                $summaryParts[] = $this->customerAnalytics->getCustomerSummary($filters + ['summary_dimension' => $dimension]);
            }
            $customerSummary = array_merge(...$summaryParts);
            
            // Get customer lifetime value
            $customerLTV = $this->customerAnalytics->getCustomerLifetimeValue($filters);
            
            // Get customer segmentation
            $customerSegments = $this->customerAnalytics->getCustomerSegments($filters);
            
            // Get customer acquisition
            $customerAcquisition = $this->customerAnalytics->getCustomerAcquisition($filters);
            
            // Get customer retention
            $customerRetention = $this->customerAnalytics->getCustomerRetention($filters);
            
            return [
                'summary' => $customerSummary,
                'lifetime_value' => $customerLTV,
                'segments' => $customerSegments,
                'acquisition' => $customerAcquisition,
                'retention' => $customerRetention,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new AnalyticsException("Failed to generate customer analytics: " . $e->getMessage());
        }
    }

    /**
     * Gets inventory analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getInventoryAnalytics(array $filters): array
    {
        try {
            // Validate filters
            $this->validateFilters($filters);
            
            // Get inventory summary across analytical dimensions
            $summaryParts = [];
            foreach (['overall', 'turnover', 'accuracy', 'slow_moving', 'stock_alerts'] as $dimension) {
                $summaryParts[] = $this->inventoryAnalytics->getInventorySummary($filters + ['summary_dimension' => $dimension]);
            }
            $inventorySummary = array_merge(...$summaryParts);
            
            // Get stock turnover
            $stockTurnover = $this->inventoryAnalytics->getStockTurnover($filters);
            
            // Get inventory accuracy
            $inventoryAccuracy = $this->inventoryAnalytics->getInventoryAccuracy($filters);
            
            // Get slow-moving items
            $slowMovingItems = $this->inventoryAnalytics->getSlowMovingItems($filters);
            
            // Get stock alerts
            $stockAlerts = $this->inventoryAnalytics->getStockAlerts($filters);
            
            return [
                'summary' => $inventorySummary,
                'turnover' => $stockTurnover,
                'accuracy' => $inventoryAccuracy,
                'slow_moving_items' => $slowMovingItems,
                'stock_alerts' => $stockAlerts,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new AnalyticsException("Failed to generate inventory analytics: " . $e->getMessage());
        }
    }

    /**
     * Gets financial analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Financial analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getFinancialAnalytics(array $filters): array
    {
        try {
            // Validate filters
            $this->validateFilters($filters);
            
            // Get revenue summary across analytical dimensions
            $summaryParts = [];
            foreach (['overall', 'profit', 'cash_flow', 'expenses', 'financial_health'] as $dimension) {
                $summaryParts[] = $this->financialAnalytics->getRevenueSummary($filters + ['summary_dimension' => $dimension]);
            }
            $revenueSummary = array_merge(...$summaryParts);
            
            // Get profit analysis
            $profitAnalysis = $this->financialAnalytics->getProfitAnalysis($filters);
            
            // Get cash flow
            $cashFlow = $this->financialAnalytics->getCashFlow($filters);
            
            // Get expense analysis
            $expenseAnalysis = $this->financialAnalytics->getExpenseAnalysis($filters);
            
            // Get financial health
            $financialHealth = $this->financialAnalytics->getFinancialHealth($filters);
            
            return [
                'revenue_summary' => $revenueSummary,
                'profit_analysis' => $profitAnalysis,
                'cash_flow' => $cashFlow,
                'expense_analysis' => $expenseAnalysis,
                'financial_health' => $financialHealth,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new AnalyticsException("Failed to generate financial analytics: " . $e->getMessage());
        }
    }

    /**
     * Gets performance metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Performance metrics
     * @throws AnalyticsException on processing failure
     */
    public function getPerformanceMetrics(array $filters): array
    {
        try {
            // Validate filters
            $this->validateFilters($filters);
            
            // Get sales performance
            $salesPerformanceData = $this->salesAnalytics->getPerformanceMetrics($filters);
            $salesPerformance = $salesPerformanceData['sales_performance'] ?? $salesPerformanceData;
            
            // Get customer satisfaction
            $customerSatisfaction = $this->customerAnalytics->getSatisfactionMetrics($filters);
            
            // Get operational efficiency
            $operationalEfficiency = $this->inventoryAnalytics->getEfficiencyMetrics($filters);
            
            // Get financial performance
            $financialPerformanceData = $this->financialAnalytics->getPerformanceMetrics($filters);
            $financialPerformance = $financialPerformanceData['overall_metrics'] ?? $financialPerformanceData;
            
            // Get system performance
            $systemPerformance = $this->getSystemPerformanceMetrics($filters);
            
            return [
                'sales_performance' => $salesPerformance,
                'customer_satisfaction' => $customerSatisfaction,
                'operational_efficiency' => $operationalEfficiency,
                'financial_performance' => $financialPerformance,
                'system_performance' => $systemPerformance,
                'generated_at' => date('Y-m-d H:i:s'),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new AnalyticsException("Failed to generate performance metrics: " . $e->getMessage());
        }
    }

    /**
     * Generates custom report.
     * 
     * @param array $reportData Report data
     * @return array Report results
     * @throws ReportGenerationException on generation failure
     */
    public function generateCustomReport(array $reportData): array
    {
        try {
            // Validate report data
            $this->validateReportData($reportData);
            
            // Generate report using report generator
            $report = $this->reportGenerator->generateReport($reportData);
            
            // Log report generation
            $this->logReportGeneration($reportData, $report);
            
            return $report;
            
        } catch (\Exception $e) {
            throw new ReportGenerationException("Failed to generate custom report: " . $e->getMessage());
        }
    }

    /**
     * Validates filters.
     * 
     * @param array $filters Filter parameters
     * @throws AnalyticsException on validation failure
     */
    private function validateFilters(array $filters): void
    {
        if (empty($filters)) {
            throw new AnalyticsException("Filters are required");
        }
        
        // Validate date range
        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $startDate = strtotime($filters['start_date']);
            $endDate = strtotime($filters['end_date']);
            
            if ($startDate === false || $endDate === false) {
                throw new AnalyticsException("Invalid date format");
            }
            
            if ($startDate > $endDate) {
                throw new AnalyticsException("Start date cannot be after end date");
            }
        }
        
        // Validate location
        if (isset($filters['location_id']) && !is_string($filters['location_id'])) {
            throw new AnalyticsException("Location ID must be a string");
        }
    }

    /**
     * Validates report data.
     * 
     * @param array $reportData Report data
     * @throws ReportGenerationException on validation failure
     */
    private function validateReportData(array $reportData): void
    {
        if (empty($reportData)) {
            throw new ReportGenerationException("Report data is required");
        }
        
        if (empty($reportData['report_type'])) {
            throw new ReportGenerationException("Report type is required");
        }
        
        if (empty($reportData['filters'])) {
            throw new ReportGenerationException("Report filters are required");
        }
        
        // Validate report type
        $validTypes = ['sales', 'customer', 'inventory', 'financial', 'performance'];
        if (!in_array($reportData['report_type'], $validTypes)) {
            throw new ReportGenerationException("Invalid report type");
        }
    }

    /**
     * Logs report generation.
     * 
     * @param array $reportData Report data
     * @param array $report Report results
     * @return int Log ID
     */
    private function logReportGeneration(array $reportData, array $report): int
    {
        $tableName = $this->getReportLogsTableName();
        $userId = $reportData['user_id'] ?? 1;
        
        $sql = "INSERT INTO {$tableName} (
            report_type, user_id, filters, report_data, 
            generated_at, execution_time
        ) VALUES (
            '{$reportData['report_type']}',
            {$userId},
            '" . \db_escape(json_encode($reportData['filters'])) . "',
            '" . \db_escape(json_encode($report)) . "',
            '{$report['generated_at']}',
            {$report['execution_time']}
        )";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Gets system performance metrics.
     * 
     * @param array $filters Filter parameters
     * @return array System performance metrics
     */
    private function getSystemPerformanceMetrics(array $filters): array
    {
        $metrics = [
            'response_time' => $this->calculateAverageResponseTime(),
            'error_rate' => $this->calculateErrorRate(),
            'uptime' => $this->calculateUptime(),
            'throughput' => $this->calculateThroughput(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage()
        ];
        
        return $metrics;
    }

    /**
     * Calculates average response time.
     * 
     * @return float Average response time in seconds
     */
    private function calculateAverageResponseTime(): float
    {
        // This would normally query performance logs
        // For now, return a placeholder
        return 0.45; // 450ms
    }

    /**
     * Calculates error rate.
     * 
     * @return float Error rate as percentage
     */
    private function calculateErrorRate(): float
    {
        // This would normally query error logs
        // For now, return a placeholder
        return 0.1; // 0.1%
    }

    /**
     * Calculates uptime.
     * 
     * @return float Uptime as percentage
     */
    private function calculateUptime(): float
    {
        // This would normally query system logs
        // For now, return a placeholder
        return 99.99; // 99.99%
    }

    /**
     * Calculates throughput.
     * 
     * @return int Throughput in requests per minute
     */
    private function calculateThroughput(): int
    {
        // This would normally query performance logs
        // For now, return a placeholder
        return 120; // 120 requests/minute
    }

    /**
     * Gets memory usage.
     * 
     * @return array Memory usage information
     */
    private function getMemoryUsage(): array
    {
        // Stand-in values until live system monitoring is wired in
        return [
            'used' => 0,
            'peak' => 0,
            'limit' => '128M'
        ];
    }

    /**
     * Gets disk usage.
     * 
     * @return array Disk usage information
     */
    private function getDiskUsage(): array
    {
        // Stand-in values until live disk monitoring is wired in
        return [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'percentage' => 0
        ];
    }

    /**
     * Gets report logs table name.
     * 
     * @return string Table name
     */
    private function getReportLogsTableName(): string
    {
        return $this->tablePrefix . 'report_logs';
    }
}