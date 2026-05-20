<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Push;

use Ksfraser\Frontaccounting\SquareUp\Contracts\CatalogExporterInterface;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\ProductNotFoundException;
use Square\SquareClient;
use Square\Environment;
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

class CatalogExporter implements CatalogExporterInterface
{
    private SquareClient $client;
    private SettingsInterface $settings;

    public function __construct(SquareClient $client, SettingsInterface $settings)
    {
        $this->client = $client;
        $this->settings = $settings;
    }

    public static function create(SettingsInterface $settings): self
    {
        $accessToken = $settings->getAccessToken();
        if ($accessToken === null) {
            throw SquareException::configurationError('access_token');
        }

        $client = new SquareClient([
            'accessToken' => $accessToken,
            'environment' => $settings->getEnvironment() === 'production'
                ? Environment::PRODUCTION
                : Environment::SANDBOX,
        ]);

        return new self($client, $settings);
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
            $categoryId = $this->resolveCategory($categoryName);

            $variation = new CatalogItemVariation();
            $variation->setName($name);
            $variation->setSku($sku);
            $variation->setPricingType('FIXED_PRICING');
            $variation->setPriceMoney(new Money());
            $variation->getPriceMoney()->setAmount($priceCents);
            $variation->getPriceMoney()->setCurrency($currency);
            $variation->setTrackInventory(true);

            $item = new CatalogItem();
            $item->setName($name);
            $item->setDescription($description);
            $item->setCategoryId($categoryId);
            $item->setVariations([$variation]);

            if ($taxName !== '') {
                $taxId = $this->resolveTax($taxName, $taxRate);
                $item->setTaxIds([$taxId]);
            }

            $body = new CatalogObject();
            $body->setType('ITEM');
            $body->setId('#' . $sku);
            $body->setItemData($item);

            $request = new UpsertCatalogObjectRequest(uniqid('', true), $body);

            $response = $this->client->getCatalogApi()->upsertCatalogObject($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'upsertCatalogObject',
                    'Failed to upsert product',
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
            $categoryName = $product['category'] ?? '';
            $priceCents = $product['price_cents'] ?? 0;
            $currency = $product['currency'] ?? 'CAD';
            $taxName = $product['tax_name'] ?? '';
            $taxRate = $product['tax_rate'] ?? 0.0;

            $categoryId = $categoryName !== '' ? $this->resolveCategory($categoryName) : null;

            $variation = new CatalogItemVariation();
            $variation->setName($name);
            $variation->setSku($sku);
            $variation->setPricingType('FIXED_PRICING');
            $variation->setPriceMoney(new Money());
            $variation->getPriceMoney()->setAmount($priceCents);
            $variation->getPriceMoney()->setCurrency($currency);
            $variation->setTrackInventory(true);

            $item = new CatalogItem();
            $item->setName($name);
            $item->setDescription($description);
            if ($categoryId !== null) {
                $item->setCategoryId($categoryId);
            }
            $item->setVariations([$variation]);

            if ($taxName !== '') {
                $taxId = $this->resolveTax($taxName, $taxRate);
                $item->setTaxIds([$taxId]);
            }

            $body = new CatalogObject();
            $body->setType('ITEM');
            $body->setId('#' . $sku);
            $body->setItemData($item);

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
            $query = new \Square\Models\SearchCatalogObjectsRequest();
            $query->setObjectTypes(['ITEM']);
            $query->setQuery(new \Square\Models\SearchCatalogObjectsQuery(
                new \Square\Models\CatalogQueryPrefix(
                    'variations',
                    ['sku' => $sku]
                )
            ));

            $response = $this->client->getCatalogApi()->searchCatalogObjects($query);

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
                $request = new \Square\Models\ListCatalogRequest();
                $request->setTypes('ITEM');
                if ($cursor !== null) {
                    $request->setCursor($cursor);
                }

                $response = $this->client->getCatalogApi()->listCatalog($request);

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

    private function resolveCategory(string $categoryName): ?string
    {
        try {
            $query = new \Square\Models\SearchCatalogObjectsRequest();
            $query->setObjectTypes(['CATEGORY']);
            $query->setQuery(new \Square\Models\SearchCatalogObjectsQuery(
                new \Square\Models\CatalogQueryExact('name', $categoryName)
            ));

            $response = $this->client->getCatalogApi()->searchCatalogObjects($query);

            if ($response->isSuccess() && !empty($response->getResult()->getObjects())) {
                return $response->getResult()->getObjects()[0]->getId();
            }

            $category = new CatalogCategory();
            $category->setName($categoryName);

            $body = new CatalogObject();
            $body->setType('CATEGORY');
            $body->setId('#' . preg_replace('/[^a-zA-Z0-9_]/', '_', $categoryName));
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
            $query = new \Square\Models\SearchCatalogObjectsRequest();
            $query->setObjectTypes(['TAX']);
            $query->setQuery(new \Square\Models\SearchCatalogObjectsQuery(
                new \Square\Models\CatalogQueryExact('name', $taxName)
            ));

            $response = $this->client->getCatalogApi()->searchCatalogObjects($query);

            if ($response->isSuccess() && !empty($response->getResult()->getObjects())) {
                return $response->getResult()->getObjects()[0]->getId();
            }

            $tax = new CatalogTax();
            $tax->setName($taxName);
            $tax->setPercentage((string)$taxRate);
            $tax->setCalculationPhase('TAX_SUBTOTAL_PHASE');
            $tax->setInclusionType('ADDITIVE');
            $tax->setEnabled(true);

            $body = new CatalogObject();
            $body->setType('TAX');
            $body->setId('#' . preg_replace('/[^a-zA-Z0-9_]/', '_', $taxName));
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
