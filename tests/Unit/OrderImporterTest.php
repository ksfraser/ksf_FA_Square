<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Pull\OrderImporter;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use PHPUnit\Framework\TestCase;
use Square\SquareClient;
use Square\Models\Payment;
use Square\Models\Order;

class OrderImporterTest extends TestCase
{
    private $mockClient;
    private $mockSettings;
    private $mockPaymentsApi;
    private $mockOrdersApi;
    private $importer;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(SquareClient::class);
        $this->mockSettings = $this->createMock(SettingsInterface::class);
        $this->mockPaymentsApi = $this->createMock(\Square\Apis\PaymentsApi::class);
        $this->mockOrdersApi = $this->createMock(\Square\Apis\OrdersApi::class);

        $this->mockClient->method('getPaymentsApi')->willReturn($this->mockPaymentsApi);
        $this->mockClient->method('getOrdersApi')->willReturn($this->mockOrdersApi);

        $this->importer = new OrderImporter($this->mockClient, $this->mockSettings);
    }

    public function testListPayments(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\ListPaymentsResponse::class);
        $payments = [new Payment('pmt_1'), new Payment('pmt_2')];

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getPayments')->willReturn($payments);
        $mockResult->method('getCursor')->willReturn(null);

        $this->mockPaymentsApi->expects($this->once())
            ->method('listPayments')
            ->willReturn($mockResponse);

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');

        $result = $this->importer->listPayments($from, $to);
        $this->assertCount(2, $result);
    }

    public function testGetPaymentWithOrder(): void
    {
        $mockPayResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockPayResult = $this->createMock(\Square\Models\GetPaymentResponse::class);
        $payment = new Payment('pmt_001');
        $payment->setOrderId('ord_001');

        $mockPayResponse->method('isSuccess')->willReturn(true);
        $mockPayResponse->method('getResult')->willReturn($mockPayResult);
        $mockPayResult->method('getPayment')->willReturn($payment);

        $mockOrdResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockOrdResult = $this->createMock(\Square\Models\RetrieveOrderResponse::class);
        $order = new Order('LOC-001');

        $mockOrdResponse->method('isSuccess')->willReturn(true);
        $mockOrdResponse->method('getResult')->willReturn($mockOrdResult);
        $mockOrdResult->method('getOrder')->willReturn($order);

        $this->mockPaymentsApi->method('getPayment')->willReturn($mockPayResponse);
        $this->mockOrdersApi->method('retrieveOrder')->willReturn($mockOrdResponse);

        $result = $this->importer->getPaymentWithOrder('pmt_001');
        $this->assertArrayHasKey('payment', $result);
        $this->assertArrayHasKey('order', $result);
        $this->assertSame($payment, $result['payment']);
        $this->assertSame($order, $result['order']);
    }

    public function testGetPaymentWithOrderNoOrder(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\GetPaymentResponse::class);
        $payment = new Payment('pmt_002');

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getPayment')->willReturn($payment);

        $this->mockPaymentsApi->method('getPayment')->willReturn($mockResponse);

        $result = $this->importer->getPaymentWithOrder('pmt_002');
        $this->assertNull($result['order']);
    }

    public function testGetOrder(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\RetrieveOrderResponse::class);
        $order = new Order('LOC-001');

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getOrder')->willReturn($order);

        $this->mockOrdersApi->method('retrieveOrder')->willReturn($mockResponse);

        $result = $this->importer->getOrder('ord_001');
        $this->assertSame($order, $result);
    }

    public function testGetOrderNotFound(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(false);

        $this->mockOrdersApi->method('retrieveOrder')->willReturn($mockResponse);

        $result = $this->importer->getOrder('ord_missing');
        $this->assertNull($result);
    }

    public function testGetOrders(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\BatchRetrieveOrdersResponse::class);
        $orders = [new Order('LOC-001'), new Order('LOC-002')];

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getOrders')->willReturn($orders);

        $this->mockOrdersApi->method('batchRetrieveOrders')->willReturn($mockResponse);

        $result = $this->importer->getOrders(['ord_001', 'ord_002']);
        $this->assertCount(2, $result);
    }

    public function testListPaymentsWithLocationFilter(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\ListPaymentsResponse::class);

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getPayments')->willReturn([new Payment('pmt_loc')]);
        $mockResult->method('getCursor')->willReturn(null);

        $this->mockPaymentsApi->expects($this->once())
            ->method('listPayments')
            ->willReturn($mockResponse);

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');

        $result = $this->importer->listPayments($from, $to, 'LOC-001');
        $this->assertCount(1, $result);
    }
}
