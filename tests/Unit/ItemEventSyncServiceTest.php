<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\DAO\ProductAttributesDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareTokenDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Ksfraser\Frontaccounting\SquareUp\Push\CatalogExporter;
use Ksfraser\Frontaccounting\SquareUp\Push\ItemEventSyncService;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxRateResolver;
use PHPUnit\Framework\TestCase;
use Square\Models\CatalogItem;
use Square\Models\CatalogObject;

/**
 * @BABOK Related: FR-SQUARE-006 item event sync
 */
class ItemEventSyncServiceTest extends TestCase
{
    private $mockSettings;
    private $mockExporter;
    private $mockStockDao;
    private $mockTokenDao;
    private $mockAttrDao;

    protected function setUp(): void
    {
        $this->mockSettings = $this->createMock(SettingsInterface::class);
        $this->mockExporter = $this->createMock(CatalogExporter::class);
        $this->mockStockDao = $this->createMock(StockMasterDAO::class);
        $this->mockTokenDao = $this->createMock(SquareTokenDAO::class);
        $this->mockAttrDao = $this->createMock(ProductAttributesDAO::class);
    }

    private function defaultItemRow(): array
    {
        return [
            'stock_id'       => 'SKU-001',
            'description'    => 'Test Widget',
            'units'          => 'each',
            'inactive'       => '0',
            'category_id'    => '1',
            'cat_description' => 'General',
            'tax_name'       => 'GST',
            'exempt'         => '0',
        ];
    }

    /**
     * Apply default mock returns. Because the first configuration of a mock
     * method wins, tests should call this AFTER configuring their own
     * overrides so the defaults only fill in methods the test left alone.
     */
    private function configureDefaults(): void
    {
        $this->mockSettings->method('getAccessToken')->willReturn('test-token');
        $this->mockStockDao->method('getItemForSync')->willReturn($this->defaultItemRow());
        $this->mockStockDao->method('getItemSku')->willReturn(null);
        $this->mockStockDao->method('getItemPrice')->willReturn(12.34);
        $this->mockAttrDao->method('getMeasurementUnitId')->willReturn(null);
        $this->mockAttrDao->method('getCustomAttributes')->willReturn([]);
        $this->mockAttrDao->method('getModifierLists')->willReturn([]);
        $this->mockAttrDao->method('getCategoryParent')->willReturn(null);
        $this->mockAttrDao->method('getUpc')->willReturn(null);
    }

    /**
     * The normalized attributes bag the service passes to the exporter when
     * no Stage 3 data is present.
     */
    private function defaultAttributes(): array
    {
        return [
            'measurement_unit_id'  => null,
            'custom_attributes'    => [],
            'modifier_lists'       => [],
            'category_parent_name' => null,
            'fulfillment'          => null,
            'upc'                  => null,
        ];
    }

    private function buildService(string $currency = 'CAD', int $salesType = 1, ?TaxRateResolver $resolver = null): ItemEventSyncService
    {
        return new ItemEventSyncService(
            $this->mockSettings,
            $this->mockExporter,
            $this->mockStockDao,
            $this->mockTokenDao,
            $currency,
            $salesType,
            $resolver ?? new TaxRateResolver(),
            $this->mockAttrDao
        );
    }

    private function catalogObjectMock(string $id = 'SQ-OBJ-1', ?string $variationId = 'SQ-VAR-1'): CatalogObject
    {
        $mock = $this->createMock(CatalogObject::class);
        $mock->method('getId')->willReturn($id);
        $itemData = $this->createMock(CatalogItem::class);
        $variation = $this->createMock(CatalogObject::class);
        $variation->method('getId')->willReturn($variationId);
        $itemData->method('getVariations')->willReturn([$variation]);
        $mock->method('getItemData')->willReturn($itemData);
        return $mock;
    }

    public function testSyncSkipsWhenNoTokenConfigured(): void
    {
        $this->mockSettings->method('getAccessToken')->willReturn(null);
        $this->configureDefaults();
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame(['status' => 'skipped', 'event' => 'created', 'reason' => 'no_token'], $result);
        $this->mockExporter->expects($this->never())->method('upsertProduct');
    }

    public function testSyncSkipsWhenItemNotFound(): void
    {
        $this->mockStockDao->method('getItemForSync')->willReturn(null);
        $this->configureDefaults();
        $service = $this->buildService();

        $result = $service->sync('SKU-GONE', 'updated');

        $this->assertSame(['status' => 'skipped', 'event' => 'updated', 'reason' => 'not_found'], $result);
        $this->mockExporter->expects($this->never())->method('upsertProduct');
    }

    public function testSyncSkipsInactiveItem(): void
    {
        $this->mockStockDao->method('getItemForSync')->willReturn([
            'stock_id' => 'SKU-001', 'description' => 'Old', 'inactive' => '1',
        ]);
        $this->configureDefaults();
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'updated');

        $this->assertSame(['status' => 'skipped', 'event' => 'updated', 'reason' => 'inactive'], $result);
        $this->mockExporter->expects($this->never())->method('upsertProduct');
    }

    public function testSyncPushesNewItem(): void
    {
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with(
                'SKU-001',        // sku
                'Test Widget',    // name
                'Test Widget',    // description
                'General',        // category
                1234,             // price cents (12.34 CAD)
                'CAD',
                'GST',
                0.0,
                null,
                $this->defaultAttributes()
            )
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame('pushed', $result['status']);
        $this->assertSame('created', $result['event']);
        $this->assertSame('SQ-OBJ-1', $result['square_id']);
    }

    public function testSyncUsesBarcodeSkuWhenAvailable(): void
    {
        $this->mockStockDao->method('getItemSku')->willReturn('BARCODE-123');
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('BARCODE-123', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame('pushed', $result['status']);
    }

    public function testSyncFallsBackToStockIdWhenNoBarcode(): void
    {
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame('pushed', $result['status']);
    }

    public function testSyncResolvesTaxRateFromConfiguredTaxGroup(): void
    {
        $this->mockSettings->method('getDefaultTaxGroup')->willReturn(4);
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return $groupId === 4 ? 13.0 : 0.0;
        });
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 13.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService('CAD', 1, $resolver);

        $service->sync('SKU-001', 'created');
    }

    public function testSyncUsesSquarePriceCents(): void
    {
        $this->mockStockDao->method('getItemPrice')->willReturn(9.99);
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 999, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'updated');
        $this->assertTrue(true);
    }

    public function testSyncDefaultsCurrencyToCadWhenNotProvided(): void
    {
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService('');

        $service->sync('SKU-001', 'created');
        $this->assertTrue(true);
    }

    public function testSyncRecordsSquareMapping(): void
    {
        $this->configureDefaults();
        $this->mockExporter->method('upsertProduct')->willReturn($this->catalogObjectMock());
        $this->mockTokenDao->method('getFaLastUpdated')->willReturn('2026-07-31 10:00:00');
        $this->mockTokenDao->expects($this->once())
            ->method('upsertToken')
            ->with('SKU-001', 'SKU-001', 'SQ-OBJ-1', 'SQ-VAR-1', '2026-07-31 10:00:00');
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncRecordsMappingWithNullVariation(): void
    {
        $this->configureDefaults();
        $mock = $this->createMock(CatalogObject::class);
        $mock->method('getId')->willReturn('SQ-OBJ-2');
        $mock->method('getItemData')->willReturn(null);
        $this->mockExporter->method('upsertProduct')->willReturn($mock);
        $this->mockTokenDao->expects($this->once())
            ->method('upsertToken')
            ->with('SKU-001', 'SKU-001', 'SQ-OBJ-2', null, null);
        $service = $this->buildService();

        $service->sync('SKU-001', 'updated');
    }

    public function testSyncReturnsFailedOnSquareException(): void
    {
        $this->configureDefaults();
        $this->mockExporter->method('upsertProduct')
            ->will($this->throwException(new SquareException('Square is down')));
        $this->mockTokenDao->expects($this->never())->method('upsertToken');
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Square is down', $result['reason']);
    }

    public function testSyncReturnsFailedOnApiException(): void
    {
        $this->configureDefaults();
        $this->mockExporter->method('upsertProduct')
            ->will($this->throwException($this->createMock(\Square\Exceptions\ApiException::class)));
        $this->mockTokenDao->expects($this->never())->method('upsertToken');
        $service = $this->buildService();

        $result = $service->sync('SKU-001', 'created');

        $this->assertSame('failed', $result['status']);
    }

    /**
     * Expected attributes bag for the given overrides.
     */
    private function expectedAttributes(array $overrides = []): array
    {
        return array_merge($this->defaultAttributes(), $overrides);
    }

    public function testSyncPassesMeasurementUnitToExporter(): void
    {
        $this->mockAttrDao->method('getMeasurementUnitId')->willReturn('g:Weight');
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['measurement_unit_id' => 'g:Weight']))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncPassesCustomAttributesToExporter(): void
    {
        $customAttributes = [
            ['stock_id' => 'SKU-001', 'attr_key' => 'ABV', 'attr_value' => '12'],
        ];
        $this->mockAttrDao->method('getCustomAttributes')->willReturn($customAttributes);
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['custom_attributes' => $customAttributes]))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncPassesModifierListsToExporter(): void
    {
        $modifierLists = [
            [
                'id'          => '7',
                'name'        => 'Size',
                'selection_type' => 'SINGLE',
                'min_selected_modifiers' => null,
                'max_selected_modifiers' => null,
                'allow_quantities' => '0',
                'hidden_from_customer' => '0',
                'ordinal'     => '1',
                'modifiers'   => [
                    ['id' => '71', 'name' => 'Large', 'price' => '2.50', 'ordinal' => '1'],
                ],
            ],
        ];
        $this->mockAttrDao->method('getModifierLists')->willReturn($modifierLists);
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['modifier_lists' => $modifierLists]))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncResolvesCategoryParentName(): void
    {
        $this->mockAttrDao->method('getCategoryParent')->willReturn(5);
        $this->mockStockDao->method('getCategoryName')->willReturn('Beverages');
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['category_parent_name' => 'Beverages']))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncSkipsCategoryParentResolutionWithoutCategory(): void
    {
        $row = $this->defaultItemRow();
        unset($row['category_id']);
        $this->mockStockDao->method('getItemForSync')->willReturn($row);
        $this->configureDefaults();
        $this->mockAttrDao->expects($this->never())->method('getCategoryParent');
        $this->mockStockDao->expects($this->never())->method('getCategoryName');
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncSkipsCategoryNameLookupWhenNoParentRow(): void
    {
        $this->configureDefaults();
        $this->mockStockDao->expects($this->never())->method('getCategoryName');
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->defaultAttributes())
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncPassesFulfillmentToExporter(): void
    {
        $fulfillment = [
            'product_type'             => 'SERVICE',
            'service_duration_minutes' => 90,
        ];
        $this->mockAttrDao->method('getFulfillment')->willReturn($fulfillment);
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['fulfillment' => $fulfillment]))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }

    public function testSyncPassesUpcToExporter(): void
    {
        $this->mockAttrDao->method('getUpc')->willReturn('123456789012');
        $this->configureDefaults();
        $this->mockExporter->expects($this->once())
            ->method('upsertProduct')
            ->with('SKU-001', 'Test Widget', 'Test Widget', 'General', 1234, 'CAD', 'GST', 0.0, null, $this->expectedAttributes(['upc' => '123456789012']))
            ->willReturn($this->catalogObjectMock());
        $service = $this->buildService();

        $service->sync('SKU-001', 'created');
    }
}
