<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Push\TerminalPayment;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use PHPUnit\Framework\TestCase;
use Square\SquareClient;
use Square\Models\Order;
use Square\Models\Money;
use Square\Models\TerminalCheckout;
use Square\Models\DeviceCheckoutOptions;

class TerminalPaymentTest extends TestCase
{
    private $mockClient;
    private $mockSettings;
    private $mockOrdersApi;
    private $mockTerminalApi;
    private $terminalPayment;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(SquareClient::class);
        $this->mockSettings = $this->createMock(SettingsInterface::class);
        $this->mockOrdersApi = $this->createMock(\Square\Apis\OrdersApi::class);
        $this->mockTerminalApi = $this->createMock(\Square\Apis\TerminalApi::class);

        $this->mockClient->method('getOrdersApi')->willReturn($this->mockOrdersApi);
        $this->mockClient->method('getTerminalApi')->willReturn($this->mockTerminalApi);

        $this->terminalPayment = new TerminalPayment($this->mockClient, $this->mockSettings);
    }

    public function testCreateOrderFromInvoice(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\CreateOrderResponse::class);
        $expectedOrder = new Order('LOC-001');

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getOrder')->willReturn($expectedOrder);

        $this->mockOrdersApi->expects($this->once())
            ->method('createOrder')
            ->willReturn($mockResponse);

        $lineItems = [
            ['name' => 'Widget', 'quantity' => '1', 'base_price_cents' => 1000, 'currency' => 'CAD'],
        ];

        $result = $this->terminalPayment->createOrderFromInvoice('LOC-001', $lineItems);
        $this->assertSame($expectedOrder, $result);
    }

    public function testCreateOrderFromInvoiceFailure(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(false);

        $this->mockOrdersApi->method('createOrder')->willReturn($mockResponse);

        $this->expectException(SquareException::class);
        $this->terminalPayment->createOrderFromInvoice('LOC-001', []);
    }

    private function createTerminalCheckoutMock(): TerminalCheckout
    {
        $money = new Money();
        $money->setAmount(1000);
        $money->setCurrency('CAD');
        $deviceOptions = new DeviceCheckoutOptions('dvc_001');
        return $this->getMockBuilder(TerminalCheckout::class)
            ->setConstructorArgs([$money, $deviceOptions])
            ->getMock();
    }

    public function testCreateTerminalCheckout(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\CreateTerminalCheckoutResponse::class);
        $expectedCheckout = $this->createTerminalCheckoutMock();

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getCheckout')->willReturn($expectedCheckout);

        $this->mockTerminalApi->expects($this->once())
            ->method('createTerminalCheckout')
            ->willReturn($mockResponse);

        $order = new Order('LOC-001');

        $result = $this->terminalPayment->createTerminalCheckout($order, 'dvc_001', 'idem_001');
        $this->assertSame($expectedCheckout, $result);
    }

    public function testCreateTerminalCheckoutWithTip(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\CreateTerminalCheckoutResponse::class);
        $expectedCheckout = $this->createTerminalCheckoutMock();

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getCheckout')->willReturn($expectedCheckout);

        $this->mockTerminalApi->method('createTerminalCheckout')->willReturn($mockResponse);

        $order = new Order('LOC-001');

        $result = $this->terminalPayment->createTerminalCheckout($order, 'dvc_001', 'idem_002', 150);
        $this->assertNotNull($result);
    }

    public function testGetCheckoutStatus(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\GetTerminalCheckoutResponse::class);
        $expectedCheckout = $this->createTerminalCheckoutMock();

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getCheckout')->willReturn($expectedCheckout);

        $this->mockTerminalApi->expects($this->once())
            ->method('getTerminalCheckout')
            ->willReturn($mockResponse);

        $result = $this->terminalPayment->getCheckoutStatus('chk_001');
        $this->assertSame($expectedCheckout, $result);
    }

    public function testCancelCheckout(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(true);

        $this->mockTerminalApi->expects($this->once())
            ->method('cancelTerminalCheckout')
            ->willReturn($mockResponse);

        $this->terminalPayment->cancelCheckout('chk_001');
        $this->assertTrue(true);
    }

    public function testCancelCheckoutFailure(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(false);

        $this->mockTerminalApi->method('cancelTerminalCheckout')->willReturn($mockResponse);

        $this->expectException(SquareException::class);
        $this->terminalPayment->cancelCheckout('chk_bad');
    }
}
