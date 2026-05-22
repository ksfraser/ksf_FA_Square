<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

use Square\Models\CatalogObject;
use Square\Models\InventoryCount;

interface CatalogExporterInterface
{
    public function upsertProduct(
        string $sku,
        string $name,
        string $description,
        string $categoryName,
        int $priceCents,
        string $currency = 'CAD',
        string $taxName = '',
        float $taxRate = 0.0
    ): CatalogObject;

    public function batchUpsertProducts(array $products): array;

    public function pushInventory(string $catalogObjectId, string $locationId, float $quantity): void;

    public function batchPushInventory(array $inventoryChanges): void;

    public function getInventoryCount(string $catalogObjectId, string $locationId): ?InventoryCount;

    public function deleteProduct(string $catalogObjectId): void;

    public function searchProductBySku(string $sku): ?CatalogObject;

    public function listAllItems(): array;

    public function uploadImage(
        string $catalogObjectId,
        string $stockId,
        string $description,
        string $imageDir,
        int $imageIndex = 0,
        bool $isPrimary = false
    ): bool;
}
