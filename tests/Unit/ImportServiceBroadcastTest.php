<?php

namespace {
    if (!function_exists('hook_invoke_all')) {
        /**
         * Neutral test double for FrontAccounting's hook_invoke_all().
         *
         * Records the broadcast name and payload so tests can assert the
         * order_imported event fired with the correct data.
         */
        function hook_invoke_all($method, &$data, $opts = null)
        {
            $GLOBALS['ksf_test_broadcasts'][] = [$method, $data, $opts];
        }
    }
}

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit\Services {

use Ksfraser\Frontaccounting\SquareUp\Services\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Square import flow broadcasts order_imported events so
 * other ksf modules (HRM commissions, ProjectManagement revenue) can react.
 *
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-SI-003 - Inter-module import event broadcast
 */
class ImportServiceBroadcastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['ksf_test_broadcasts'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['ksf_test_broadcasts']);
    }

    /**
     * @test
     */
    public function broadcastOrderImportedFiresEventWithDefaults(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();

        // Act
        $this->invokeMethod($service, 'broadcastOrderImported', [[]]);

        // Assert
        $broadcasts = $GLOBALS['ksf_test_broadcasts'];
        $this->assertCount(1, $broadcasts);
        $this->assertEquals('order_imported', $broadcasts[0][0]);
        $data = $broadcasts[0][1];
        $this->assertEquals('square', $data['source']);
        $this->assertArrayHasKey('source_order_id', $data);
        $this->assertArrayHasKey('fa_order_no', $data);
        $this->assertArrayHasKey('customer_id', $data);
        $this->assertArrayHasKey('order_total', $data);
        $this->assertArrayHasKey('order_date', $data);
        $this->assertArrayHasKey('currency', $data);
    }

    /**
     * @test
     */
    public function broadcastOrderImportedMergesPayload(): void
    {
        // Arrange
        $service = $this->newInstanceWithoutConstructor();

        // Act
        $this->invokeMethod($service, 'broadcastOrderImported', [[
            'source_order_id' => 'PAY_123',
            'fa_order_no' => 456,
            'fa_trans_type' => ST_SALESINVOICE,
            'customer_id' => 77,
            'order_total' => 99.50,
            'order_date' => '2026-08-10',
            'currency' => 'USD',
        ]]);

        // Assert
        $broadcasts = $GLOBALS['ksf_test_broadcasts'];
        $this->assertCount(1, $broadcasts);
        $data = $broadcasts[0][1];
        $this->assertEquals('square', $data['source']);
        $this->assertEquals('PAY_123', $data['source_order_id']);
        $this->assertEquals(456, $data['fa_order_no']);
        $this->assertEquals(ST_SALESINVOICE, $data['fa_trans_type']);
        $this->assertEquals(77, $data['customer_id']);
        $this->assertEquals(99.50, $data['order_total']);
        $this->assertEquals('2026-08-10', $data['order_date']);
        $this->assertEquals('USD', $data['currency']);
    }

    /**
     * Helper method to invoke private methods
     */
    private function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    private function newInstanceWithoutConstructor(): ImportService
    {
        $reflection = new \ReflectionClass(ImportService::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
}