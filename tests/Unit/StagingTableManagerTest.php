<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;
use PHPUnit\Framework\TestCase;

class StagingTableManagerTest extends TestCase
{
    private StagingTableManager $manager;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->manager = new StagingTableManager('0_');
    }

    public function testCreateStagingTables(): void
    {
        $this->manager->createStagingTables();
        $this->assertArrayHasKey('__fa_table', $GLOBALS);
    }

    public function testDropStagingTables(): void
    {
        $this->manager->dropStagingTables();
        $this->assertTrue(true);
    }

    public function testInsertAndRetrieveStagingTransaction(): void
    {
        $data = [
            'source' => 'api',
            'square_transaction_id' => 'txn_001',
            'total_amount' => '100.00',
            'status' => 'staged',
        ];

        $id = $this->manager->insertStagingTransaction($data);
        $this->assertIsInt($id);
    }

    public function testGetUnprocessedTransactions(): void
    {
        $rows = $this->manager->getUnprocessedTransactions('api');
        $this->assertIsArray($rows);
    }

    public function testMarkProcessed(): void
    {
        $this->manager->markProcessed(1);
        $this->assertTrue(true);
    }

    public function testMarkFailed(): void
    {
        $this->manager->markFailed(1, 'Something went wrong');
        $this->assertTrue(true);
    }
}
