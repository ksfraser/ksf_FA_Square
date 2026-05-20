<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\ProductNotFoundException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function testSquareExceptionApiError(): void
    {
        $e = SquareException::apiError('listPayments', 'Rate limit exceeded', [['code' => 'RATE_LIMITED']]);
        $this->assertStringContainsString('listPayments', $e->getMessage());
        $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
    }

    public function testSquareExceptionConfigurationError(): void
    {
        $e = SquareException::configurationError('access_token');
        $this->assertStringContainsString('access_token', $e->getMessage());
    }

    public function testSquareExceptionImportFailed(): void
    {
        $e = SquareException::importFailed('Order already exists');
        $this->assertStringContainsString('Import failed', $e->getMessage());
    }

    public function testSquareExceptionExportFailed(): void
    {
        $e = SquareException::exportFailed('Catalog API unreachable');
        $this->assertStringContainsString('Export failed', $e->getMessage());
    }

    public function testProductNotFoundExceptionBySku(): void
    {
        $e = ProductNotFoundException::bySku('WIDGET-001');
        $this->assertStringContainsString('WIDGET-001', $e->getMessage());
    }

    public function testProductNotFoundExceptionByStockId(): void
    {
        $e = ProductNotFoundException::byStockId('STK-001');
        $this->assertStringContainsString('STK-001', $e->getMessage());
    }

    public function testSquareExceptionIsInstanceOfFAException(): void
    {
        $e = SquareException::configurationError('test');
        $this->assertInstanceOf(\Ksfraser\Exceptions\FrontAccounting\FAException::class, $e);
    }
}
