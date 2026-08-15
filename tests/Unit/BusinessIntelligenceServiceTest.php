<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use ksfraser\FrontAccounting\Square\Services\BusinessIntelligenceService;
use ksfraser\FrontAccounting\Square\Services\SalesAnalyticsService;
use ksfraser\FrontAccounting\Square\Services\CustomerAnalyticsService;
use ksfraser\FrontAccounting\Square\Services\InventoryAnalyticsService;
use ksfraser\FrontAccounting\Square\Services\FinancialAnalyticsService;
use ksfraser\FrontAccounting\Square\Services\ReportGenerator;
use ksfraser\FrontAccounting\Square\Exceptions\AnalyticsException;
use ksfraser\FrontAccounting\Square\Exceptions\ReportGenerationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for BusinessIntelligenceService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-08.01 through FR-08.05 - Business Intelligence
 */
class BusinessIntelligenceServiceTest extends TestCase
{
    protected MockObject $mockSalesAnalytics;
    protected MockObject $mockCustomerAnalytics;
    protected MockObject $mockInventoryAnalytics;
    protected MockObject $mockFinancialAnalytics;
    protected MockObject $mockReportGenerator;
    protected BusinessIntelligenceService $biService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock services
        $this->mockSalesAnalytics = $this->createMock(SalesAnalyticsService::class);
        $this->mockCustomerAnalytics = $this->createMock(CustomerAnalyticsService::class);
        $this->mockInventoryAnalytics = $this->createMock(InventoryAnalyticsService::class);
        $this->mockFinancialAnalytics = $this->createMock(FinancialAnalyticsService::class);
        $this->mockReportGenerator = $this->createMock(ReportGenerator::class);
        
        // Create BI service
        $this->biService = new BusinessIntelligenceService(
            $this->mockSalesAnalytics,
            $this->mockCustomerAnalytics,
            $this->mockInventoryAnalytics,
            $this->mockFinancialAnalytics,
            $this->mockReportGenerator
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function getSalesAnalyticsReturnsCorrectData(): void
    {
        // Arrange
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $expectedSummary = [
            'total_transactions' => 100,
            'total_amount' => 5000.00,
            'average_transaction' => 50.00,
            'min_amount' => 10.00,
            'max_amount' => 200.00
        ];
        
        $expectedTrends = [
            [
                'date' => '2024-01-01',
                'transactions' => 10,
                'total_amount' => 500.00,
                'unique_customers' => 8
            ]
        ];
        
        $expectedProducts = [
            [
                'item_code' => '001',
                'quantity_sold' => 50,
                'total_revenue' => 2500.00,
                'average_price' => 50.00
            ]
        ];
        
        $expectedLocations = [
            [
                'location_id' => 'loc1',
                'transactions' => 80,
                'total_amount' => 4000.00,
                'average_transaction' => 50.00,
                'unique_customers' => 60
            ]
        ];
        
        $expectedPaymentDistribution = [
            [
                'payment_method' => 'Credit Card',
                'count' => 70,
                'total_amount' => 3500.00,
                'average_amount' => 50.00,
                'percentage' => 70
            ]
        ];
        
        // Mock sales analytics
        $this->mockSalesAnalytics->expects($this->exactly(5))
            ->method('getSalesSummary')
            ->willReturn($expectedSummary);
            
        $this->mockSalesAnalytics->expects($this->once())
            ->method('getSalesTrends')
            ->willReturn($expectedTrends);
            
        $this->mockSalesAnalytics->expects($this->once())
            ->method('getTopProducts')
            ->willReturn($expectedProducts);
            
        $this->mockSalesAnalytics->expects($this->once())
            ->method('getLocationPerformance')
            ->willReturn($expectedLocations);
            
        $this->mockSalesAnalytics->expects($this->once())
            ->method('getPaymentMethodDistribution')
            ->willReturn($expectedPaymentDistribution);
        
        // Act
        $result = $this->biService->getSalesAnalytics($filters);
        
        // Assert
        $this->assertEquals($expectedSummary, $result['summary']);
        $this->assertEquals($expectedTrends, $result['trends']);
        $this->assertEquals($expectedProducts, $result['top_products']);
        $this->assertEquals($expectedLocations, $result['location_performance']);
        $this->assertEquals($expectedPaymentDistribution, $result['payment_distribution']);
        $this->assertEquals($filters, $result['filters']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * @test
     */
    public function getSalesAnalyticsHandlesInvalidFilters(): void
    {
        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessage("Invalid date format");
        
        // Arrange
        $filters = ['start_date' => 'invalid-date', 'end_date' => '2024-12-31'];
        
        // Act
        $this->biService->getSalesAnalytics($filters);
    }

    /**
     * @test
     */
    public function getSalesAnalyticsHandlesEmptyFilters(): void
    {
        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessage("Filters are required");
        
        // Arrange
        $filters = [];
        
        // Act
        $this->biService->getSalesAnalytics($filters);
    }

    /**
     * @test
     */
    public function getCustomerAnalyticsReturnsCorrectData(): void
    {
        // Arrange
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $expectedSummary = [
            'total_customers' => 50,
            'unique_individuals' => 40,
            'unique_companies' => 10,
            'unique_locations' => 2
        ];
        
        $expectedLTV = [
            [
                'debtor_no' => 1,
                'name' => 'John Doe',
                'total_transactions' => 5,
                'total_spent' => 250.00,
                'average_transaction' => 50.00,
                'first_purchase' => '2024-01-01',
                'last_purchase' => '2024-12-31',
                'days_as_customer' => 365,
                'daily_spend' => 0.68,
                'days_purchased' => 5,
                'ltv_score' => 75
            ]
        ];
        
        $expectedSegments = [
            'high_value' => [
                'count' => 10,
                'percentage' => 20,
                'total_spent' => 1000.00,
                'average_spent' => 100.00,
                'top_customers' => []
            ],
            'medium_value' => [
                'count' => 20,
                'percentage' => 40,
                'total_spent' => 800.00,
                'average_spent' => 40.00,
                'top_customers' => []
            ],
            'low_value' => [
                'count' => 20,
                'percentage' => 40,
                'total_spent' => 500.00,
                'average_spent' => 25.00,
                'top_customers' => []
            ],
            'new_customers' => []
        ];
        
        // Mock customer analytics
        $this->mockCustomerAnalytics->expects($this->exactly(5))
            ->method('getCustomerSummary')
            ->willReturn($expectedSummary);
            
        $this->mockCustomerAnalytics->expects($this->once())
            ->method('getCustomerLifetimeValue')
            ->willReturn($expectedLTV);
            
        $this->mockCustomerAnalytics->expects($this->once())
            ->method('getCustomerSegments')
            ->willReturn($expectedSegments);
            
        $this->mockCustomerAnalytics->expects($this->once())
            ->method('getCustomerAcquisition')
            ->willReturn(['monthly_acquisition' => [], 'by_source' => []]);
            
        $this->mockCustomerAnalytics->expects($this->once())
            ->method('getCustomerRetention')
            ->willReturn([]);
        
        // Act
        $result = $this->biService->getCustomerAnalytics($filters);
        
        // Assert
        $this->assertEquals($expectedSummary, $result['summary']);
        $this->assertEquals($expectedLTV, $result['lifetime_value']);
        $this->assertEquals($expectedSegments, $result['segments']);
        $this->assertEquals($filters, $result['filters']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * @test
     */
    public function getInventoryAnalyticsReturnsCorrectData(): void
    {
        // Arrange
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $expectedSummary = [
            'total_items' => 100,
            'total_quantity' => 500,
            'total_value' => 10000.00,
            'out_of_stock' => 5,
            'low_stock' => 10,
            'average_quantity' => 5.00,
            'min_quantity' => 0,
            'max_quantity' => 50
        ];
        
        $expectedByCategory = [
            [
                'category' => 'Electronics',
                'item_count' => 50,
                'total_quantity' => 300,
                'total_value' => 8000.00
            ]
        ];
        
        $expectedTurnover = [
            [
                'category' => 'Electronics',
                'item_code' => '001',
                'qty_on_hand' => 10,
                'unit_cost' => 100.00,
                'reorder_level' => 5,
                'total_sold' => 50,
                'turnover_ratio' => 5.00,
                'days_of_inventory' => 6.00
            ]
        ];
        
        $expectedAlerts = [
            [
                'severity' => 'Critical',
                'count' => 5,
                'total_quantity' => 0,
                'total_value' => 0
            ],
            [
                'severity' => 'High',
                'count' => 10,
                'total_quantity' => 50,
                'total_value' => 5000.00
            ]
        ];
        
        // Mock inventory analytics
        $this->mockInventoryAnalytics->expects($this->exactly(5))
            ->method('getInventorySummary')
            ->willReturn(['summary' => $expectedSummary, 'by_category' => $expectedByCategory]);
            
        $this->mockInventoryAnalytics->expects($this->once())
            ->method('getStockTurnover')
            ->willReturn($expectedTurnover);
            
        $this->mockInventoryAnalytics->expects($this->once())
            ->method('getStockAlerts')
            ->willReturn(['by_severity' => $expectedAlerts, 'specific_items' => []]);
            
        $this->mockInventoryAnalytics->expects($this->once())
            ->method('getSlowMovingItems')
            ->willReturn([]);
            
        $this->mockInventoryAnalytics->expects($this->once())
            ->method('getInventoryAccuracy')
            ->willReturn(['total_counts' => 0, 'accuracy_percentage' => 0]);
        
        // Act
        $result = $this->biService->getInventoryAnalytics($filters);
        
        // Assert
        $this->assertEquals($expectedSummary, $result['summary']['summary']);
        $this->assertEquals($expectedByCategory, $result['summary']['by_category']);
        $this->assertEquals($expectedTurnover, $result['turnover']);
        $this->assertEquals($expectedAlerts, $result['stock_alerts']['by_severity']);
        $this->assertEquals($filters, $result['filters']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * @test
     */
    public function getFinancialAnalyticsReturnsCorrectData(): void
    {
        // Arrange
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $expectedRevenue = [
            'summary' => [
                'total_transactions' => 100,
                'total_revenue' => 5000.00,
                'average_transaction' => 50.00,
                'min_transaction' => 10.00,
                'max_transaction' => 200.00,
                'credit_card_revenue' => 3500.00,
                'cash_revenue' => 1000.00,
                'check_revenue' => 500.00,
                'unique_customers' => 80
            ],
            'monthly_trends' => []
        ];
        
        $expectedProfit = [
            'summary' => [
                'total_sales' => 100,
                'total_revenue' => 5000.00,
                'total_cogs' => 2000.00,
                'gross_profit' => 3000.00,
                'gross_margin_percentage' => 60.00,
                'average_revenue' => 50.00,
                'average_cogs' => 20.00,
                'average_profit' => 30.00
            ],
            'by_product' => []
        ];
        
        $expectedCashFlow = [
            'daily_flow' => [],
            'weekly_flow' => []
        ];
        
        $expectedFinancialHealth = [
            'revenue' => ['total_revenue' => 5000.00],
            'expenses' => ['total_expenses' => 2000.00],
            'cash_flow' => ['net_cash_flow' => 3000.00],
            'health_score' => [
                'overall_score' => 85,
                'profit_margin' => 60.0,
                'cash_flow_ratio' => 60.0,
                'sales_efficiency' => 50.0,
                'profit_score' => 85,
                'cash_flow_score' => 85,
                'efficiency_score' => 85,
                'rating' => 'Excellent'
            ]
        ];
        
        // Mock financial analytics
        $this->mockFinancialAnalytics->expects($this->exactly(5))
            ->method('getRevenueSummary')
            ->willReturn($expectedRevenue);
            
        $this->mockFinancialAnalytics->expects($this->once())
            ->method('getProfitAnalysis')
            ->willReturn($expectedProfit);
            
        $this->mockFinancialAnalytics->expects($this->once())
            ->method('getCashFlow')
            ->willReturn($expectedCashFlow);
            
        $this->mockFinancialAnalytics->expects($this->once())
            ->method('getFinancialHealth')
            ->willReturn($expectedFinancialHealth);
        
        // Act
        $result = $this->biService->getFinancialAnalytics($filters);
        
        // Assert
        $this->assertEquals($expectedRevenue, $result['revenue_summary']);
        $this->assertEquals($expectedProfit, $result['profit_analysis']);
        $this->assertEquals($expectedCashFlow, $result['cash_flow']);
        $this->assertEquals($expectedFinancialHealth, $result['financial_health']);
        $this->assertEquals($filters, $result['filters']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * @test
     */
    public function getPerformanceMetricsReturnsCorrectData(): void
    {
        // Arrange
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $expectedSalesPerformance = [
            'total_transactions' => 100,
            'total_revenue' => 5000.00,
            'average_transaction' => 50.00,
            'unique_customers' => 80,
            'unique_locations' => 2,
            'credit_card_sales' => 3500.00,
            'cash_sales' => 1000.00
        ];
        
        $expectedCustomerSatisfaction = [
            'overall' => [
                'average_rating' => 4.5,
                'total_responses' => 100,
                'positive_responses' => 80,
                'negative_responses' => 20,
                'positive_percentage' => 80.0
            ],
            'by_category' => []
        ];
        
        $expectedOperationalEfficiency = [
            'overall_efficiency' => [
                'average_turnover' => 5.0,
                'total_items' => 100,
                'items_with_stock' => 95,
                'items_with_sales' => 80,
                'turnover_ratio' => 5.0,
                'stock_utilization' => 84.21
            ],
            'by_category' => []
        ];
        
        $expectedFinancialPerformance = [
            'total_sales' => 100,
            'total_revenue' => 5000.00,
            'average_sale' => 50.00,
            'unique_customers' => 80,
            'unique_locations' => 2,
            'credit_card_sales' => 3500.00,
            'cash_sales' => 1000.00
        ];
        
        $expectedSystemPerformance = [
            'response_time' => 0.45,
            'error_rate' => 0.1,
            'uptime' => 99.99,
            'throughput' => 120,
            'memory_usage' => ['used' => 0, 'peak' => 0, 'limit' => '128M'],
            'disk_usage' => ['total' => 0, 'used' => 0, 'free' => 0, 'percentage' => 0]
        ];
        
        // Mock analytics services
        $this->mockSalesAnalytics->expects($this->once())
            ->method('getPerformanceMetrics')
            ->willReturn(['sales_performance' => $expectedSalesPerformance]);
            
        $this->mockCustomerAnalytics->expects($this->once())
            ->method('getSatisfactionMetrics')
            ->willReturn($expectedCustomerSatisfaction);
            
        $this->mockInventoryAnalytics->expects($this->once())
            ->method('getEfficiencyMetrics')
            ->willReturn($expectedOperationalEfficiency);
            
        $this->mockFinancialAnalytics->expects($this->once())
            ->method('getPerformanceMetrics')
            ->willReturn(['overall_metrics' => $expectedFinancialPerformance]);
        
        // Act
        $result = $this->biService->getPerformanceMetrics($filters);
        
        // Assert
        $this->assertEquals($expectedSalesPerformance, $result['sales_performance']);
        $this->assertEquals($expectedCustomerSatisfaction, $result['customer_satisfaction']);
        $this->assertEquals($expectedOperationalEfficiency, $result['operational_efficiency']);
        $this->assertEquals($expectedFinancialPerformance, $result['financial_performance']);
        $this->assertEquals($expectedSystemPerformance, $result['system_performance']);
        $this->assertEquals($filters, $result['filters']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * @test
     */
    public function generateCustomReportReturnsCorrectData(): void
    {
        // Arrange
        $reportData = [
            'report_type' => 'sales',
            'user_id' => 1,
            'filters' => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31']
        ];
        
        $expectedReport = [
            'report_type' => 'sales',
            'generated_at' => '2024-01-01 12:00:00',
            'execution_time' => 0.5,
            'filters' => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'],
            'data' => [
                'summary' => ['total_transactions' => 100],
                'by_payment_method' => [],
                'by_location' => []
            ]
        ];
        
        // Mock report generator
        $this->mockReportGenerator->expects($this->once())
            ->method('generateReport')
            ->willReturn($expectedReport);
        
        // Act
        $result = $this->biService->generateCustomReport($reportData);
        
        // Assert
        $this->assertEquals($expectedReport, $result);
    }

    /**
     * @test
     */
    public function generateCustomReportHandlesInvalidReportType(): void
    {
        $this->expectException(ReportGenerationException::class);
        $this->expectExceptionMessage("Invalid report type");
        
        // Arrange
        $reportData = [
            'report_type' => 'invalid_type',
            'user_id' => 1,
            'filters' => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31']
        ];
        
        // Act
        $this->biService->generateCustomReport($reportData);
    }

    /**
     * @test
     */
    public function generateCustomReportHandlesMissingFilters(): void
    {
        $this->expectException(ReportGenerationException::class);
        $this->expectExceptionMessage("Report filters are required");
        
        // Arrange
        $reportData = [
            'report_type' => 'sales',
            'user_id' => 1,
            'filters' => []
        ];
        
        // Act
        $this->biService->generateCustomReport($reportData);
    }

    /**
     * @test
     */
    public function generateCustomReportHandlesReportGenerationError(): void
    {
        $this->expectException(ReportGenerationException::class);
        $this->expectExceptionMessage("Failed to generate custom report: Database error");
        
        // Arrange
        $reportData = [
            'report_type' => 'sales',
            'user_id' => 1,
            'filters' => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31']
        ];
        
        // Mock report generator throws exception
        $this->mockReportGenerator->expects($this->once())
            ->method('generateReport')
            ->willThrowException(new \Exception("Database error"));
        
        // Act
        $this->biService->generateCustomReport($reportData);
    }

    /**
     * @test
     */
    public function validateFiltersHandlesInvalidDateRange(): void
    {
        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessage("Start date cannot be after end date");
        
        // Arrange
        $filters = [
            'start_date' => '2024-12-31',
            'end_date' => '2024-01-01'
        ];
        
        // Act
        $result = $this->biService->getSalesAnalytics($filters);
    }

    /**
     * @test
     */
    public function validateFiltersHandlesInvalidLocationId(): void
    {
        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessage("Location ID must be a string");
        
        // Arrange
        $filters = [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'location_id' => 123 // Should be string
        ];
        
        // Act
        $this->biService->getSalesAnalytics($filters);
    }

    /**
     * @test
     */
    public function calculateAverageResponseTimeReturnsValidValue(): void
    {
        // This test would normally mock the actual system monitoring
        // For now, we test that the method returns a float
        $reflection = new \ReflectionClass($this->biService);
        $method = $reflection->getMethod('calculateAverageResponseTime');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->biService);
        
        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * @test
     */
    public function calculateErrorRateReturnsValidValue(): void
    {
        // This test would normally mock the actual error monitoring
        // For now, we test that the method returns a float
        $reflection = new \ReflectionClass($this->biService);
        $method = $reflection->getMethod('calculateErrorRate');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->biService);
        
        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * @test
     */
    public function calculateUptimeReturnsValidValue(): void
    {
        // This test would normally mock the actual uptime monitoring
        // For now, we test that the method returns a float
        $reflection = new \ReflectionClass($this->biService);
        $method = $reflection->getMethod('calculateUptime');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->biService);
        
        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(0, $result);
        $this->assertLessThanOrEqual(100, $result);
    }

    /**
     * @test
     */
    public function calculateThroughputReturnsValidValue(): void
    {
        // This test would normally mock the actual throughput monitoring
        // For now, we test that the method returns an int
        $reflection = new \ReflectionClass($this->biService);
        $method = $reflection->getMethod('calculateThroughput');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->biService);
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }
}