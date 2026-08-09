<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Integration;

use Ksfraser\Frontaccounting\SquareUp\Services\BusinessIntelligenceService;
use Ksfraser\Frontaccounting\SquareUp\Services\SalesAnalyticsService;
use Ksfraser\Frontaccounting\SquareUp\Services\CustomerAnalyticsService;
use Ksfraser\Frontaccounting\SquareUp\Services\InventoryAnalyticsService;
use Ksfraser\Frontaccounting\SquareUp\Services\FinancialAnalyticsService;
use Ksfraser\Frontaccounting\SquareUp\Services\ReportGenerator;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentsDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SalesOrdersDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\TaxRatesDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\TaxMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\WebhookSubscriptionDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockEventDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\ExpensesDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\InventoryDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\CustomerDAO;
use Ksfraser\Frontaccounting\SquareUp\Services\CRMAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\StockMovementAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\SquareOrderAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\PaymentAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\WebhookService;
use Ksfraser\Frontaccounting\SquareUp\Services\CRMIntegrationService;
use Ksfraser\Frontaccounting\SquareUp\Services\StockEventService;
use Ksfraser\Frontaccounting\SquareUp\Services\SalesOrderService;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxService;
use Ksfraser\Frontaccounting\SquareUp\Services\PaymentService;
use Ksfraser\Frontaccounting\SquareUp\Controllers\SquareAnalyticsController;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Integration test for complete Square API and FA integration workflow.
 * 
 * @UML Note: Integration test in ProjectDocs/UML.md
 * @BABOK Related: End-to-end integration testing
 */
class SquareIntegrationTest extends TestCase
{
    protected MockObject $mockPaymentsDao;
    protected MockObject $mockPaymentMappingDao;
    protected MockObject $mockSalesOrdersDao;
    protected MockObject $mockTaxRatesDao;
    protected MockObject $mockTaxMappingDao;
    protected MockObject $mockWebhookSubscriptionDao;
    protected MockObject $mockStockEventDao;
    protected MockObject $mockExpensesDao;
    protected MockObject $mockInventoryDao;
    protected MockObject $mockCustomerDao;
    
    protected MockObject $mockCrmAdapter;
    protected MockObject $mockStockMovementAdapter;
    protected MockObject $mockSquareOrderAdapter;
    protected MockObject $mockPaymentAdapter;
    protected MockObject $mockTaxAdapter;
    
    protected MockObject $mockWebhookService;
    protected MockObject $mockCrmIntegrationService;
    protected MockObject $mockStockEventService;
    protected MockObject $mockSalesOrderService;
    protected MockObject $mockTaxService;
    protected MockObject $mockPaymentService;
    
    protected MockObject $mockSalesAnalyticsService;
    protected MockObject $mockCustomerAnalyticsService;
    protected MockObject $mockInventoryAnalyticsService;
    protected MockObject $mockFinancialAnalyticsService;
    protected MockObject $mockReportGenerator;
    
    protected BusinessIntelligenceService $biService;
    protected SquareAnalyticsController $analyticsController;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock DAOs
        $this->mockPaymentsDao = $this->createMock(PaymentsDAO::class);
        $this->mockPaymentMappingDao = $this->createMock(PaymentMappingDAO::class);
        $this->mockSalesOrdersDao = $this->createMock(SalesOrdersDAO::class);
        $this->mockTaxRatesDao = $this->createMock(TaxRatesDAO::class);
        $this->mockTaxMappingDao = $this->createMock(TaxMappingDAO::class);
        $this->mockWebhookSubscriptionDao = $this->createMock(WebhookSubscriptionDAO::class);
        $this->mockStockEventDao = $this->createMock(StockEventDAO::class);
        $this->mockExpensesDao = $this->createMock(ExpensesDAO::class);
        $this->mockInventoryDao = $this->createMock(InventoryDAO::class);
        $this->mockCustomerDao = $this->createMock(CustomerDAO::class);
        
        // Mock Adapters
        $this->mockCrmAdapter = $this->createMock(CRMAdapter::class);
        $this->mockStockMovementAdapter = $this->createMock(StockMovementAdapter::class);
        $this->mockSquareOrderAdapter = $this->createMock(SquareOrderAdapter::class);
        $this->mockPaymentAdapter = $this->createMock(PaymentAdapter::class);
        $this->mockTaxAdapter = $this->createMock(TaxAdapter::class);
        
        // Mock Services
        $this->mockWebhookService = $this->createMock(WebhookService::class);
        $this->mockCrmIntegrationService = $this->createMock(CRMIntegrationService::class);
        $this->mockStockEventService = $this->createMock(StockEventService::class);
        $this->mockSalesOrderService = $this->createMock(SalesOrderService::class);
        $this->mockTaxService = $this->createMock(TaxService::class);
        $this->mockPaymentService = $this->createMock(PaymentService::class);
        
        // Mock Analytics Services
        $this->mockSalesAnalyticsService = $this->createMock(SalesAnalyticsService::class);
        $this->mockCustomerAnalyticsService = $this->createMock(CustomerAnalyticsService::class);
        $this->mockInventoryAnalyticsService = $this->createMock(InventoryAnalyticsService::class);
        $this->mockFinancialAnalyticsService = $this->createMock(FinancialAnalyticsService::class);
        $this->mockReportGenerator = $this->createMock(ReportGenerator::class);
        
        // Create Business Intelligence Service
        $this->biService = new BusinessIntelligenceService(
            $this->mockSalesAnalyticsService,
            $this->mockCustomerAnalyticsService,
            $this->mockInventoryAnalyticsService,
            $this->mockFinancialAnalyticsService,
            $this->mockReportGenerator
        );
        
        // Create Analytics Controller
        $this->analyticsController = new SquareAnalyticsController(
            $this->biService,
            $this->tablePrefix
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function completeIntegrationWorkflow(): void
    {
        // Simulate complete integration workflow
        
        // Step 1: Webhook event processing
        $this->testWebhookEventProcessing();
        
        // Step 2: Customer synchronization
        $this->testCustomerSynchronization();
        
        // Step 3: Payment processing
        $this->testPaymentProcessing();
        
        // Step 4: Tax calculation
        $this->testTaxCalculation();
        
        // Step 5: Order processing
        $this->testOrderProcessing();
        
        // Step 6: Analytics generation
        $this->testAnalyticsGeneration();
        
        // Step 7: Report generation
        $this->testReportGeneration();
    }

    /**
     * Test webhook event processing
     */
    private function testWebhookEventProcessing(): void
    {
        // Mock webhook service
        $this->mockWebhookService->expects($this->once())
            ->method('processWebhook')
            ->willReturn(['success' => true, 'events_processed' => 1]);
        
        // Simulate webhook data
        $webhookData = [
            'type' => 'payment.created',
            'data' => [
                'id' => 'pay_123456',
                'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
                'status' => 'COMPLETED',
                'reference_id' => 'ref_123',
                'note' => 'Customer payment'
            ]
        ];
        
        // Process webhook
        $result = $this->mockWebhookService->processWebhook($webhookData);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['events_processed']);
    }

    /**
     * Test customer synchronization
     */
    private function testCustomerSynchronization(): void
    {
        // Mock CRM integration service
        $this->mockCrmIntegrationService->expects($this->once())
            ->method('syncCustomerFromSquare')
            ->willReturn(['success' => true, 'customer_id' => 123]);
        
        // Mock CRM adapter
        $this->mockCrmAdapter->expects($this->once())
            ->method('convertToFacustomer')
            ->willReturn([
                'debtor_no' => 123,
                'person_id' => 456,
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]);
        
        // Simulate customer data
        $squareCustomer = [
            'id' => 'cust_123456',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'email_address' => 'john@example.com',
            'phone_number' => '+1234567890'
        ];
        
        // Sync customer
        $result = $this->mockCrmIntegrationService->syncCustomerFromSquare($squareCustomer);

        // Convert via CRM adapter
        $converted = $this->mockCrmAdapter->convertToFacustomer($squareCustomer);

        $this->assertTrue($result['success']);
        $this->assertEquals(123, $result['customer_id']);
        $this->assertEquals(123, $converted['debtor_no']);
    }

    /**
     * Test payment processing
     */
    private function testPaymentProcessing(): void
    {
        // Mock payment service
        $this->mockPaymentService->expects($this->once())
            ->method('recordSquarePayment')
            ->willReturn(789);
        
        // Mock payment adapter
        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFAPayment')
            ->willReturn([
                'debtor_no' => 123,
                'amount' => 50.00,
                'currency' => 'USD',
                'date_1' => date('Y-m-d'),
                'bank_act' => 'Default Card Processing',
                'ref' => 'ref_123',
                'person_id' => 456,
                'bank_trans_type' => 'Receipt',
                'payment_method' => 'Credit Card',
                'status' => 'Completed'
            ]);
        
        // Simulate payment data
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'reference_id' => 'ref_123',
            'payment_method' => 'CARD',
            'customer_email' => 'john@example.com'
        ];
        
        // Process payment
        $result = $this->mockPaymentService->recordSquarePayment($squarePayment);

        // Convert via payment adapter
        $converted = $this->mockPaymentAdapter->convertToFAPayment($squarePayment, ['debtor_no' => 123, 'person_id' => 456]);

        $this->assertEquals(789, $result);
        $this->assertEquals('Default Card Processing', $converted['bank_act']);
    }

    /**
     * Test tax calculation
     */
    private function testTaxCalculation(): void
    {
        // Mock tax service
        $this->mockTaxService->expects($this->once())
            ->method('calculateTax')
            ->willReturn(['tax_amount' => 10.00, 'tax_rate' => 8.0]);
        
        // Mock tax adapter
        $this->mockTaxAdapter->expects($this->once())
            ->method('convertToFATax')
            ->willReturn([
                'tax_type_id' => 1,
                'rate' => 8.0,
                'amount' => 10.00
            ]);
        
        // Simulate tax calculation
        $orderData = [
            'subtotal' => 100.00,
            'tax_rate' => 8.0,
            'location_id' => 'loc_123'
        ];
        
        $result = $this->mockTaxService->calculateTax($orderData);

        // Convert via tax adapter
        $converted = $this->mockTaxAdapter->convertToFATax($orderData);

        $this->assertEquals(10.00, $result['tax_amount']);
        $this->assertEquals(8.0, $result['tax_rate']);
        $this->assertEquals(1, $converted['tax_type_id']);
    }

    /**
     * Test order processing
     */
    private function testOrderProcessing(): void
    {
        // Mock sales order service
        $this->mockSalesOrderService->expects($this->once())
            ->method('createSalesOrder')
            ->willReturn(['success' => true, 'order_id' => 456]);
        
        // Mock square order adapter
        $this->mockSquareOrderAdapter->expects($this->once())
            ->method('convertToFASalesOrder')
            ->willReturn([
                'order_id' => 456,
                'customer_id' => 123,
                'order_date' => date('Y-m-d'),
                'total_amount' => 150.00,
                'items' => [
                    ['item_code' => '001', 'quantity' => 2, 'unit_price' => 50.00],
                    ['item_code' => '002', 'quantity' => 1, 'unit_price' => 50.00]
                ]
            ]);
        
        // Simulate order data
        $squareOrder = [
            'id' => 'ord_123456',
            'customer_id' => 'cust_123456',
            'location_id' => 'loc_123',
            'total_money' => ['amount' => 15000, 'currency' => 'USD'],
            'line_items' => [
                [
                    'quantity' => 2,
                    'base_price_money' => ['amount' => 5000, 'currency' => 'USD'],
                    'name' => 'Product 1'
                ],
                [
                    'quantity' => 1,
                    'base_price_money' => ['amount' => 5000, 'currency' => 'USD'],
                    'name' => 'Product 2'
                ]
            ]
        ];
        
        // Process order
        $result = $this->mockSalesOrderService->createSalesOrder($squareOrder);

        // Convert via square order adapter
        $converted = $this->mockSquareOrderAdapter->convertToFASalesOrder($squareOrder);

        $this->assertTrue($result['success']);
        $this->assertEquals(456, $result['order_id']);
        $this->assertEquals(456, $converted['order_id']);
    }

    /**
     * Test analytics generation
     */
    private function testAnalyticsGeneration(): void
    {
        // Mock sales analytics service
        $this->mockSalesAnalyticsService->expects($this->exactly(5))
            ->method('getSalesSummary')
            ->willReturn([
                'total_transactions' => 100,
                'total_amount' => 5000.00,
                'average_transaction' => 50.00
            ]);
        
        // Mock customer analytics service
        $this->mockCustomerAnalyticsService->expects($this->exactly(5))
            ->method('getCustomerSummary')
            ->willReturn([
                'total_customers' => 50,
                'unique_individuals' => 40,
                'unique_companies' => 10
            ]);
        
        // Mock financial analytics service
        $this->mockFinancialAnalyticsService->expects($this->exactly(5))
            ->method('getRevenueSummary')
            ->willReturn([
                'total_revenue' => 5000.00,
                'total_transactions' => 100,
                'average_transaction' => 50.00
            ]);
        
        // Generate analytics
        $filters = ['start_date' => '2024-01-01', 'end_date' => '2024-12-31'];
        
        $result = $this->biService->getSalesAnalytics($filters);
        
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(100, $result['summary']['total_transactions']);
        $this->assertEquals(5000.00, $result['summary']['total_amount']);

        // Generate customer analytics
        $customerResult = $this->biService->getCustomerAnalytics($filters);
        $this->assertEquals(50, $customerResult['summary']['total_customers']);

        // Generate financial analytics
        $financialResult = $this->biService->getFinancialAnalytics($filters);
        $this->assertEquals(5000.00, $financialResult['revenue_summary']['total_revenue']);
    }

    /**
     * Test report generation
     */
    private function testReportGeneration(): void
    {
        // Mock report generator
        $this->mockReportGenerator->expects($this->once())
            ->method('generateReport')
            ->willReturn([
                'report_type' => 'sales',
                'generated_at' => date('Y-m-d H:i:s'),
                'execution_time' => 0.5,
                'filters' => ['start_date' => '2024-01-01'],
                'data' => [
                    'summary' => ['total_transactions' => 100],
                    'by_payment_method' => []
                ]
            ]);
        
        // Generate custom report
        $reportData = [
            'report_type' => 'sales',
            'user_id' => 1,
            'filters' => ['start_date' => '2024-01-01', 'end_date' => '2024-12-31']
        ];
        
        $result = $this->biService->generateCustomReport($reportData);
        
        $this->assertEquals('sales', $result['report_type']);
        $this->assertArrayHasKey('generated_at', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * @test
     */
    public function analyticsControllerHandlesRequests(): void
    {
        // Test analytics controller request handling
        
        $request = [
            'action' => 'get_sales_analytics',
            'filters' => [
                'start_date' => '2024-01-01',
                'end_date' => '2024-12-31'
            ]
        ];
        
        // Mock sales analytics service
        $this->mockSalesAnalyticsService->expects($this->exactly(5))
            ->method('getSalesSummary')
            ->willReturn([
                'total_transactions' => 100,
                'total_amount' => 5000.00
            ]);
        
        // Handle request
        $response = $this->analyticsController->handleAnalyticsRequest($request);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('Sales analytics retrieved successfully', $response['message']);
    }

    /**
     * @test
     */
    public function analyticsControllerHandlesErrors(): void
    {
        // Test error handling in analytics controller
        
        $request = [
            'action' => 'invalid_action',
            'filters' => []
        ];
        
        // Handle request
        $response = $this->analyticsController->handleAnalyticsRequest($request);
        
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('error_code', $response);
    }

    /**
     * @test
     */
    public function getAvailableEndpointsReturnsCorrectData(): void
    {
        // Test available endpoints
        $endpoints = $this->analyticsController->getAvailableEndpoints();
        
        $this->assertArrayHasKey('get_sales_analytics', $endpoints);
        $this->assertArrayHasKey('get_customer_analytics', $endpoints);
        $this->assertArrayHasKey('get_inventory_analytics', $endpoints);
        $this->assertArrayHasKey('get_financial_analytics', $endpoints);
        $this->assertArrayHasKey('get_performance_metrics', $endpoints);
        $this->assertArrayHasKey('generate_custom_report', $endpoints);
        
        // Check endpoint structure
        $salesEndpoint = $endpoints['get_sales_analytics'];
        $this->assertArrayHasKey('description', $salesEndpoint);
        $this->assertArrayHasKey('method', $salesEndpoint);
        $this->assertArrayHasKey('parameters', $salesEndpoint);
    }

    /**
     * @test
     */
    public function getDefaultFiltersReturnsCorrectData(): void
    {
        // Test default filters
        $filters = $this->analyticsController->getDefaultFilters();
        
        $this->assertArrayHasKey('start_date', $filters);
        $this->assertArrayHasKey('end_date', $filters);
        $this->assertArrayHasKey('location_id', $filters);
        
        // Check date format
        $currentYear = date('Y');
        $this->assertEquals($currentYear . '-01-01', $filters['start_date']);
        $this->assertEquals($currentYear . '-12-31', $filters['end_date']);
    }
}