<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit\Services;

use Ksfraser\Frontaccounting\SquareUp\Services\SalesOrderService;
use Ksfraser\Frontaccounting\SquareUp\DAO\SalesOrdersDAO;
use Ksfraser\Frontaccounting\SquareUp\Services\SquareOrderAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxService;
use Ksfraser\Frontaccounting\SquareUp\Services\CustomerService;
use Ksfraser\Frontaccounting\SquareUp\Services\PaymentService;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SalesOrderException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for SalesOrderService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-02.01 through FR-02.07 - Order Management
 */
class SalesOrderServiceTest extends TestCase
{
    protected MockObject $mockSalesOrdersDao;
    protected MockObject $mockOrderAdapter;
    protected MockObject $mockTaxService;
    protected MockObject $mockCustomerService;
    protected MockObject $mockPaymentService;
    protected SalesOrderService $salesOrderService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock DAOs
        $this->mockSalesOrdersDao = $this->createMock(SalesOrdersDAO::class);
        $this->mockOrderAdapter = $this->createMock(SquareOrderAdapter::class);
        $this->mockTaxService = $this->createMock(TaxService::class);
        $this->mockCustomerService = $this->createMock(CustomerService::class);
        $this->mockPaymentService = $this->createMock(PaymentService::class);
        
        // Create sales order service
        $this->salesOrderService = new SalesOrderService(
            $this->mockSalesOrdersDao,
            $this->mockOrderAdapter,
            $this->mockTaxService,
            $this->mockCustomerService,
            $this->mockPaymentService
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canCreateSalesOrderFromSquareSuccessfully(): void
    {
        // Arrange
        $squareOrder = [
            'id' => 'ord_123456',
            'reference' => 'Test Order',
            'note' => 'Customer note',
            'customer' => [
                'id' => 'cus_123',
                'email_address' => 'test@example.com',
                'given_name' => 'John',
                'family_name' => 'Doe'
            ],
            'line_items' => [
                [
                    'item_id' => 'item_1',
                    'name' => 'Product 1',
                    'quantity' => 2,
                    'base_price_money' => ['amount' => 2000, 'currency' => 'USD'],
                    'sequence_number' => 1
                ],
                [
                    'item_id' => 'item_2',
                    'name' => 'Product 2',
                    'quantity' => 1,
                    'base_price_money' => ['amount' => 1500, 'currency' => 'USD'],
                    'sequence_number' => 2
                ]
            ],
            'created_at' => '2023-01-01T10:00:00Z'
        ];
        
        $customer = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'test@example.com'
        ];
        
        $taxData = [
            'tax_included' => false,
            'total' => 35.00
        ];
        
        $faOrder = [
            'debtor_no' => 123,
            'type' => 10,
            'order_date' => '2023-01-01',
            'due_date' => '2023-01-31',
            'order_ref' => 'SQ-ord_123456',
            'reference' => 'Test Order',
            'tax_included' => false,
            'total' => 35.00,
            'notes' => 'Customer note'
        ];
        
        $faItem1 = [
            'order_id' => 456,
            'item_code' => 'SQ-item_1',
            'description' => 'Product 1',
            'quantity' => 2,
            'unit_price' => 20.00,
            'line_total' => 40.00,
            'tax_amount' => 0,
            'notes' => '',
            'sequence' => 1
        ];
        
        $faItem2 = [
            'order_id' => 456,
            'item_code' => 'SQ-item_2',
            'description' => 'Product 2',
            'quantity' => 1,
            'unit_price' => 15.00,
            'line_total' => 15.00,
            'tax_amount' => 0,
            'notes' => '',
            'sequence' => 2
        ];
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('syncCustomerToSquare')
            ->with($squareOrder['customer'])
            ->willReturn($customer);
        
        // Mock tax service
        $this->mockTaxService->expects($this->once())
            ->method('calculateSquareTaxes')
            ->with($squareOrder)
            ->willReturn($taxData);
        
        // Mock order adapter
        $this->mockOrderAdapter->expects($this->once())
            ->method('convertToFAOrder')
            ->with($squareOrder, $customer, $taxData)
            ->willReturn($faOrder);
        
        // Mock order DAO insert
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('insertOrder')
            ->with($faOrder)
            ->willReturn(456);
        
        // Mock order DAO insert items
        $this->mockSalesOrdersDao->expects($this->exactly(2))
            ->method('insertOrderItem')
            ->withConsecutive(
                [$faItem1],
                [$faItem2]
            )
            ->willReturn(1, 2);
        
        // Mock order DAO update mapping
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('updateMappingBySquareId')
            ->with('ord_123456', ['fa_order_id' => 456])
            ->willReturn(1);
        
        // Mock order DAO log event
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('logOrderEvent')
            ->with($this->callback(function($data) {
                return $data['fa_order_id'] === 456 &&
                       $data['square_order_id'] === 'ord_123456' &&
                       $data['event_type'] === 'created';
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->salesOrderService->createSalesOrderFromSquare($squareOrder);
        
        // Assert
        $this->assertEquals(456, $result['order_id']);
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('SQ-ord_123456', $result['order_ref']);
    }

    /**
     * @test
     */
    public function createSalesOrderFromSquareFailsWithInvalidOrder(): void
    {
        $this->expectException(SalesOrderException::class);
        $this->expectExceptionMessage("Square order ID is required");
        
        // Arrange
        $squareOrder = [
            // Missing id
            'reference' => 'Test Order',
            'line_items' => []
        ];
        
        // Act
        $this->salesOrderService->createSalesOrderFromSquare($squareOrder);
    }

    /**
     * @test
     */
    public function createSalesOrderFromSquareFailsWithMissingCustomer(): void
    {
        $this->expectException(SalesOrderException::class);
        $this->expectExceptionMessage("Customer information is required");
        
        // Arrange
        $squareOrder = [
            'id' => 'ord_123456',
            'reference' => 'Test Order',
            'line_items' => [
                [
                    'item_id' => 'item_1',
                    'quantity' => 2,
                    'base_price_money' => ['amount' => 2000, 'currency' => 'USD']
                ]
            ]
            // Missing customer
        ];
        
        // Act
        $this->salesOrderService->createSalesOrderFromSquare($squareOrder);
    }

    /**
     * @test
     */
    public function canUpdateSalesOrderSuccessfully(): void
    {
        // Arrange
        $orderId = 456;
        $updates = [
            'reference' => 'Updated Order',
            'notes' => 'Updated notes',
            'total' => 40.00
        ];
        
        $order = [
            'order_id' => 456,
            'debtor_no' => 123,
            'reference' => 'Test Order',
            'notes' => 'Customer note'
        ];
        
        // Mock order DAO get order
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getOrder')
            ->with($orderId)
            ->willReturn($order);
        
        // Mock order DAO update
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('updateOrder')
            ->with($orderId, $updates)
            ->willReturn(true);
        
        // Mock order DAO log event
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('logOrderEvent')
            ->with($this->callback(function($data) {
                return $data['fa_order_id'] === 456 &&
                       $data['event_type'] === 'updated';
            }))
            ->willReturn(1);
        
        // Act
        $this->salesOrderService->updateSalesOrder($orderId, $updates);
        
        // Assert - should not throw exception
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function updateSalesOrderFailsWithInvalidField(): void
    {
        $this->expectException(SalesOrderException::class);
        $this->expectExceptionMessage("Invalid update field: invalid_field");
        
        // Arrange
        $orderId = 456;
        $updates = [
            'reference' => 'Updated Order',
            'invalid_field' => 'bad_value' // Invalid field
        ];
        
        // Act
        $this->salesOrderService->updateSalesOrder($orderId, $updates);
    }

    /**
     * @test
     */
    public function canCreateSalesCreditNoteSuccessfully(): void
    {
        // Arrange
        $originalOrderId = 456;
        $reason = 'Customer request for refund';
        
        $originalOrder = [
            'order_id' => 456,
            'debtor_no' => 123,
            'order_ref' => 'SQ-ord_123456',
            'type' => 10,
            'tax_included' => false,
            'total' => 35.00,
            'notes' => 'Original order notes'
        ];
        
        $customer = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'test@example.com'
        ];
        
        $originalItems = [
            [
                'item_id' => 1,
                'item_code' => 'SQ-item_1',
                'description' => 'Product 1',
                'quantity' => 2,
                'unit_price' => 20.00,
                'line_total' => 40.00,
                'tax_amount' => 0,
                'notes' => ''
            ]
        ];
        
        $creditNote = [
            'debtor_no' => 123,
            'type' => 11,
            'order_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+30 days')),
            'order_ref' => 'CN-' . uniqid(),
            'reference' => 'Customer request for refund',
            'tax_included' => false,
            'total' => -40.00,
            'notes' => 'Square refund: Customer request for refund',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $creditItem = [
            'order_id' => 789,
            'item_code' => 'SQ-item_1',
            'description' => 'Product 1',
            'quantity' => -2,
            'unit_price' => 20.00,
            'line_total' => -40.00,
            'tax_amount' => 0,
            'discount_amount' => 0
        ];
        
        // Mock order DAO get order
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getOrder')
            ->with($originalOrderId)
            ->willReturn($originalOrder);
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('getCustomerByDebtorNo')
            ->with(123)
            ->willReturn($customer);
        
        // Mock order DAO insert credit note
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('insertOrder')
            ->with($creditNote)
            ->willReturn(789);
        
        // Mock order DAO get order items
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getOrderItems')
            ->with($originalOrderId)
            ->willReturn($originalItems);
        
        // Mock order DAO insert credit note item
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('insertOrderItem')
            ->with($creditItem)
            ->willReturn(3);
        
        // Mock order DAO update credit note total
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('updateOrder')
            ->with(789, ['total' => -40.00])
            ->willReturn(true);
        
        // Mock order DAO update mapping
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('updateMappingByCreditNoteId')
            ->with(789, ['original_order_id' => 456])
            ->willReturn(1);
        
        // Mock order DAO log event
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('logOrderEvent')
            ->with($this->callback(function($data) {
                return $data['fa_order_id'] === 789 &&
                       $data['original_order_id'] === 456 &&
                       $data['event_type'] === 'credit_note_created';
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->salesOrderService->createSalesCreditNote($originalOrderId, $reason);
        
        // Assert
        $this->assertEquals(789, $result['order_id']);
        $this->assertEquals(123, $result['debtor_no']);
        $this->assertEquals('CN-', substr($result['order_ref'], 0, 3));
    }

    /**
     * @test
     */
    public function createSalesCreditNoteFailsWithMissingOriginalOrder(): void
    {
        $this->expectException(SalesOrderException::class);
        $this->expectExceptionMessage("Original order not found: 999");
        
        // Arrange
        $originalOrderId = 999;
        $reason = 'Customer request for refund';
        
        // Mock order DAO returns null
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getOrder')
            ->with($originalOrderId)
            ->willReturn(null);
        
        // Act
        $this->salesOrderService->createSalesCreditNote($originalOrderId, $reason);
    }

    /**
     * @test
     */
    public function canGetSalesOrderBySquareId(): void
    {
        // Arrange
        $squareOrderId = 'ord_123456';
        $expectedOrder = [
            'order_id' => 456,
            'square_order_id' => 'ord_123456',
            'debtor_no' => 123,
            'order_ref' => 'SQ-ord_123456'
        ];
        
        // Mock order DAO
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getBySquareId')
            ->with($squareOrderId)
            ->willReturn($expectedOrder);
        
        // Act
        $result = $this->salesOrderService->getSalesOrderBySquareId($squareOrderId);
        
        // Assert
        $this->assertEquals($expectedOrder, $result);
    }

    /**
     * @test
     */
    public function getSalesOrderBySquareIdReturnsNullWhenNotFound(): void
    {
        // Arrange
        $squareOrderId = 'ord_123456';
        
        // Mock order DAO returns null
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getBySquareId')
            ->with($squareOrderId)
            ->willReturn(null);
        
        // Act
        $result = $this->salesOrderService->getSalesOrderBySquareId($squareOrderId);
        
        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function canGetOrderStatistics(): void
    {
        // Arrange
        $expectedStats = [
            'total_orders' => 100,
            'by_type' => [10 => 80, 11 => 20],
            'recent_orders' => 15,
            'event_statistics' => ['created' => 80, 'updated' => 20]
        ];
        
        // Mock order DAO
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('getOrderStatistics')
            ->willReturn($expectedStats);
        
        // Act
        $result = $this->salesOrderService->getOrderStatistics();
        
        // Assert
        $this->assertEquals($expectedStats, $result);
    }

    /**
     * @test
     */
    public function createSalesOrderFromSquareHandlesDaoError(): void
    {
        $this->expectException(SalesOrderException::class);
        $this->expectExceptionMessage("Failed to create sales order: Database error");
        
        // Arrange
        $squareOrder = [
            'id' => 'ord_123456',
            'reference' => 'Test Order',
            'customer' => [
                'id' => 'cus_123',
                'email_address' => 'test@example.com',
                'given_name' => 'John',
                'family_name' => 'Doe'
            ],
            'line_items' => [
                [
                    'item_id' => 'item_1',
                    'quantity' => 2,
                    'base_price_money' => ['amount' => 2000, 'currency' => 'USD']
                ]
            ]
        ];
        
        $customer = [
            'debtor_no' => 123,
            'name' => 'John Doe',
            'email' => 'test@example.com'
        ];
        
        $taxData = [
            'tax_included' => false,
            'total' => 20.00
        ];
        
        $faOrder = [
            'debtor_no' => 123,
            'type' => 10,
            'order_ref' => 'SQ-ord_123456',
            'total' => 20.00
        ];
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('syncCustomerToSquare')
            ->willReturn($customer);
        
        // Mock tax service
        $this->mockTaxService->expects($this->once())
            ->method('calculateSquareTaxes')
            ->willReturn($taxData);
        
        // Mock order adapter
        $this->mockOrderAdapter->expects($this->once())
            ->method('convertToFAOrder')
            ->willReturn($faOrder);
        
        // Mock order DAO throws exception
        $this->mockSalesOrdersDao->expects($this->once())
            ->method('insertOrder')
            ->willThrowException(new \Exception("Database error"));
        
        // Act
        $this->salesOrderService->createSalesOrderFromSquare($squareOrder);
    }
}