<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Push;

use Ksfraser\Frontaccounting\SquareUp\Contracts\CatalogExporterInterface;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Square\SquareClient;
use Square\Exceptions\ApiException;
use Square\Models\CatalogObject;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogCategory;
use Square\Models\CatalogTax;
use Square\Models\Money;
use Square\Models\UpsertCatalogObjectRequest;
use Square\Models\BatchUpsertCatalogObjectsRequest;
use Square\Models\CatalogObjectBatch;
use Square\Models\BatchChangeInventoryRequest;
use Square\Models\InventoryChange;
use Square\Models\InventoryAdjustment;
use Square\Models\CatalogImage;
use Square\Models\CreateCatalogImageRequest;
use Square\Utils\FileWrapper;

class CatalogExporter implements CatalogExporterInterface
{
    private SquareClient $client;
    private SettingsInterface $settings;

    public function __construct(SquareClient $client, SettingsInterface $settings)
    {
        $this->client = $client;
        $this->settings = $settings;
    }

    public function upsertProduct(
        string $sku,
        string $name,
        string $description,
        string $categoryName,
        int $priceCents,
        string $currency = 'CAD',
        string $taxName = '',
        float $taxRate = 0.0
    ): CatalogObject {
        try {
            $body = $this->buildCatalogObject($sku, $name, $description, $categoryName, $priceCents, $currency, $taxName, $taxRate);

            $request = new UpsertCatalogObjectRequest(uniqid('', true), $body);

            $response = $this->client->getCatalogApi()->upsertCatalogObject($request);

            if (!$response->isSuccess()) {
                $errors = $response->getErrors();
                $detail = '';
                if ($errors !== null) {
                    $parts = [];
                    foreach ($errors as $err) {
                        $parts[] = '[' . $err->getCode() . '] ' . $err->getDetail() . ($err->getField() ? ' (field: ' . $err->getField() . ')' : '');
                    }
                    $detail = ' | ' . implode('; ', $parts);
                }
                throw SquareException::apiError(
                    'upsertCatalogObject',
                    'Failed to upsert product' . $detail,
                    $response->getErrors()
                );
            }

            return $response->getResult()->getCatalogObject();
        } catch (ApiException $e) {
            throw SquareException::apiError('upsertCatalogObject', $e->getMessage());
        }
    }

    public function batchUpsertProducts(array $products): array
    {
        $batches = [];
        $objects = [];

        foreach ($products as $product) {
            $sku = $product['sku'];
            $name = $product['name'];
            $description = $product['description'] ?? $name;
            $categoryName = $product['category'] ?? null;
            $priceCents = $product['price_cents'] ?? 0;
            $currency = $product['currency'] ?? 'CAD';
            $taxName = $product['tax_name'] ?? '';
            $taxRate = $product['tax_rate'] ?? 0.0;

            $body = $this->buildCatalogObject($sku, $name, $description, $categoryName, $priceCents, $currency, $taxName, $taxRate);
            $objects[] = $body;
        }

        $batch = new CatalogObjectBatch($objects);
        $batches[] = $batch;

        try {
            $request = new BatchUpsertCatalogObjectsRequest(uniqid('', true), $batches);
            $response = $this->client->getCatalogApi()->batchUpsertCatalogObjects($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'batchUpsertCatalogObjects',
                    'Batch upsert failed',
                    $response->getErrors()
                );
            }

            return $response->getResult()->getObjects();
        } catch (ApiException $e) {
            throw SquareException::apiError('batchUpsertCatalogObjects', $e->getMessage());
        }
    }

    public function pushInventory(string $catalogObjectId, string $locationId, float $quantity): void
    {
        try {
            $adjustment = new InventoryAdjustment();
            $adjustment->setCatalogObjectId($catalogObjectId);
            $adjustment->setLocationId($locationId);
            $adjustment->setQuantity((string)$quantity);
            $adjustment->setFromState('NONE');
            $adjustment->setToState('IN_STOCK');

            $change = new InventoryChange();
            $change->setType('ADJUSTMENT');
            $change->setAdjustment($adjustment);

            $request = new BatchChangeInventoryRequest(uniqid('', true), [$change]);

            $response = $this->client->getInventoryApi()->batchChangeInventory($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'batchChangeInventory',
                    'Failed to push inventory count',
                    $response->getErrors()
                );
            }
        } catch (ApiException $e) {
            throw SquareException::apiError('batchChangeInventory', $e->getMessage());
        }
    }

    public function batchPushInventory(array $inventoryChanges): void
    {
        $changes = [];
        foreach ($inventoryChanges as $item) {
            $adjustment = new InventoryAdjustment();
            $adjustment->setCatalogObjectId($item['catalog_object_id']);
            $adjustment->setLocationId($item['location_id']);
            $adjustment->setQuantity((string)$item['quantity']);
            $adjustment->setFromState('NONE');
            $adjustment->setToState('IN_STOCK');

            $change = new InventoryChange();
            $change->setType('ADJUSTMENT');
            $change->setAdjustment($adjustment);

            $changes[] = $change;
        }

        try {
            $request = new BatchChangeInventoryRequest(uniqid('', true), $changes);
            $response = $this->client->getInventoryApi()->batchChangeInventory($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'batchChangeInventory',
                    'Batch inventory push failed',
                    $response->getErrors()
                );
            }
        } catch (ApiException $e) {
            throw SquareException::apiError('batchChangeInventory', $e->getMessage());
        }
    }

    public function getInventoryCount(string $catalogObjectId, string $locationId): ?\Square\Models\InventoryCount
    {
        try {
            $response = $this->client->getInventoryApi()->retrieveInventoryCount($catalogObjectId, $locationId);

            if (!$response->isSuccess()) {
                return null;
            }

            $counts = $response->getResult()->getCounts();
            return $counts[0] ?? null;
        } catch (ApiException $e) {
            throw SquareException::apiError('retrieveInventoryCount', $e->getMessage());
        }
    }

    public function deleteProduct(string $catalogObjectId): void
    {
        try {
            $response = $this->client->getCatalogApi()->deleteCatalogObject($catalogObjectId);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'deleteCatalogObject',
                    'Failed to delete product',
                    $response->getErrors()
                );
            }
        } catch (ApiException $e) {
            throw SquareException::apiError('deleteCatalogObject', $e->getMessage());
        }
    }

    public function searchProductBySku(string $sku): ?CatalogObject
    {
        try {
            $searchRequest = new \Square\Models\SearchCatalogObjectsRequest();
            $searchRequest->setObjectTypes(['ITEM']);
            $query = new \Square\Models\CatalogQuery();
            $query->setPrefixQuery(new \Square\Models\CatalogQueryPrefix('sku', $sku));
            $searchRequest->setQuery($query);

            $response = $this->client->getCatalogApi()->searchCatalogObjects($searchRequest);

            if (!$response->isSuccess() || empty($response->getResult()->getObjects())) {
                return null;
            }

            return $response->getResult()->getObjects()[0];
        } catch (ApiException $e) {
            throw SquareException::apiError('searchCatalogObjects', $e->getMessage());
        }
    }

    public function listAllItems(): array
    {
        $items = [];
        $cursor = null;

        try {
            do {
                $response = $this->client->getCatalogApi()->listCatalog($cursor, 'ITEM');

                if ($response->isSuccess()) {
                    $result = $response->getResult();
                    if ($result->getObjects() !== null) {
                        $items = array_merge($items, $result->getObjects());
                    }
                    $cursor = $result->getCursor();
                } else {
                    break;
                }
            } while ($cursor !== null);

            return $items;
        } catch (ApiException $e) {
            throw SquareException::apiError('listCatalog', $e->getMessage());
        }
    }

    public function uploadImage(
        string $catalogObjectId,
        string $stockId,
        string $description,
        string $imageDir,
        int $imageIndex = 0,
        bool $isPrimary = false
    ): bool {
        $filename = $imageIndex === 0
            ? $stockId . '.jpg'
            : $stockId . '-' . $imageIndex . '.jpg';
        $filePath = rtrim($imageDir, '/') . '/' . $filename;

        if (!file_exists($filePath)) {
            return false;
        }

        try {
            $srcImg = @imagecreatefromjpeg($filePath);
            if ($srcImg === false) {
                return false;
            }

            $oldX = imagesx($srcImg);
            $oldY = imagesy($srcImg);
            $dim = 600;
            $ratio1 = $oldX / $dim;
            $ratio2 = $oldY / $dim;
            if ($ratio1 > $ratio2) {
                $thumbW = $dim;
                $thumbH = (int)($oldY / $ratio1);
            } else {
                $thumbH = $dim;
                $thumbW = (int)($oldX / $ratio2);
            }

            $finalImg = imagecreatetruecolor($dim, $dim);
            $bg = imagecolorallocate($finalImg, 255, 255, 255);
            imagefilledrectangle($finalImg, 0, 0, $dim, $dim, $bg);
            $dstX = (int)(($dim - $thumbW) / 2);
            $dstY = (int)(($dim - $thumbH) / 2);
            imagecopyresampled($finalImg, $srcImg, $dstX, $dstY, 0, 0, $thumbW, $thumbH, $oldX, $oldY);

            $tempFile = tempnam(sys_get_temp_dir(), 'sq_img_') . '.jpeg';
            imagejpeg($finalImg, $tempFile, 90);
            imagedestroy($srcImg);
            imagedestroy($finalImg);

            $imageObj = new CatalogObject('IMAGE', '#img_' . $stockId . ($imageIndex > 0 ? '_' . $imageIndex : ''));
            $imgData = new CatalogImage();
            $imgData->setCaption($description);
            $imageObj->setImageData($imgData);

            $request = new CreateCatalogImageRequest(uniqid('', true), $imageObj);
            $request->setObjectId($catalogObjectId);
            $request->setIsPrimary($isPrimary);

            $imageFile = FileWrapper::createFromPath($tempFile, 'image/jpeg');
            $response = $this->client->getCatalogApi()->createCatalogImage($request, $imageFile);

            unlink($tempFile);

            return $response->isSuccess();
        } catch (ApiException $e) {
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            throw SquareException::apiError('createCatalogImage', $e->getMessage());
        } catch (\Exception $e) {
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return false;
        }
    }

    private function sanitizeUtf8(string $value): string
    {
        if (preg_match('//u', $value)) {
            return $value;
        }
        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function buildCatalogObject(
        string $sku,
        string $name,
        string $description,
        ?string $categoryName,
        int $priceCents,
        string $currency,
        string $taxName,
        float $taxRate
    ): CatalogObject {
        $sku = $this->sanitizeUtf8($sku);
        $name = $this->sanitizeUtf8($name);
        $description = $this->sanitizeUtf8($description);
        $categoryId = null;
        if ($categoryName !== null && $categoryName !== '') {
            $categoryId = $this->resolveCategory($this->sanitizeUtf8($categoryName));
        }
        $taxName = $this->sanitizeUtf8($taxName);

        $variationData = new CatalogItemVariation();
        $variationData->setName($name);
        $variationData->setSku($sku);
        $variationData->setPricingType('FIXED_PRICING');
        $variationData->setPriceMoney(new Money());
        $variationData->getPriceMoney()->setAmount($priceCents);
        $variationData->getPriceMoney()->setCurrency($currency);
        $variationData->setTrackInventory(true);

        $variationObject = new CatalogObject('ITEM_VARIATION', '#' . $sku);
        $variationObject->setItemVariationData($variationData);

        $item = new CatalogItem();
        $item->setName($name);
        $item->setDescription($description);
        if ($categoryId !== null) {
            $item->setCategoryId($categoryId);
        }
        $item->setVariations([$variationObject]);

        if ($taxName !== '') {
            $taxId = $this->resolveTax($taxName, $taxRate);
            $item->setTaxIds([$taxId]);
        }

        $body = new CatalogObject('ITEM', '#' . $sku);
        $body->setItemData($item);

        return $body;
    }

    private function resolveCategory(string $categoryName): ?string
    {
        try {
            $searchRequest = new \Square\Models\SearchCatalogObjectsRequest();
            $searchRequest->setObjectTypes(['CATEGORY']);
            $query = new \Square\Models\CatalogQuery();
            $query->setExactQuery(new \Square\Models\CatalogQueryExact('name', $categoryName));
            $searchRequest->setQuery($query);

            $response = $this->client->getCatalogApi()->searchCatalogObjects($searchRequest);

            if ($response->isSuccess() && !empty($response->getResult()->getObjects())) {
                return $response->getResult()->getObjects()[0]->getId();
            }

            $category = new CatalogCategory();
            $category->setName($categoryName);

            $body = new CatalogObject('CATEGORY', '#' . preg_replace('/[^a-zA-Z0-9_]/', '_', $categoryName));
            $body->setCategoryData($category);

            $request = new UpsertCatalogObjectRequest(uniqid('', true), $body);
            $createResponse = $this->client->getCatalogApi()->upsertCatalogObject($request);

            if ($createResponse->isSuccess()) {
                return $createResponse->getResult()->getCatalogObject()->getId();
            }

            return null;
        } catch (ApiException $e) {
            throw SquareException::apiError('resolveCategory', $e->getMessage());
        }
    }

    private function resolveTax(string $taxName, float $taxRate): ?string
    {
        try {
            $searchRequest = new \Square\Models\SearchCatalogObjectsRequest();
            $searchRequest->setObjectTypes(['TAX']);
            $query = new \Square\Models\CatalogQuery();
            $query->setExactQuery(new \Square\Models\CatalogQueryExact('name', $taxName));
            $searchRequest->setQuery($query);

            $response = $this->client->getCatalogApi()->searchCatalogObjects($searchRequest);

            if ($response->isSuccess() && !empty($response->getResult()->getObjects())) {
                return $response->getResult()->getObjects()[0]->getId();
            }

            $tax = new CatalogTax();
            $tax->setName($taxName);
            $tax->setPercentage((string)$taxRate);
            $tax->setCalculationPhase('TAX_SUBTOTAL_PHASE');
            $tax->setInclusionType('ADDITIVE');
            $tax->setEnabled(true);

            $body = new CatalogObject('TAX', '#' . preg_replace('/[^a-zA-Z0-9_]/', '_', $taxName));
            $body->setTaxData($tax);

            $request = new UpsertCatalogObjectRequest(uniqid('', true), $body);
            $createResponse = $this->client->getCatalogApi()->upsertCatalogObject($request);

            if ($createResponse->isSuccess()) {
                return $createResponse->getResult()->getCatalogObject()->getId();
            }

            return null;
        } catch (ApiException $e) {
            throw SquareException::apiError('resolveTax', $e->getMessage());
        }
    }
}
