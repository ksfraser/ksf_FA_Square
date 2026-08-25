<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services {

use ksfraser\FrontAccounting\Square\Services\ImportService;
use ksfraser\FrontAccounting\Square\Services\PaymentService;
use ksfraser\FrontAccounting\Square\Exceptions\PaymentProcessingException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Square\Models\Payment;
use Square\Models\Money;

/**
 * Verifies the import flow records Square payments against the imported
 * invoice's debtor via PaymentService after a successful import.
 *
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing
 */
class ImportServicePaymentTest extends TestCase
{
    /**
     * @test
     */
    public function recordSquarePaymentDelegatesToPaymentService(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();
        $mockPaymentService = $this->createMock(PaymentService::class);
        $mockPaymentService->expects($this->once())
            ->method('recordImportedPayment')
            ->with($this->callback(function ($payment) {
                return $payment['id'] === 'pay_123' && $payment['amount_money']['amount'] === 5000;
            }), 77)
            ->willReturn(42);
        $this->setPaymentService($service, $mockPaymentService);

        // Act
        $result = $this->invokeMethod($service, 'recordSquarePayment', [
            ['id' => 'pay_123', 'amount_money' => ['amount' => 5000, 'currency' => 'USD']],
            77,
        ]);

        // Assert
        $this->assertSame(42, $result);
    }

    /**
     * @test
     */
    public function recordSquarePaymentReturnsNullOnFailure(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();
        $mockPaymentService = $this->createMock(PaymentService::class);
        $mockPaymentService->expects($this->once())
            ->method('recordImportedPayment')
            ->willThrowException(new PaymentProcessingException("boom"));
        $this->setPaymentService($service, $mockPaymentService);

        // Act
        $result = $this->invokeMethod($service, 'recordSquarePayment', [
            ['id' => 'pay_123', 'amount_money' => ['amount' => 5000, 'currency' => 'USD']],
            77,
        ]);

        // Assert: the invoice import must not fail when payment recording fails
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function recordSquarePaymentReturnsNullWithoutService(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();

        // Act
        $result = $this->invokeMethod($service, 'recordSquarePayment', [
            ['id' => 'pay_123', 'amount_money' => ['amount' => 5000, 'currency' => 'USD']],
            77,
        ]);

        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function buildSquarePaymentFromTransactionUsesStoredPaymentData(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();
        $trans = [
            'source_payment_id' => 'pay_123',
            'total_amount' => 50.00,
            'currency' => '',
            'raw_json' => json_encode([
                'payment' => ['id' => 'pay_123', 'amount' => 5000, 'currency' => 'USD', 'source_type' => 'CARD'],
                'order' => [],
            ]),
        ];

        // Act
        $payment = $this->invokeMethod($service, 'buildSquarePaymentFromTransaction', [$trans]);

        // Assert
        $this->assertSame('pay_123', $payment['id']);
        $this->assertSame(5000, $payment['amount_money']['amount']);
        $this->assertSame('USD', $payment['amount_money']['currency']);
        $this->assertSame('CARD', $payment['payment_method']);
        $this->assertSame('COMPLETED', $payment['status']);
    }

    /**
     * @test
     */
    public function buildSquarePaymentFromTransactionFallsBackToTotalCollected(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();
        $trans = [
            'source_payment_id' => 'pay_123',
            'total_amount' => 99.50,
            'raw_json' => '',
        ];

        // Act
        $payment = $this->invokeMethod($service, 'buildSquarePaymentFromTransaction', [$trans]);

        // Assert
        $this->assertSame(9950, $payment['amount_money']['amount']);
        $this->assertSame('OTHER', $payment['payment_method']);
    }

    /**
     * @test
     */
    public function buildSquarePaymentFromPaymentObject(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();
        $money = new Money();
        $money->setAmount(5000);
        $money->setCurrency('USD');
        $payment = new Payment();
        $payment->setId('pay_123');
        $payment->setTotalMoney($money);
        $payment->setStatus('COMPLETED');
        $payment->setSourceType('CARD');
        $payment->setReferenceId('ref_1');
        $payment->setBuyerEmailAddress('test@example.com');
        $payment->setNote('Imported from Square');

        // Act
        $result = $this->invokeMethod($service, 'buildSquarePaymentFromPayment', [$payment]);

        // Assert
        $this->assertSame('pay_123', $result['id']);
        $this->assertSame(5000, $result['amount_money']['amount']);
        $this->assertSame('USD', $result['amount_money']['currency']);
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame('CARD', $result['payment_method']);
        $this->assertSame('ref_1', $result['reference_id']);
        $this->assertSame('test@example.com', $result['customer_email']);
        $this->assertSame('Imported from Square', $result['note']);
    }

    private function setPaymentService(ImportService $service, ?PaymentService $paymentService): void
    {
        $reflection = new \ReflectionClass(ImportService::class);
        $property = $reflection->getProperty('paymentService');
        $property->setAccessible(true);
        $property->setValue($service, $paymentService);
    }

    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    private function newInstanceWithoutConstructor(): ImportService
    {
        $reflection = new \ReflectionClass(ImportService::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
}
