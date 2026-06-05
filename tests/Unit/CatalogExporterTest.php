<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Push\CatalogExporter;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use PHPUnit\Framework\TestCase;
use Square\SquareClient;
use Square\Models\CatalogObject;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemVariation;
use Square\Models\Money;

class CatalogExporterTest extends TestCase
{
    private $mockClient;
    private $mockSettings;
    private $mockCatalogApi;
    private $exporter;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(SquareClient::class);
        $this->mockSettings = $this->createMock(SettingsInterface::class);
        $this->mockCatalogApi = $this->createMock(\Square\Apis\CatalogApi::class);

        $this->mockClient->method('getCatalogApi')->willReturn($this->mockCatalogApi);

        $this->exporter = new CatalogExporter($this->mockClient, $this->mockSettings);
    }

    public function testUpsertProduct(): void
    {
        $mockSearchResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockSearchResult = $this->createMock(\Square\Models\SearchCatalogObjectsResponse::class);
        $mockSearchResponse->method('isSuccess')->willReturn(true);
        $mockSearchResponse->method('getResult')->willReturn($mockSearchResult);
        $mockSearchResult->method('getObjects')->willReturn([]);

        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\UpsertCatalogObjectResponse::class);
        $expectedObject = new CatalogObject('ITEM', '#TEST-SKU');
        $expectedObject->setItemData(new CatalogItem());

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getCatalogObject')->willReturn($expectedObject);

        $this->mockCatalogApi->method('searchCatalogObjects')->willReturn($mockSearchResponse);
        $this->mockCatalogApi->expects($this->exactly(2))
            ->method('upsertCatalogObject')
            ->willReturn($mockResponse);

        $result = $this->exporter->upsertProduct('TEST-SKU', 'Test Item', 'Test Description', 'General', 1000);

        $this->assertSame($expectedObject, $result);
    }

    public function testUpsertProductFailure(): void
    {
        $mockSearchResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockSearchResult = $this->createMock(\Square\Models\SearchCatalogObjectsResponse::class);
        $mockSearchResponse->method('isSuccess')->willReturn(true);
        $mockSearchResponse->method('getResult')->willReturn($mockSearchResult);
        $mockSearchResult->method('getObjects')->willReturn([]);

        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(false);

        $this->mockCatalogApi->method('searchCatalogObjects')->willReturn($mockSearchResponse);
        $this->mockCatalogApi->method('upsertCatalogObject')->willReturn($mockResponse);

        $this->expectException(SquareException::class);
        $this->exporter->upsertProduct('TEST-SKU', 'Test', 'Test', 'Cat', 1000);
    }

    public function testBatchUpsertProducts(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\BatchUpsertCatalogObjectsResponse::class);

        $products = [
            ['sku' => 'SKU-1', 'name' => 'Item 1', 'price_cents' => 1000],
            ['sku' => 'SKU-2', 'name' => 'Item 2', 'price_cents' => 2000],
        ];

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getObjects')->willReturn([new CatalogObject('ITEM', '#SKU-1')]);

        $this->mockCatalogApi->expects($this->once())
            ->method('batchUpsertCatalogObjects')
            ->willReturn($mockResponse);

        $result = $this->exporter->batchUpsertProducts($products);
        $this->assertCount(1, $result);
    }

    public function testPushInventory(): void
    {
        $mockInventoryApi = $this->createMock(\Square\Apis\InventoryApi::class);
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockInventoryApi->method('batchChangeInventory')->willReturn($mockResponse);
        $this->mockClient->method('getInventoryApi')->willReturn($mockInventoryApi);

        $this->exporter->pushInventory('OBJ-001', 'LOC-001', 10.0);
        $this->assertTrue(true);
    }

    public function testGetInventoryCount(): void
    {
        $mockInventoryApi = $this->createMock(\Square\Apis\InventoryApi::class);
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\RetrieveInventoryCountResponse::class);
        $mockCount = $this->createMock(\Square\Models\InventoryCount::class);

        $mockCount->method('getQuantity')->willReturn('42');
        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getCounts')->willReturn([$mockCount]);
        $this->mockClient->method('getInventoryApi')->willReturn($mockInventoryApi);
        $mockInventoryApi->method('retrieveInventoryCount')->willReturn($mockResponse);

        $result = $this->exporter->getInventoryCount('OBJ-001', 'LOC-001');
        $this->assertNotNull($result);
        $this->assertSame('42', $result->getQuantity());
    }

    public function testDeleteProduct(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(true);

        $this->mockCatalogApi->expects($this->once())
            ->method('deleteCatalogObject')
            ->willReturn($mockResponse);

        $this->exporter->deleteProduct('OBJ-001');
        $this->assertTrue(true);
    }

    public function testSearchProductBySku(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\SearchCatalogObjectsResponse::class);
        $expectedObject = new CatalogObject('ITEM', '#FOUND');

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getObjects')->willReturn([$expectedObject]);

        $this->mockCatalogApi->expects($this->once())
            ->method('searchCatalogObjects')
            ->willReturn($mockResponse);

        $result = $this->exporter->searchProductBySku('FOUND');
        $this->assertSame($expectedObject, $result);
    }

    public function testSearchProductBySkuNotFound(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\SearchCatalogObjectsResponse::class);

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getObjects')->willReturn([]);

        $this->mockCatalogApi->method('searchCatalogObjects')->willReturn($mockResponse);

        $result = $this->exporter->searchProductBySku('NONEXIST');
        $this->assertNull($result);
    }

    public function testListAllItems(): void
    {
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult = $this->createMock(\Square\Models\ListCatalogResponse::class);
        $objects = [new CatalogObject('ITEM', '#1'), new CatalogObject('ITEM', '#2')];

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockResponse->method('getResult')->willReturn($mockResult);
        $mockResult->method('getObjects')->willReturn($objects);
        $mockResult->method('getCursor')->willReturn(null);

        $this->mockCatalogApi->expects($this->once())
            ->method('listCatalog')
            ->willReturn($mockResponse);

        $result = $this->exporter->listAllItems();
        $this->assertCount(2, $result);
    }

    public function testBatchPushInventory(): void
    {
        $mockInventoryApi = $this->createMock(\Square\Apis\InventoryApi::class);
        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);

        $mockResponse->method('isSuccess')->willReturn(true);
        $mockInventoryApi->method('batchChangeInventory')->willReturn($mockResponse);
        $this->mockClient->method('getInventoryApi')->willReturn($mockInventoryApi);

        $changes = [
            ['catalog_object_id' => 'OBJ-1', 'location_id' => 'LOC-1', 'quantity' => 5],
            ['catalog_object_id' => 'OBJ-2', 'location_id' => 'LOC-2', 'quantity' => 10],
        ];

        $this->exporter->batchPushInventory($changes);
        $this->assertTrue(true);
    }

    public function testUploadImageWithRealFile(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required for image upload test');
        }

        $tempDir = sys_get_temp_dir() . '/sq_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $imgPath = $tempDir . '/test-product.jpg';
        $img = \imagecreatetruecolor(200, 150);
        $bg = \imagecolorallocate($img, 255, 0, 0);
        \imagefill($img, 0, 0, $bg);
        \imagejpeg($img, $imgPath, 90);
        \imagedestroy($img);

        $mockResponse = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResponse->method('isSuccess')->willReturn(true);

        $this->mockCatalogApi->expects($this->once())
            ->method('createCatalogImage')
            ->willReturn($mockResponse);

        $result = $this->exporter->uploadImage('OBJ-001', 'test-product', 'Test Product', $tempDir, 0, true);

        unlink($imgPath);
        rmdir($tempDir);

        $this->assertTrue($result);
    }

    public function testUploadImageReturnsFalseWhenFileMissing(): void
    {
        $result = $this->exporter->uploadImage('OBJ-001', 'nonexistent', 'N/A', '/tmp/no_images', 0);

        $this->assertFalse($result);
    }

    public function testUploadImageReturnsFalseWhenNotJpeg(): void
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->markTestSkipped('GD extension required for image load test');
        }

        $tempDir = sys_get_temp_dir() . '/sq_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $imgPath = $tempDir . '/bad-file.jpg';
        file_put_contents($imgPath, 'not a real jpeg');

        $result = $this->exporter->uploadImage('OBJ-001', 'bad-file', 'Bad', $tempDir, 0);

        unlink($imgPath);
        rmdir($tempDir);

        $this->assertFalse($result);
    }
}
