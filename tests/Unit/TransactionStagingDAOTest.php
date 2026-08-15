<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use ksfraser\FrontAccounting\Square\DAO\TransactionStagingDAO;
use PHPUnit\Framework\TestCase;

class TransactionStagingDAOTest extends TestCase
{
    /**
     * @var TransactionStagingDAO
     */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->dao = new TransactionStagingDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(TransactionStagingDAO::class, $this->dao);
    }

    public function testGetTableName(): void
    {
        $tableName = $this->dao->getTableName();
        $this->assertStringContainsString('ksf_import_square_transactions', $tableName);
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('staged', TransactionStagingDAO::STATUS_STAGED);
        $this->assertSame('imported', TransactionStagingDAO::STATUS_IMPORTED);
        $this->assertSame('failed', TransactionStagingDAO::STATUS_FAILED);
        $this->assertSame('matched', TransactionStagingDAO::STATUS_MATCHED);
    }

    public function testSourceConstants(): void
    {
        $this->assertSame('api', TransactionStagingDAO::SOURCE_API);
        $this->assertSame('csv', TransactionStagingDAO::SOURCE_CSV);
    }

    public function testEnsureTableExists(): void
    {
        $GLOBALS['__fa_result_set'][] = [
            ['Tables_in_test' => '0_ksf_import_square_transactions'],
        ];
        $GLOBALS['__fa_result_pos'][] = 0;

        $this->dao->ensureTableExists();
        $this->assertTrue(true);
    }

    public function testGetByStatusReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getByStatus(TransactionStagingDAO::STATUS_STAGED);
        $this->assertIsArray($result);
    }

    public function testGetStatusCountsReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getStatusCounts();
        $this->assertIsArray($result);
    }
}
