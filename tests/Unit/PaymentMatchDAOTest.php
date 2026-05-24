<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMatchDAO;
use PHPUnit\Framework\TestCase;

class PaymentMatchDAOTest extends TestCase
{
    /**
     * @var PaymentMatchDAO
     */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->dao = new PaymentMatchDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(PaymentMatchDAO::class, $this->dao);
    }

    public function testGetTableName(): void
    {
        $tableName = $this->dao->getTableName();
        $this->assertStringContainsString('ksf_import_square_payments', $tableName);
    }

    public function testEnsureTableExists(): void
    {
        $GLOBALS['__fa_result_set'][] = [
            ['Tables_in_test' => '0_ksf_import_square_payments'],
        ];
        $GLOBALS['__fa_result_pos'][] = 0;

        $this->dao->ensureTableExists();
        $this->assertTrue(true);
    }

    public function testGetBySquarePaymentIdNotFound(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getBySquarePaymentId('pmt_001');
        $this->assertNull($result);
    }

    public function testGetByFaTransactionReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getByFaTransaction(12, 1001);
        $this->assertIsArray($result);
    }

    public function testIsMatchedNotFound(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->isMatched('pmt_001');
        $this->assertFalse($result);
    }
}
