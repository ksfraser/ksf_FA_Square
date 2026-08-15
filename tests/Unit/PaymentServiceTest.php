<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use ksfraser\FrontAccounting\Square\Services\PaymentService;
use ksfraser\FrontAccounting\Square\DAO\PaymentsDAO;
use ksfraser\FrontAccounting\Square\Services\PaymentAdapter;
use ksfraser\FrontAccounting\Square\Services\CustomerService;
use ksfraser\FrontAccounting\Square\DAO\PaymentMappingDAO;
use ksfraser\FrontAccounting\Square\Exceptions\PaymentMappingException;
use ksfraser\FrontAccounting\Square\Exceptions\PaymentProcessingException;
use ksfraser\FrontAccounting\Square\Exceptions\RefundProcessingException;
use ksfraser\FrontAccounting\Square\Exceptions\ReconciliationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for PaymentService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 through FR-07.03 - Payment Management
 */
class PaymentServiceTest extends TestCase
{
    protected MockObject $mockPaymentsDao;
    protected MockObject $mockPaymentAdapter;
    protected MockObject $mockCustomerService;
    protected MockObject $mockPaymentMappingDao;
    protected PaymentService $paymentService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock DAOs
        $this->mockPaymentsDao = $this->createMock(PaymentsDAO::class);
        $this->mockPaymentAdapter = $this->createMock(PaymentAdapter::class);
        $this->mockCustomerService = $this->createMock(CustomerService::class);
        $this->mockPaymentMappingDao = $this->createMock(PaymentMappingDAO::class);
        
        // Create payment service (partial mock for method stubbing)
        $this->paymentService = $this->getMockBuilder(PaymentService::class)
            ->setConstructorArgs([
                $this->mockPaymentsDao,
                $this->mockPaymentAdapter,
                $this->mockCustomerService,
                $this->mockPaymentMappingDao
            ])
            ->onlyMethods(['getPaymentBySquareId'])
            ->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function canRecordSquarePaymentSuccessfully(): void
    {
        // Arrange
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'reference_id' => 'ref_123',
            'note' => 'Customer payment',
            'payment_method' => 'CARD',
            'customer_email' => 'test@example.com'
        ];
        
        $customer = [
            'debtor_no' => 123,
            'person_id' => 456,
            'email' => 'test@example.com'
        ];
        
        $faPayment = [
            'debtor_no' => 123,
            'amount' => 50.00,
            'currency' => 'USD',
            'date_1' => date('Y-m-d'),
            'bank_act' => 'Default Card Processing',
            'ref' => 'ref_123',
            'person_id' => 456,
            'bank_trans_type' => 'Receipt',
            'payment_method' => 'Credit Card',
            'status' => 'Completed',
            'notes' => 'Customer payment',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('matchCustomer')
            ->with('test@example.com')
            ->willReturn($customer);
        
        // Mock payment adapter
        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFAPayment')
            ->with($squarePayment, $customer)
            ->willReturn($faPayment);
        
        // Mock payment DAO insert
        $this->mockPaymentsDao->expects($this->once())
            ->method('insertPayment')
            ->with($faPayment)
            ->willReturn(789);
        
        // Mock payment mapping DAO create
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('createMapping')
            ->with($this->callback(function($data) {
                return $data['square_payment_id'] === 'pay_123456' &&
                       $data['fa_payment_id'] === 789;
            }))
            ->willReturn(1);
        
        // Mock payment DAO log event
        $this->mockPaymentsDao->expects($this->once())
            ->method('logPaymentEvent')
            ->with($this->callback(function($data) {
                return $data['fa_payment_id'] === 789 &&
                       $data['square_payment_id'] === 'pay_123456' &&
                       $data['event_type'] === 'recorded';
            }))
            ->willReturn(2);
        
        // Act
        $result = $this->paymentService->recordSquarePayment($squarePayment);
        
        // Assert
        $this->assertEquals(789, $result);
        $this->assertEquals(123, $faPayment['debtor_no']);
        $this->assertEquals(50.00, $faPayment['amount']);
    }

    /**
     * @test
     */
    public function recordSquarePaymentFailsWithInvalidPayment(): void
    {
        $this->expectException(PaymentProcessingException::class);
        $this->expectExceptionMessage("Square payment data is required");
        
        // Arrange
        $squarePayment = [
            // Missing required fields
            'id' => 'pay_123456'
        ];
        
        // Act
        $this->paymentService->recordSquarePayment($squarePayment);
    }

    /**
     * @test
     */
    public function recordSquarePaymentFailsWithMissingCustomer(): void
    {
        $this->expectException(PaymentProcessingException::class);
        $this->expectExceptionMessage("Customer not found for payment");
        
        // Arrange
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'payment_method' => 'CARD',
            'customer_email' => 'test@example.com'
        ];
        
        // Mock customer service returns null
        $this->mockCustomerService->expects($this->once())
            ->method('matchCustomer')
            ->with('test@example.com')
            ->willReturn(null);
        
        // Act
        $this->paymentService->recordSquarePayment($squarePayment);
    }

    /**
     * @test
     */
    public function canProcessSquareRefundSuccessfully(): void
    {
        // Arrange
        $squareRefund = [
            'id' => 'refund_123',
            'payment_id' => 'pay_123456',
            'amount_money' => ['amount' => 2000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'reference_id' => 'ref_456',
            'note' => 'Customer refund',
            'payment_method' => 'CARD'
        ];
        
        $originalPayment = [
            'fa_payment_id' => 789,
            'debtor_no' => 123,
            'person_id' => 456,
            'amount' => 50.00,
            'currency' => 'USD',
            'bank_act' => 'Default Card Processing',
            'date_1' => date('Y-m-d'),
            'ref' => 'ref_123',
            'bank_trans_type' => 'Receipt',
            'payment_method' => 'Credit Card',
            'status' => 'Completed'
        ];
        
        $faRefund = [
            'debtor_no' => 123,
            'amount' => 20.00,
            'currency' => 'USD',
            'date_1' => date('Y-m-d'),
            'bank_act' => 'Default Card Processing',
            'ref' => 'ref_456',
            'person_id' => 456,
            'bank_trans_type' => 'Payment',
            'payment_method' => 'Credit Card',
            'status' => 'Refunded',
            'notes' => 'Square refund',
            'original_payment_id' => 789,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Mock payment service get payment
        $this->paymentService->method('getPaymentBySquareId')
            ->with('pay_123456')
            ->willReturn($originalPayment);
        
        // Mock payment adapter
        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFARefund')
            ->with($squareRefund, $originalPayment)
            ->willReturn($faRefund);
        
        // Mock payment DAO insert refund
        $this->mockPaymentsDao->expects($this->once())
            ->method('insertRefund')
            ->with($faRefund)
            ->willReturn(790);
        
        // Mock payment mapping DAO create
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('createMapping')
            ->with($this->callback(function($data) {
                return $data['square_refund_id'] === 'refund_123' &&
                       $data['fa_refund_id'] === 790 &&
                       $data['original_fa_payment_id'] === 789;
            }))
            ->willReturn(2);
        
        // Mock payment DAO log event
        $this->mockPaymentsDao->expects($this->once())
            ->method('logPaymentEvent')
            ->with($this->callback(function($data) {
                return $data['fa_refund_id'] === 790 &&
                       $data['square_refund_id'] === 'refund_123' &&
                       $data['original_fa_payment_id'] === 789 &&
                       $data['event_type'] === 'refund_processed';
            }))
            ->willReturn(3);
        
        // Act
        $result = $this->paymentService->processSquareRefund($squareRefund);
        
        // Assert
        $this->assertEquals(790, $result);
        $this->assertEquals(123, $faRefund['debtor_no']);
        $this->assertEquals(20.00, $faRefund['amount']);
    }

    /**
     * @test
     */
    public function processSquareRefundFailsWithMissingOriginalPayment(): void
    {
        $this->expectException(RefundProcessingException::class);
        $this->expectExceptionMessage("Original payment not found for refund");
        
        // Arrange
        $squareRefund = [
            'id' => 'refund_123',
            'payment_id' => 'pay_123456',
            'amount_money' => ['amount' => 2000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'payment_method' => 'CARD'
        ];
        
        // Mock payment service returns null
        $this->paymentService->method('getPaymentBySquareId')
            ->with('pay_123456')
            ->willReturn(null);
        
        // Act
        $this->paymentService->processSquareRefund($squareRefund);
    }

    /**
     * @test
     */
    public function canReconcileSquarePaymentsSuccessfully(): void
    {
        // Arrange
        $payments = [
            [
                'id' => 'pay_789',
                'amount_money' => ['amount' => 3000, 'currency' => 'USD'],
                'status' => 'COMPLETED',
                'payment_method' => 'CARD',
                'customer_email' => 'test2@example.com'
            ],
            [
                'id' => 'pay_790',
                'amount_money' => ['amount' => 4000, 'currency' => 'USD'],
                'status' => 'COMPLETED',
                'payment_method' => 'CASH',
                'customer_email' => 'test3@example.com'
            ]
        ];
        
        $customer = [
            'debtor_no' => 124,
            'person_id' => 457,
            'email' => 'test2@example.com'
        ];
        
        $faPayment = [
            'debtor_no' => 124,
            'amount' => 30.00,
            'currency' => 'USD',
            'date_1' => date('Y-m-d'),
            'bank_act' => 'Default Card Processing',
            'ref' => 'pay_789',
            'person_id' => 457,
            'bank_trans_type' => 'Receipt',
            'payment_method' => 'Credit Card',
            'status' => 'Completed',
            'notes' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('matchCustomer')
            ->with('test2@example.com')
            ->willReturn($customer);
        
        // Mock payment adapter
        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFAPayment')
            ->with($payments[0], $customer)
            ->willReturn($faPayment);
        
        // Mock payment DAO insert
        $this->mockPaymentsDao->expects($this->once())
            ->method('insertPayment')
            ->with($faPayment)
            ->willReturn(791);
        
        // Mock payment mapping DAO create (record + update)
        $this->mockPaymentMappingDao->expects($this->exactly(2))
            ->method('createMapping')
            ->withConsecutive(
                [$this->callback(function($data) {
                    return $data['square_payment_id'] === 'pay_789' &&
                           $data['fa_payment_id'] === 791;
                })],
                [$this->callback(function($data) {
                    return $data['square_payment_id'] === 'pay_790' &&
                           $data['fa_payment_id'] === 792;
                })]
            )
            ->willReturnOnConsecutiveCalls(3, 4);
        
        // Mock payment DAO log event (record + update)
        $this->mockPaymentsDao->expects($this->exactly(2))
            ->method('logPaymentEvent')
            ->withConsecutive(
                [$this->callback(function($data) {
                    return $data['fa_payment_id'] === 791 &&
                           $data['square_payment_id'] === 'pay_789' &&
                           $data['event_type'] === 'recorded';
                })],
                [$this->callback(function($data) {
                    return $data['fa_payment_id'] === 792 &&
                           $data['square_payment_id'] === 'pay_790' &&
                           $data['event_type'] === 'recorded';
                })]
            )
            ->willReturnOnConsecutiveCalls(4, 5);
        
        // Mock payment service for existing payment check
        $this->paymentService->method('getPaymentBySquareId')
            ->willReturnMap([
                ['pay_789', null], // Payment doesn't exist
                ['pay_790', ['fa_payment_id' => 792]] // Payment exists
            ]);
        
        // Mock payment DAO update
        $this->mockPaymentsDao->expects($this->once())
            ->method('updatePayment')
            ->with(792, ['status' => 'Completed', 'updated_at' => date('Y-m-d H:i:s')])
            ->willReturn(true);
        
        // Act
        $result = $this->paymentService->reconcileSquarePayments($payments);
        
        // Assert
        $this->assertEquals([
            'processed' => 1,
            'reconciled' => 1,
            'failed' => 0,
            'details' => [
                [
                    'payment_id' => 'pay_789',
                    'status' => 'recorded',
                    'message' => 'New payment recorded',
                    'fa_payment_id' => 791
                ],
                [
                    'payment_id' => 'pay_790',
                    'status' => 'updated',
                    'message' => 'Payment already exists, updated status'
                ]
            ]
        ], $result);
    }

    /**
     * @test
     */
    public function getPaymentBySquareIdReturnsNullWhenNotFound(): void
    {
        // Arrange
        $squarePaymentId = 'pay_123456';
        
        // Mock payment mapping DAO returns null
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('getPaymentBySquareId')
            ->with($squarePaymentId)
            ->willReturn(null);
        
        // Use a real service instance (the partial mock stubs out this method)
        $paymentService = new PaymentService(
            $this->mockPaymentsDao,
            $this->mockPaymentAdapter,
            $this->mockCustomerService,
            $this->mockPaymentMappingDao
        );
        
        // Act
        $result = $paymentService->getPaymentBySquareId($squarePaymentId);
        
        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function getPaymentByFaIdReturnsPaymentData(): void
    {
        // Arrange
        $faPaymentId = 789;
        $expectedPayment = [
            'payment_id' => 789,
            'debtor_no' => 123,
            'amount' => 50.00,
            'currency' => 'USD'
        ];
        
        // Mock payment DAO
        $this->mockPaymentsDao->expects($this->once())
            ->method('getPaymentById')
            ->with($faPaymentId)
            ->willReturn($expectedPayment);
        
        // Act
        $result = $this->paymentService->getPaymentByFaId($faPaymentId);
        
        // Assert
        $this->assertEquals($expectedPayment, $result);
    }

    /**
     * @test
     */
    public function canCreatePaymentMappingSuccessfully(): void
    {
        // Arrange
        $mappingData = [
            'square_payment_id' => 'pay_123456',
            'fa_payment_id' => 789,
            'mapping_data' => json_encode(['test' => 'data']),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Mock payment mapping DAO
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('createMapping')
            ->with($mappingData)
            ->willReturn(5);
        
        // Act
        $result = $this->paymentService->createPaymentMapping($mappingData);
        
        // Assert
        $this->assertEquals(5, $result);
    }

    /**
     * @test
     */
    public function createPaymentMappingFailsWithInvalidData(): void
    {
        $this->expectException(PaymentMappingException::class);
        $this->expectExceptionMessage("Mapping data is required");
        
        // Arrange
        $mappingData = [
            // Missing required fields
            'square_payment_id' => 'pay_123456'
        ];
        
        // Act
        $this->paymentService->createPaymentMapping($mappingData);
    }

    /**
     * @test
     */
    public function canGetPaymentStatistics(): void
    {
        // Arrange
        $expectedStats = [
            'total_payments' => 100,
            'recent_payments' => 15,
            'amounts' => [
                'total_amount' => 5000.00,
                'total_payments' => 100,
                'average_amount' => 50.00
            ],
            'by_payment_method' => [
                'Credit Card' => 80,
                'Cash' => 20
            ]
        ];
        
        // Mock payment DAO
        $this->mockPaymentsDao->expects($this->once())
            ->method('getPaymentStatistics')
            ->willReturn($expectedStats);
        
        // Act
        $result = $this->paymentService->getPaymentStatistics();
        
        // Assert
        $this->assertEquals($expectedStats, $result);
    }

    /**
     * @test
     */
    public function recordSquarePaymentHandlesDaoError(): void
    {
        $this->expectException(PaymentProcessingException::class);
        $this->expectExceptionMessage("Failed to record Square payment: Database error");
        
        // Arrange
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'payment_method' => 'CARD',
            'customer_email' => 'test@example.com'
        ];
        
        $customer = [
            'debtor_no' => 123,
            'email' => 'test@example.com'
        ];
        
        // Mock customer service
        $this->mockCustomerService->expects($this->once())
            ->method('matchCustomer')
            ->willReturn($customer);
        
        // Mock payment adapter
        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFAPayment')
            ->willReturn(['debtor_no' => 123, 'amount' => 50.00]);
        
        // Mock payment DAO throws exception
        $this->mockPaymentsDao->expects($this->once())
            ->method('insertPayment')
            ->willThrowException(new \Exception("Database error"));
        
        // Act
        $this->paymentService->recordSquarePayment($squarePayment);
    }
}