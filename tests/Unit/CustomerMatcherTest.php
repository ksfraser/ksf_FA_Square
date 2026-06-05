<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Staging\CustomerMatcher;
use PHPUnit\Framework\TestCase;

class CustomerMatcherTest extends TestCase
{
    private CustomerMatcher $matcher;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $this->matcher = new CustomerMatcher('0_');
    }

    public function testFindOrCreateDebtorCreatesNew(): void
    {
        $debtorNo = $this->matcher->findOrCreateDebtor([
            'name' => 'New Customer',
            'email' => 'new@example.com',
        ]);

        $this->assertIsInt($debtorNo);
    }

    public function testFindOrCreateDebtorByEmail(): void
    {
        $GLOBALS['__fa_table'] = [
            ['pref_name' => 'debtor_no', 'pref_value' => '42'],
        ];

        $debtorNo = $this->matcher->findOrCreateDebtor([
            'name' => 'Existing',
            'email' => 'existing@example.com',
        ]);

        $this->assertIsInt($debtorNo);
    }

    public function testMatchSquareCustomerToFaDebtorNotFound(): void
    {
        $result = $this->matcher->matchSquareCustomerToFaDebtor('sq_cus_001');
        $this->assertNull($result);
    }

    public function testLinkSquareCustomer(): void
    {
        $this->matcher->linkSquareCustomer('sq_cus_001', 42);
        $this->assertTrue(true);
    }

    public function testFindOrCreateBranchCreatesNew(): void
    {
        $branchCode = $this->matcher->findOrCreateBranch(42, [
            'branch_code' => 'BR-001',
            'name' => 'Main Branch',
        ]);

        $this->assertIsInt($branchCode);
    }
}
