<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit\Services;

use Ksfraser\Frontaccounting\SquareUp\Services\PaymentService;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentsDAO;
use Ksfraser\Frontaccounting\SquareUp\Services\PaymentAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\CustomerService;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\PaymentProcessingException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for PaymentService::recordImportedPayment — the import-flow
 * entrypoint that records a Square payment against an explicit FA debtor
 * (the destination customer chosen at import time) and is idempotent.
 *
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing
 */
class PaymentServiceRecordedPaymentTest extends TestCase
{
    protected MockObject $mockPaymentsDao;
    protected MockObject $mockPaymentAdapter;
    protected MockObject $mockCustomerService;
    protected MockObject $mockPaymentMappingDao;
    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPaymentsDao = $this->createMock(PaymentsDAO::class);
        $this->mockPaymentAdapter = $this->createMock(PaymentAdapter::class);
        $this->mockCustomerService = $this->createMock(CustomerService::class);
        $this->mockPaymentMappingDao = $this->createMock(PaymentMappingDAO::class);

        $this->paymentService = new PaymentService(
            $this->mockPaymentsDao,
            $this->mockPaymentAdapter,
            $this->mockCustomerService,
            $this->mockPaymentMappingDao
        );
    }

    /**
     * @test
     */
    public function recordImportedPaymentRecordsAgainstExplicitDebtor(): void
    {
        // Arrange
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'reference_id' => 'ref_123',
            'note' => 'Imported from Square',
            'payment_method' => 'CARD',
            'customer_email' => 'test@example.com'
        ];

        $faPayment = [
            'debtor_no' => 123,
            'amount' => 50.00,
            'currency' => 'USD',
            'date_1' => date('Y-m-d'),
            'bank_act' => 'Default Card Processing',
            'ref' => 'ref_123',
            'person_id' => null,
            'bank_trans_type' => 'Receipt',
            'payment_method' => 'Credit Card',
            'status' => 'Completed',
            'notes' => 'Imported from Square',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // No existing mapping -> proceeds to insert
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('getPaymentBySquareId')
            ->with('pay_123456')
            ->willReturn(null);

        $this->mockPaymentAdapter->expects($this->once())
            ->method('convertToFAPayment')
            ->with($squarePayment, $this->callback(function ($customer) {
                return $customer['debtor_no'] === 123 && $customer['person_id'] === null;
            }))
            ->willReturn($faPayment);

        $this->mockPaymentsDao->expects($this->once())
            ->method('insertPayment')
            ->with($faPayment)
            ->willReturn(789);

        $this->mockPaymentMappingDao->expects($this->once())
            ->method('createMapping')
            ->with($this->callback(function ($data) {
                return $data['square_payment_id'] === 'pay_123456' && $data['fa_payment_id'] === 789;
            }))
            ->willReturn(1);

        $this->mockPaymentsDao->expects($this->once())
            ->method('logPaymentEvent')
            ->with($this->callback(function ($data) {
                return $data['fa_payment_id'] === 789 &&
                       $data['square_payment_id'] === 'pay_123456' &&
                       $data['event_type'] === 'recorded';
            }))
            ->willReturn(2);

        // Act
        $result = $this->paymentService->recordImportedPayment($squarePayment, 123);

        // Assert
        $this->assertEquals(789, $result);
    }

    /**
     * @test
     */
    public function recordImportedPaymentIsIdempotent(): void
    {
        // Arrange
        $squarePayment = [
            'id' => 'pay_123456',
            'amount_money' => ['amount' => 5000, 'currency' => 'USD'],
            'status' => 'COMPLETED',
            'payment_method' => 'CARD'
        ];

        // Existing mapping -> return existing id without re-inserting
        $this->mockPaymentMappingDao->expects($this->once())
            ->method('getPaymentBySquareId')
            ->with('pay_123456')
            ->willReturn(['fa_payment_id' => 999]);

        $this->mockPaymentAdapter->expects($this->never())
            ->method('convertToFAPayment');
        $this->mockPaymentsDao->expects($this->never())
            ->method('insertPayment');
        $this->mockPaymentsDao->expects($this->never())
            ->method('logPaymentEvent');

        // Act
        $result = $this->paymentService->recordImportedPayment($squarePayment, 123);

        // Assert
        $this->assertEquals(999, $result);
    }

    /**
     * @test
     */
    public function recordImportedPaymentFailsWithInvalidPayment(): void
    {
        $this->expectException(PaymentProcessingException::class);
        $this->expectExceptionMessage("Square payment data is required");

        // Arrange: missing amount_money
        $squarePayment = [
            'id' => 'pay_123456'
        ];

        // Act
        $this->paymentService->recordImportedPayment($squarePayment, 123);
    }
}
