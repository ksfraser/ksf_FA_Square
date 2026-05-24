<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\DAO\SalesMatchDAO;
use PHPUnit\Framework\TestCase;

class SalesMatchDAOTest extends TestCase
{
    /**
     * @var SalesMatchDAO
     */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'][] = [];
        $this->dao = new SalesMatchDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SalesMatchDAO::class, $this->dao);
    }

    public function testGetTableName(): void
    {
        $tableName = $this->dao->getTableName();
        $this->assertStringContainsString('ksf_import_square_sales', $tableName);
    }

    public function testEnsureTableExists(): void
    {
        $GLOBALS['__fa_result_set'][] = [
            ['Tables_in_test' => '0_ksf_import_square_sales'],
        ];
        $GLOBALS['__fa_result_pos'][] = 0;

        $this->dao->ensureTableExists();
        $this->assertTrue(true);
    }

    public function testGetBySquareTransactionIdNotFound(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getBySquareTransactionId('txn_001');
        $this->assertNull($result);
    }

    public function testGetByInvoiceNoReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getByInvoiceNo(1001);
        $this->assertIsArray($result);
    }

    public function testIsMatchedNotFound(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->isMatched('txn_001');
        $this->assertFalse($result);
    }
}
