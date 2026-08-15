<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use ksfraser\FrontAccounting\Square\DAO\ItemStagingDAO;
use PHPUnit\Framework\TestCase;

class ItemStagingDAOTest extends TestCase
{
    /**
     * @var ItemStagingDAO
     */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->dao = new ItemStagingDAO('0_');
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ItemStagingDAO::class, $this->dao);
    }

    public function testGetTableName(): void
    {
        $tableName = $this->dao->getTableName();
        $this->assertStringContainsString('ksf_import_square_items', $tableName);
    }

    public function testEnsureTableExists(): void
    {
        $GLOBALS['__fa_result_set'][] = [
            ['Tables_in_test' => '0_ksf_import_square_items'],
        ];
        $GLOBALS['__fa_result_pos'][] = 0;

        $this->dao->ensureTableExists();
        $this->assertTrue(true);
    }

    public function testGetByTransactionIdReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getByTransactionId('txn_001');
        $this->assertIsArray($result);
    }

    public function testGetByPaymentIdReturnsArray(): void
    {
        $GLOBALS['__fa_result_set'][] = [];
        $GLOBALS['__fa_result_pos'][] = 0;

        $result = $this->dao->getByPaymentId('pmt_001');
        $this->assertIsArray($result);
    }
}
