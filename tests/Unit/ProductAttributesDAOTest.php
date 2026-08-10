<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\DAO\ProductAttributesDAO;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: FR-SQUARE-006 item event sync, Stage 3 connector attributes
 */
class ProductAttributesDAOTest extends TestCase
{
    /** @var ProductAttributesDAO */
    private $dao;

    protected function setUp(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $GLOBALS['__fa_last_sql'] = '';
        $this->dao = new ProductAttributesDAO('0_');
    }

    private function seedTable(string $table): void
    {
        $GLOBALS['__fa_result_set']["SHOW TABLES LIKE '0_{$table}'"] = [
            ['Tables_in_test' => '0_' . $table],
        ];
    }

    private function seedRows(array $rows): void
    {
        $GLOBALS['__fa_table'] = $rows;
    }

    public function testGetModifierListsReturnsEmptyWhenTablesMissing(): void
    {
        $result = $this->dao->getModifierLists('SKU-001');

        $this->assertSame([], $result);
        $this->assertStringContainsString('SHOW TABLES', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetModifierListsReturnsListsWithNestedModifiers(): void
    {
        $this->seedTable('product_modifier_lists');
        $this->seedTable('product_modifier_list_assignments');
        $this->seedTable('product_modifiers');
        $this->seedRows([
            [
                'pref_name' => 'SKU-001',
                'id' => '1',
                'name' => 'Size',
                'selection_type' => 'SINGLE',
                'modifier_type' => 'NON_ALCOHOL',
                'min_selected_modifiers' => '1',
                'max_selected_modifiers' => '1',
                'allow_quantities' => '0',
                'hidden_from_customer' => '0',
                'ordinal' => '0',
            ],
        ]);

        $result = $this->dao->getModifierLists('SKU-001');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Size', $result[0]['name']);
        $this->assertSame('SINGLE', $result[0]['selection_type']);
        $this->assertSame('NON_ALCOHOL', $result[0]['modifier_type']);
        $this->assertSame(1, $result[0]['min_selected_modifiers']);
        $this->assertSame(1, $result[0]['max_selected_modifiers']);
        $this->assertSame(0, $result[0]['allow_quantities']);
        $this->assertSame(0, $result[0]['hidden_from_customer']);
        $this->assertSame(0, $result[0]['ordinal']);
        $this->assertArrayHasKey('modifiers', $result[0]);
        $this->assertCount(1, $result[0]['modifiers']);
        $this->assertSame('Size', $result[0]['modifiers'][0]['name']);
        $this->assertNull($result[0]['modifiers'][0]['price']);
    }

    public function testGetModifiersFiltersByListIdInSql(): void
    {
        $this->seedTable('product_modifiers');
        $this->seedRows([]);

        $this->dao->getModifiers(7);

        $this->assertStringContainsString('modifier_list_id = 7', (string)($GLOBALS['__fa_last_sql'] ?? ''));
    }

    public function testGetModifiersReturnsEmptyWhenTableMissing(): void
    {
        $result = $this->dao->getModifiers(5);

        $this->assertSame([], $result);
    }

    public function testGetModifiersReturnsEmptyForNoRows(): void
    {
        $this->seedTable('product_modifiers');
        $this->seedRows([]);

        $result = $this->dao->getModifiers(5);

        $this->assertSame([], $result);
    }

    public function testGetModifiersMapsRows(): void
    {
        $this->seedTable('product_modifiers');
        $this->seedRows([
            ['id' => '10', 'name' => 'Small', 'price' => '0.00', 'on_by_default' => '0', 'ordinal' => '0', 'hidden_online' => '0'],
            ['id' => '11', 'name' => 'Large', 'price' => '2.50', 'on_by_default' => '1', 'ordinal' => '1', 'hidden_online' => '1'],
        ]);

        $result = $this->dao->getModifiers(1);

        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['id']);
        $this->assertSame('Small', $result[0]['name']);
        $this->assertSame('0.00', $result[0]['price']);
        $this->assertSame(0, $result[0]['on_by_default']);
        $this->assertSame(11, $result[1]['id']);
        $this->assertSame('Large', $result[1]['name']);
        $this->assertSame('2.50', $result[1]['price']);
        $this->assertSame(1, $result[1]['on_by_default']);
        $this->assertSame(1, $result[1]['hidden_online']);
    }

    public function testGetModifiersMapsNullPriceWhenMissing(): void
    {
        $this->seedTable('product_modifiers');
        $this->seedRows([
            ['id' => '10', 'name' => 'Plain', 'on_by_default' => '0', 'ordinal' => '0', 'hidden_online' => '0'],
        ]);

        $result = $this->dao->getModifiers(1);

        $this->assertNull($result[0]['price']);
    }

    public function testGetMeasurementUnitIdReturnsNullWhenTableMissing(): void
    {
        $result = $this->dao->getMeasurementUnitId('SKU-001');

        $this->assertNull($result);
    }

    public function testGetMeasurementUnitIdReturnsNullWhenNoRow(): void
    {
        $this->seedTable('product_measurement_units');
        $this->seedRows([]);

        $result = $this->dao->getMeasurementUnitId('SKU-001');

        $this->assertNull($result);
    }

    public function testGetMeasurementUnitIdReturnsValue(): void
    {
        $this->seedTable('product_measurement_units');
        $this->seedRows([
            ['pref_name' => 'SKU-001', 'measurement_unit_id' => 'g:Weight'],
        ]);

        $result = $this->dao->getMeasurementUnitId('SKU-001');

        $this->assertSame('g:Weight', $result);
    }

    public function testGetMeasurementUnitIdReturnsNullForEmptyValue(): void
    {
        $this->seedTable('product_measurement_units');
        $this->seedRows([
            ['pref_name' => 'SKU-001', 'measurement_unit_id' => ''],
        ]);

        $result = $this->dao->getMeasurementUnitId('SKU-001');

        $this->assertNull($result);
    }

    public function testGetCustomAttributesReturnsEmptyWhenTableMissing(): void
    {
        $result = $this->dao->getCustomAttributes('SKU-001');

        $this->assertSame([], $result);
    }

    public function testGetCustomAttributesReturnsMappedRows(): void
    {
        $this->seedTable('product_custom_attributes');
        $this->seedRows([
            ['pref_name' => 'SKU-001', 'attr_key' => 'brand', 'attr_value' => 'Acme'],
            ['pref_name' => 'SKU-001', 'attr_key' => 'upc', 'attr_value' => '12345'],
        ]);

        $result = $this->dao->getCustomAttributes('SKU-001');

        $this->assertCount(2, $result);
        $this->assertSame('brand', $result[0]['attr_key']);
        $this->assertSame('Acme', $result[0]['attr_value']);
        $this->assertSame('upc', $result[1]['attr_key']);
        $this->assertSame('12345', $result[1]['attr_value']);
    }

    public function testGetCategoryParentReturnsNullWhenTableMissing(): void
    {
        $result = $this->dao->getCategoryParent(3);

        $this->assertNull($result);
    }

    public function testGetCategoryParentReturnsNullWhenNoRow(): void
    {
        $this->seedTable('product_category_hierarchy');
        $this->seedRows([]);

        $result = $this->dao->getCategoryParent(3);

        $this->assertNull($result);
    }

    public function testGetCategoryParentReturnsParentId(): void
    {
        $this->seedTable('product_category_hierarchy');
        $this->seedRows([
            ['pref_name' => '3', 'parent_category_id' => '7'],
        ]);

        $result = $this->dao->getCategoryParent(3);

        $this->assertSame(7, $result);
    }

    public function testGetCategoryParentReturnsNullForEmptyParent(): void
    {
        $this->seedTable('product_category_hierarchy');
        $this->seedRows([
            ['pref_name' => '3', 'parent_category_id' => ''],
        ]);

        $result = $this->dao->getCategoryParent(3);

        $this->assertNull($result);
    }

    public function testGetFulfillmentReturnsNullWhenTableMissing(): void
    {
        $result = $this->dao->getFulfillment('SKU-001');

        $this->assertNull($result);
    }

    public function testGetFulfillmentReturnsNullWhenNoRow(): void
    {
        $this->seedTable('product_fulfillment');
        $this->seedRows([]);

        $result = $this->dao->getFulfillment('SKU-001');

        $this->assertNull($result);
    }

    public function testGetFulfillmentReturnsServiceRow(): void
    {
        $this->seedTable('product_fulfillment');
        $this->seedRows([
            [
                'pref_name'               => 'SKU-001',
                'product_type'            => 'SERVICE',
                'service_duration_minutes' => '90',
                'available_for_booking'   => '1',
                'sellable'                => '1',
                'stockable'               => '0',
            ],
        ]);

        $result = $this->dao->getFulfillment('SKU-001');

        $this->assertNotNull($result);
        $this->assertSame('SERVICE', $result['product_type']);
        $this->assertSame(90, $result['service_duration_minutes']);
    }

    public function testGetFulfillmentNormalizesRegularRow(): void
    {
        $this->seedTable('product_fulfillment');
        $this->seedRows([
            [
                'pref_name'               => 'SKU-001',
                'product_type'            => 'REGULAR',
                'service_duration_minutes' => '',
                'available_for_booking'   => '0',
                'sellable'                => '1',
                'stockable'               => '1',
            ],
        ]);

        $result = $this->dao->getFulfillment('SKU-001');

        $this->assertNotNull($result);
        $this->assertSame('REGULAR', $result['product_type']);
        $this->assertNull($result['service_duration_minutes']);
    }
}
