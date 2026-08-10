<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\DAO\SalesOrdersDAO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SalesOrdersDAO order dedup and Square order id lookups.
 *
 * Dedup relies on the ksf_import_square_sales table (square_transaction_id),
 * which the import flow populates via SalesMatchDAO::insertMatch.
 *
 * @BABOK Related: FR-02.01 - Order Synchronization
 */
class SalesOrdersDAOTest extends TestCase
{
    /** @var SalesOrdersDAO */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $GLOBALS['__fa_last_sql'] = '';
        $this->dao = new SalesOrdersDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SalesOrdersDAO::class, $this->dao);
    }

    public function testOrderExistsReturnsFalseWhenNoMatch(): void
    {
        $GLOBALS['__fa_table'] = [];

        $this->assertFalse($this->dao->orderExists('sqpay_123'));
    }

    public function testOrderExistsReturnsTrueWhenMatchExists(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'sqpay_123', 'ksf_import_square_sales_id' => '7'],
        ];

        $this->assertTrue($this->dao->orderExists('sqpay_123'));
        $this->assertStringContainsString('ksf_import_square_sales', (string)$GLOBALS['__fa_last_sql']);
        $this->assertStringContainsString('sqpay_123', (string)$GLOBALS['__fa_last_sql']);
    }

    public function testOrderExistsReturnsFalseForOtherTransaction(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'sqpay_other', 'ksf_import_square_sales_id' => '8'],
        ];

        $this->assertFalse($this->dao->orderExists('sqpay_123'));
    }

    public function testGetBySquareIdReturnsOrderWhenMapped(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'sqorder_9', 'order_id' => 42, 'debtor_no' => 7, 'order_ref' => 'SQR-9'],
        ];

        $result = $this->dao->getBySquareId('sqorder_9');

        $this->assertIsArray($result);
        $this->assertSame(42, (int)$result['order_id']);
        $this->assertSame(7, (int)$result['debtor_no']);
        $this->assertStringContainsString('square_order_mappings', (string)$GLOBALS['__fa_last_sql']);
    }

    public function testGetBySquareIdReturnsNullWhenNotMapped(): void
    {
        $GLOBALS['__fa_table'] = [];

        $this->assertNull($this->dao->getBySquareId('sqorder_unknown'));
    }
}
