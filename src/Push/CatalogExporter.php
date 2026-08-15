<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Push;

use ksfraser\FrontAccounting\Square\Contracts\CatalogExporterInterface;
use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;
use ksfraser\FrontAccounting\Square\ValueObjects\SquarePrice;
use Square\SquareClient;
use Square\Exceptions\ApiException;
use Square\Models\CatalogObject;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogCategory;
use Square\Models\CatalogObjectCategory;
use Square\Models\CatalogTax;
use Square\Models\CatalogModifier;
use Square\Models\CatalogModifierList;
use Square\Models\CatalogItemModifierListInfo;
use Square\Models\CatalogCustomAttributeValue;
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
    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var SettingsInterface
     */
    private $settings;

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
        float $taxRate = 0.0,
        ?CatalogObject $existingItem = null,
        ?array $attributes = null
    ): CatalogObject {
        $maxRetries = 5;
        $retryDelay = 1000000;
        $currentExistingItem = $existingItem;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $existingItemId = null;
                $existingVariationId = null;
                $existingItemVersion = null;
                $existingVariationVersion = null;
                if ($currentExistingItem !== null) {
                    $existingItemId = $currentExistingItem->getId();
                    $existingItemVersion = $currentExistingItem->getVersion();
                    $itemData = $currentExistingItem->getItemData();
                    if ($itemData !== null) {
                        $variations = $itemData->getVariations();
                        if ($variations !== null && count($variations) > 0) {
                            $existingVariationId = $variations[0]->getId();
                            $existingVariationVersion = $variations[0]->getVersion();
                        }
                    }
                }

                $body = $this->buildCatalogObject($sku, $name, $description, $categoryName, $priceCents, $currency, $taxName, $taxRate, $existingItemId, $existingVariationId, $existingItemVersion, $existingVariationVersion, $attributes);

                $request = new UpsertCatalogObjectRequest(uniqid('', true), $body);

                $response = $this->client->getCatalogApi()->upsertCatalogObject($request);

                if ($response->isSuccess()) {
                    return $response->getResult()->getCatalogObject();
                }

                $errors = $response->getErrors();
                $detail = '';
                $isRateLimited = false;
                $isVersionMismatch = false;
                if ($errors !== null) {
                    $parts = [];
                    foreach ($errors as $err) {
                        $parts[] = '[' . $err->getCode() . '] ' . $err->getDetail() . ($err->getField() ? ' (field: ' . $err->getField() . ')' : '');
                        if ($err->getCode() === 'RATE_LIMITED') {
                            $isRateLimited = true;
                        }
                        if ($err->getCode() === 'VERSION_MISMATCH') {
                            $isVersionMismatch = true;
                        }
                    }
                    $detail = ' | ' . implode('; ', $parts);
                }

                if ($isVersionMismatch && $attempt < $maxRetries) {
                    $refreshedItem = $this->searchProductBySku($sku);
                    if ($refreshedItem !== null) {
                        $currentExistingItem = $refreshedItem;
                        usleep($retryDelay * $attempt);
                        continue;
                    }
                }

                if ($isRateLimited && $attempt < $maxRetries) {
                    usleep($retryDelay * $attempt);
                    continue;
                }

                throw SquareException::apiError(
                    'upsertCatalogObject',
                    'Failed to upsert product' . $detail,
                    $response->getErrors()
                );
            } catch (ApiException $e) {
                if ($attempt < $maxRetries) {
                    usleep($retryDelay * $attempt);
                    continue;
                }
                throw SquareException::apiError('upsertCatalogObject', $e->getMessage());
            }
        }

        throw SquareException::apiError('upsertCatalogObject', 'Max retries exceeded');
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
            $existingItemId = $product['existing_item_id'] ?? null;
            $existingVariationId = $product['existing_variation_id'] ?? null;

            $body = $this->buildCatalogObject($sku, $name, $description, $categoryName, $priceCents, $currency, $taxName, $taxRate, $existingItemId, $existingVariationId, null, null, $product['attributes'] ?? null);
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
        float $taxRate,
        ?string $existingItemId = null,
        ?string $existingVariationId = null,
        $existingItemVersion = null,
        $existingVariationVersion = null,
        ?array $attributes = null
    ): CatalogObject {
        $sku = $this->sanitizeUtf8($sku);
        $name = $this->sanitizeUtf8($name);
        $description = $this->sanitizeUtf8($description);
        $categoryId = null;
        $parentCategoryName = isset($attributes['category_parent_name']) ? (string)$attributes['category_parent_name'] : '';
        if ($categoryName !== null && $categoryName !== '') {
            $categoryId = $this->resolveCategory(
                $this->sanitizeUtf8($categoryName),
                $parentCategoryName !== '' ? $this->sanitizeUtf8($parentCategoryName) : null
            );
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

        $measurementUnitId = isset($attributes['measurement_unit_id']) ? (string)$attributes['measurement_unit_id'] : '';
        if ($measurementUnitId !== '') {
            $variationData->setMeasurementUnitId($measurementUnitId);
        }

        $upc = isset($attributes['upc']) ? (string)$attributes['upc'] : '';
        if ($upc !== '') {
            $variationData->setUpc($upc);
        }

        $variationObject = new CatalogObject('ITEM_VARIATION', $existingVariationId ?? ('#' . $sku . '_var'));
        $variationObject->setItemVariationData($variationData);
        if ($existingVariationVersion !== null) {
            $variationObject->setVersion($existingVariationVersion);
        }

        $item = new CatalogItem();
        $item->setName($name);
        $item->setDescription($description);
        if ($categoryId !== null) {
            $item->setCategoryId($categoryId);
        }

        $fulfillment = isset($attributes['fulfillment']) && is_array($attributes['fulfillment']) ? $attributes['fulfillment'] : [];
        if (count($fulfillment) > 0) {
            $productType = (string)($fulfillment['product_type'] ?? 'REGULAR');
            $isService = $productType === 'SERVICE';
            $item->setProductType($isService ? 'APPOINTMENTS_SERVICE' : 'REGULAR');
            if ($isService && !empty($fulfillment['service_duration_minutes'])) {
                $variationData->setServiceDuration((int)$fulfillment['service_duration_minutes'] * 60);
            }
            if (array_key_exists('available_for_booking', $fulfillment)) {
                $variationData->setAvailableForBooking((bool)$fulfillment['available_for_booking']);
            }
            if (array_key_exists('sellable', $fulfillment)) {
                $variationData->setSellable((bool)$fulfillment['sellable']);
            }
            if (array_key_exists('stockable', $fulfillment)) {
                $variationData->setStockable((bool)$fulfillment['stockable']);
            }
        }

        $item->setVariations([$variationObject]);

        $modifierLists = isset($attributes['modifier_lists']) && is_array($attributes['modifier_lists']) ? $attributes['modifier_lists'] : [];
        if (count($modifierLists) > 0) {
            $infoList = [];
            foreach ($modifierLists as $modifierList) {
                $listId = $this->resolveModifierList($modifierList, $currency);
                $info = new CatalogItemModifierListInfo($listId);
                if (!empty($modifierList['min_selected_modifiers'])) {
                    $info->setMinSelectedModifiers((int)$modifierList['min_selected_modifiers']);
                }
                if (!empty($modifierList['max_selected_modifiers'])) {
                    $info->setMaxSelectedModifiers((int)$modifierList['max_selected_modifiers']);
                }
                $info->setEnabled(true);
                $info->setOrdinal((int)($modifierList['ordinal'] ?? 0));
                $infoList[] = $info;
            }
            $item->setModifierListInfo($infoList);
        }

        if ($taxName !== '') {
            $taxId = $this->resolveTax($taxName, $taxRate);
            $item->setTaxIds([$taxId]);
        }

        $body = new CatalogObject('ITEM', $existingItemId ?? ('#' . $sku));
        $body->setItemData($item);
        if ($existingItemVersion !== null) {
            $body->setVersion($existingItemVersion);
        }

        $customAttributes = isset($attributes['custom_attributes']) && is_array($attributes['custom_attributes']) ? $attributes['custom_attributes'] : [];
        if (count($customAttributes) > 0) {
            $valueMap = [];
            foreach ($customAttributes as $attribute) {
                $key = (string)($attribute['attr_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $value = new CatalogCustomAttributeValue();
                $value->setKey($key);
                $value->setStringValue((string)($attribute['attr_value'] ?? ''));
                $valueMap[$key] = $value;
            }
            if (count($valueMap) > 0) {
                $body->setCustomAttributeValues($valueMap);
            }
        }

        return $body;
    }

    /**
     * Upserts a modifier list as a MODIFIER_LIST catalog object with nested
     * MODIFIER children and returns the catalog object id to attach to the
     * item's modifier_list_info.
     *
     * @param array<string, mixed> $modifierList Normalized modifier list
     * @param string               $currency     Price currency code
     * @return string Catalog object id of the modifier list
     * @throws SquareException when the upsert fails
     */
    private function resolveModifierList(array $modifierList, string $currency): string
    {
        $listData = new CatalogModifierList();
        $listData->setName((string)($modifierList['name'] ?? ''));

        $selectionType = (string)($modifierList['selection_type'] ?? 'SINGLE');
        if ($selectionType === 'SINGLE' || $selectionType === 'MULTIPLE') {
            $listData->setSelectionType($selectionType);
        }

        $modifierType = (string)($modifierList['modifier_type'] ?? '');
        if ($modifierType === 'NON_ALCOHOL' || $modifierType === 'ALCOHOL') {
            $listData->setModifierType($modifierType);
        }
        if (isset($modifierList['ordinal'])) {
            $listData->setOrdinal((int)$modifierList['ordinal']);
        }

        $modifierObjects = [];
        foreach ((isset($modifierList['modifiers']) && is_array($modifierList['modifiers']) ? $modifierList['modifiers'] : []) as $modifier) {
            $modifierData = new CatalogModifier();
            $modifierData->setName((string)($modifier['name'] ?? ''));
            $price = isset($modifier['price']) && $modifier['price'] !== null && $modifier['price'] !== ''
                ? (string)$modifier['price']
                : '';
            if ($price !== '' && (float)$price > 0) {
                $money = new Money();
                $money->setAmount(SquarePrice::fromDollars((float)$price)->getCents());
                $money->setCurrency($currency);
                $modifierData->setPriceMoney($money);
            }
            if (isset($modifier['ordinal'])) {
                $modifierData->setOrdinal((int)$modifier['ordinal']);
            }

            $modifierObject = new CatalogObject('MODIFIER', '#' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($modifier['name'] ?? '')));
            $modifierObject->setModifierData($modifierData);
            $modifierObjects[] = $modifierObject;
        }
        if (count($modifierObjects) > 0) {
            $listData->setModifiers($modifierObjects);
        }

        $listObject = new CatalogObject('MODIFIER_LIST', '#modlist_' . (int)($modifierList['id'] ?? 0));
        $listObject->setModifierListData($listData);

        try {
            $request = new UpsertCatalogObjectRequest(uniqid('', true), $listObject);
            $response = $this->client->getCatalogApi()->upsertCatalogObject($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'upsertCatalogObject',
                    'Failed to upsert modifier list',
                    $response->getErrors()
                );
            }

            $resultObject = $response->getResult()->getCatalogObject();
            return $resultObject->getId() ?? $listObject->getId();
        } catch (ApiException $e) {
            throw SquareException::apiError('upsertCatalogObject', $e->getMessage());
        }
    }

    private function resolveCategory(string $categoryName, ?string $parentCategoryName = null): ?string
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

            if ($parentCategoryName !== null && $parentCategoryName !== '' && $parentCategoryName !== $categoryName) {
                $parentId = $this->resolveCategory($parentCategoryName);
                if ($parentId !== null) {
                    $parent = new CatalogObjectCategory();
                    $parent->setId($parentId);
                    $category->setParentCategory($parent);
                }
            }

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
