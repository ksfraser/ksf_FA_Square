<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Staging\InvoiceCreator;
use PHPUnit\Framework\TestCase;

class InvoiceCreatorTest extends TestCase
{
    private InvoiceCreator $creator;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->creator = new InvoiceCreator('0_');
    }

    public function testCreateSalesInvoice(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'next_ref', 'pref_value' => '2000'],
        ];

        $orderNo = $this->creator->createSalesInvoice(
            42,
            1,
            new \DateTimeImmutable('2026-05-21'),
            [
                ['stock_id' => 'WIDGET', 'unit_price' => 10.00, 'quantity' => 2],
            ],
            [],
            ['reference' => '2000', 'comments' => 'Test invoice']
        );

        $this->assertIsInt($orderNo);
    }

    public function testCreateSalesInvoiceWithAutoReference(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'next_ref', 'pref_value' => '3000'],
        ];

        $orderNo = $this->creator->createSalesInvoice(
            42,
            1,
            new \DateTimeImmutable('2026-05-21'),
            [],
            []
        );

        $this->assertIsInt($orderNo);
    }

    public function testRecordPayment(): void
    {
        $transNo = $this->creator->recordPayment(
            2000,
            100.00,
            new \DateTimeImmutable('2026-05-21'),
            1
        );

        $this->assertSame(0, $transNo);
    }
}
