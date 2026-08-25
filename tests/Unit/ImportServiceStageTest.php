<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\PaymentMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\SalesMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareImportLogDAO;
use ksfraser\FrontAccounting\Square\Services\ImportService;
use ksfraser\FrontAccounting\Square\Staging\IsuStagingGateway;
use PHPUnit\Framework\TestCase;

/**
 * Tests ImportService.stageFromApi() with fully mocked dependencies.
 *
 * @BABOK Related: FR-SI-001 - Stage from API
 * @BABOK Related: FR-SI-002 - Dedup staging
 */
class ImportServiceStageTest extends TestCase
{
    /** @var ImportService */
    private $service;

    /** @var \PHPUnit\Framework\MockObject\MockObject|IsuStagingGateway */
    private $gateway;

    /** @var \PHPUnit\Framework\MockObject\MockObject|SquareImportLogDAO */
    private $importLogDao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(IsuStagingGateway::class);
        $this->importLogDao = $this->createMock(SquareImportLogDAO::class);
        $paymentMatchDao = $this->createMock(PaymentMatchDAO::class);
        $salesMatchDao = $this->createMock(SalesMatchDAO::class);

        $settings = new Settings();
        $settings->setEnvironment('sandbox');

        $this->service = $this->newInstanceWithoutConstructor();
        $this->setPropertyValue($this->service, 'settings', $settings);
        $this->setPropertyValue($this->service, 'gateway', $this->gateway);
        $this->setPropertyValue($this->service, 'squareImportLogDao', $this->importLogDao);
        $this->setPropertyValue($this->service, 'paymentMatchDao', $paymentMatchDao);
        $this->setPropertyValue($this->service, 'salesMatchDao', $salesMatchDao);
    }

    /** @test */
    public function stageFromApiReturnsZeroForEmptyLocations(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');

        $results = $this->service->stageFromApi($from, $to, '', []);

        $this->assertSame(0, $results['staged']);
        $this->assertNotEmpty($results['errors']);
    }

    /** @test */
    public function stageFromApiReturnsZeroWhenNoPaymentsFound(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->expects($this->once())
            ->method('listPayments')
            ->willReturn([]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $results = $this->service->stageFromApi($from, $to, '', $locations);

        $this->assertSame(0, $results['staged']);
        $this->assertSame(0, $results['skipped']);
        $this->assertGreaterThanOrEqual(1, count($results['errors']));
    }

    /** @test */
    public function stageFromApiSkipsAlreadyStagedPayment(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $payment = $this->createPaymentMock('pay_100', 'ord_100', 5000);

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->method('listPayments')->willReturn([$payment]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $this->gateway->expects($this->once())
            ->method('exists')
            ->with('pay_100')
            ->willReturn(true);

        $results = $this->service->stageFromApi($from, $to, '', $locations);

        $this->assertSame(0, $results['staged']);
        $this->assertSame(1, $results['skipped']);
    }

    /** @test */
    public function stageFromApiSkipsRefundedPayment(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $money = new \Square\Models\Money();
        $money->setAmount(2500);
        $money->setCurrency('USD');

        $totalMoney = $this->money(5000, 'USD');
        $payment = $this->createMock(\Square\Models\Payment::class);
        $payment->method('getId')->willReturn('pay_refund');
        $payment->method('getOrderId')->willReturn('ord_refund');
        $payment->method('getTotalMoney')->willReturn($totalMoney);
        $payment->method('getTipMoney')->willReturn(null);
        $payment->method('getRefundedMoney')->willReturn($money);
        $payment->method('getCreatedAt')->willReturn('2026-08-15T14:30:00Z');
        $payment->method('getCustomerId')->willReturn(null);
        $payment->method('getCardDetails')->willReturn(null);
        $payment->method('getSourceType')->willReturn('CARD');

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->method('listPayments')->willReturn([$payment]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $this->gateway->method('exists')->willReturn(false);

        $results = $this->service->stageFromApi($from, $to, '', $locations);

        $this->assertSame(0, $results['staged']);
        $this->assertSame(1, $results['skipped']);
    }

    /** @test */
    public function stageFromApiSkipsPaymentWithoutOrderId(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $payment = $this->createPaymentMock('pay_noorder', null, 5000);
        $payment->method('getRefundedMoney')->willReturn(null);

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->method('listPayments')->willReturn([$payment]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $this->gateway->method('exists')->willReturn(false);

        $results = $this->service->stageFromApi($from, $to, '', $locations);

        $this->assertSame(0, $results['staged']);
        $this->assertSame(1, $results['skipped']);
    }

    /** @test */
    public function stageFromApiStagesSinglePaymentSuccessfully(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $payment = $this->createPaymentMock('pay_001', 'ord_001', 5000);
        $payment->method('getRefundedMoney')->willReturn(null);
        $payment->method('getCreatedAt')->willReturn('2026-08-15T14:30:00Z');
        $payment->method('getCustomerId')->willReturn('cust_123');
        $payment->method('getCardDetails')->willReturn(null);
        $payment->method('getTotalMoney')->willReturn($this->money(5000, 'USD'));
        $payment->method('getTipMoney')->willReturn(null);

        $lineItem = $this->createMock(\Square\Models\OrderLineItem::class);
        $lineItem->method('getUid')->willReturn('item_001');
        $lineItem->method('getName')->willReturn('Widget');
        $lineItem->method('getQuantity')->willReturn('2');
        $lineItem->method('getBasePriceMoney')->willReturn($this->money(2500, 'USD'));
        $lineItem->method('getTotalTaxMoney')->willReturn(null);
        $lineItem->method('getTotalDiscountMoney')->willReturn(null);
        $lineItem->method('getCatalogObjectId')->willReturn(null);

        $order = $this->createMock(\Square\Models\Order::class);
        $order->method('getId')->willReturn('ord_001');
        $order->method('getLineItems')->willReturn([$lineItem]);
        $order->method('getTotalMoney')->willReturn($this->money(5000, 'USD'));
        $order->method('getTotalTaxMoney')->willReturn(null);
        $order->method('getTotalDiscountMoney')->willReturn(null);
        $order->method('getTotalTipMoney')->willReturn(null);
        $order->method('getCreatedAt')->willReturn('2026-08-15T14:30:00Z');
        $order->method('getUpdatedAt')->willReturn('2026-08-15T14:30:00Z');

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->method('listPayments')->willReturn([$payment]);
        $orderImporter->method('getPaymentWithOrder')
            ->with('pay_001')
            ->willReturn(['payment' => $payment, 'order' => $order]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $this->gateway->method('exists')->willReturn(false);
        $this->gateway->expects($this->once())
            ->method('stageSquareOrder')
            ->willReturn(1);

        $results = $this->service->stageFromApi($from, $to, '', $locations);

        $this->assertSame(1, $results['staged']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(1, $results['payments_found']);
    }

    /** @test */
    public function stageFromApiFiltersByLocation(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = [
            'LOC_1' => 'Main Store',
            'LOC_2' => 'Second Store',
        ];

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->expects($this->once())
            ->method('listPayments')
            ->with($from, $to, 'LOC_1')
            ->willReturn([]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $results = $this->service->stageFromApi($from, $to, 'LOC_1', $locations);

        $this->assertSame(0, $results['staged']);
        $this->assertSame(0, $results['payments_found']);
    }

    /** @test */
    public function stageFromApiLogsImportAfterCompletion(): void
    {
        $from = new DateTimeImmutable('2026-08-01');
        $to = new DateTimeImmutable('2026-08-21');
        $locations = ['LOC_1' => 'Main Store'];

        $orderImporter = $this->createMock(\ksfraser\FrontAccounting\Square\Pull\OrderImporter::class);
        $orderImporter->method('listPayments')->willReturn([]);
        $this->setPropertyValue($this->service, 'orderImporter', $orderImporter);

        $this->importLogDao->expects($this->once())
            ->method('insertLog')
            ->with(
                'api',
                0,
                0,
                0,
                'completed',
                '2026-08-01',
                '2026-08-21',
                'sandbox',
                SquareImportLogDAO::OP_TYPE_STAGE,
                ''
            );

        $this->service->stageFromApi($from, $to, '', $locations);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function createPaymentMock(string $paymentId, ?string $orderId, int $amountCents): \PHPUnit\Framework\MockObject\MockObject
    {
        $totalMoney = $this->money($amountCents, 'USD');

        $payment = $this->createMock(\Square\Models\Payment::class);
        $payment->method('getId')->willReturn($paymentId);
        $payment->method('getOrderId')->willReturn($orderId);
        $payment->method('getTotalMoney')->willReturn($totalMoney);
        $payment->method('getTipMoney')->willReturn(null);
        $payment->method('getRefundedMoney')->willReturn(null);
        $payment->method('getCreatedAt')->willReturn('2026-08-15T14:30:00Z');
        $payment->method('getCustomerId')->willReturn(null);
        $payment->method('getCardDetails')->willReturn(null);
        $payment->method('getSourceType')->willReturn('CARD');
        return $payment;
    }

    private function money(int $amount, string $currency): \Square\Models\Money
    {
        $m = new \Square\Models\Money();
        $m->setAmount($amount);
        $m->setCurrency($currency);
        return $m;
    }

    private function setPropertyValue(object $object, string $propertyName, $value): void
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function newInstanceWithoutConstructor(): ImportService
    {
        $reflection = new \ReflectionClass(ImportService::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
