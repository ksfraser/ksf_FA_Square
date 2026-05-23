<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Exception;
use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareTokenDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMovesDAO;
use Ksfraser\Frontaccounting\SquareUp\Push\CatalogExporter;
use Square\Exceptions\ApiException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;

/**
 * Service class to handle Square export logic.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ExportService
{
    private string $tablePrefix;
    private Settings $settings;
    private CatalogExporter $exporter;
    private SquareTokenDAO $squareTokenDao;
    private StockMasterDAO $stockMasterDao;
    private StockMovesDAO $stockMovesDao;
    private array $existingSquareItems = [];

    public function __construct(
        string $tablePrefix,
        Settings $settings,
        CatalogExporter $exporter
    ) {
        $this->tablePrefix = $tablePrefix;
        $this->settings = $settings;
        $this->exporter = $exporter;
        $this->squareTokenDao = new SquareTokenDAO($tablePrefix);
        $this->stockMasterDao = new StockMasterDAO($tablePrefix);
        $this->stockMovesDao = new StockMovesDAO($tablePrefix);
    }

    /**
     * Ensures the square_tokens table exists.
     * 
     * @throws Exception if table creation fails
     */
    public function ensureTableExists(): void
    {
        $this->squareTokenDao->ensureTableExists();
    }

    /**
     * Fetches existing items from Square catalog.
     * 
     * @return array Array of existing items indexed by SKU
     */
    public function fetchExistingSquareItems(): array
    {
        $this->existingSquareItems = [];
        foreach ($this->exporter->listAllItems() as $obj) {
            $itemData = $obj->getItemData();
            if ($itemData !== null) {
                $variations = $itemData->getVariations();
                if ($variations !== null && count($variations) > 0) {
                    $varData = $variations[0]->getItemVariationData();
                    if ($varData !== null) {
                        $sku = $varData->getSku() ?? $varData->getName();
                        $this->existingSquareItems[$sku] = $obj;
                    }
                }
            }
        }
        return $this->existingSquareItems;
    }

    /**
     * Gets the number of existing Square items.
     * 
     * @return int
     */
    public function getExistingSquareItemsCount(): int
    {
        return count($this->existingSquareItems);
    }

    /**
     * Fetches items from FA for export.
     * 
     * @param int|null $categoryId
     * @param string|null $stockLike
     * @param bool $excludeInactive
     * @param bool $sortRecent
     * @param array|null $ksfGenPrefs
     * @param bool $ksfGenCatalogueInstalled
     * @return resource
     * @throws Exception
     */
    public function fetchFAItems(
        ?int $categoryId = null,
        ?string $stockLike = null,
        bool $excludeInactive = false,
        bool $sortRecent = false,
        ?array $ksfGenPrefs = null,
        bool $ksfGenCatalogueInstalled = false
    ) {
        return $this->stockMasterDao->getItemsForExport(
            $categoryId,
            $stockLike,
            $excludeInactive,
            $sortRecent,
            $ksfGenPrefs,
            $ksfGenCatalogueInstalled
        );
    }

    /**
     * Processes a single FA item for export.
     * 
     * @param array $item FA item data
     * @param string $currency Currency code
     * @param int $salesType Sales type ID
     * @param string $locationId Square location ID
     * @param bool $uploadImages Whether to upload images
     * @return array Result with status and messages
     */
    public function processItem(
        array $item,
        string $currency,
        int $salesType,
        string $locationId,
        bool $uploadImages
    ): array {
        $result = [
            'success' => false,
            'skipped' => false,
            'message' => '',
            'changes' => [],
            'catalogObject' => null,
        ];

        $stockId = $item['stock_id'];
        $sku = $stockId;

        // Get SKU from barcode if available
        $itemSku = $this->stockMasterDao->getItemSku($stockId);
        if ($itemSku !== null) {
            $sku = $itemSku;
            $result['message'] = "Using barcode SKU: " . $sku;
        }

        // Get price
        $myPrice = $this->stockMasterDao->getItemPrice($stockId, $currency, $salesType);
        if ($myPrice <= 0) {
            $myPrice = 999999.99;
            $priceCents = 99999999;
            $result['message'] .= "\nWARNING: No price for " . $stockId . " — set to $999,999.99 (sentinel)";
        } else {
            $priceCents = (int)round(100 * $myPrice);
            if ($priceCents > 99999999) {
                $priceCents = 99999999;
                $result['message'] .= "\nWARNING: Price capped for " . $stockId . " at $999,999.99";
            }
        }

        $catName = $item['cat_description'] ?? 'General';
        $taxName = $item['tax_name'] ?? '';
        $taxRate = $item['exempt'] ? 0.0 : 0.0;

        $existingItem = $this->existingSquareItems[$sku] ?? $this->existingSquareItems[$stockId] ?? null;

        // Check if item is already at all locations
        if ($existingItem !== null) {
            if ($existingItem->getPresentAtAllLocations() && $locationId !== '0') {
                $result['skipped'] = true;
                $result['message'] = "Skipping (already at all locations)";
                return $result;
            }
        }

        // Determine if insert or update
        $changes = [];
        $newDisplayName = str_replace("Whitewater Hill ", "", $item['description']);
        if ($existingItem !== null) {
            $existingData = $existingItem->getItemData();
            if ($existingData !== null) {
                $oldName = $existingData->getName();
                $oldDesc = $existingData->getDescription();
                $oldVariations = $existingData->getVariations();
                $oldPrice = null;
                $oldSku = null;
                if ($oldVariations !== null && count($oldVariations) > 0) {
                    $oldVarData = $oldVariations[0]->getItemVariationData();
                    if ($oldVarData !== null) {
                        $oldPrice = $oldVarData->getPriceMoney() !== null ? $oldVarData->getPriceMoney()->getAmount() : null;
                        $oldSku = $oldVarData->getSku();
                    }
                }
                if ($oldName !== $newDisplayName) $changes[] = 'desc: "' . ($oldName ?? '') . '" -> "' . $newDisplayName . '"';
                if ($oldDesc !== $item['description']) $changes[] = 'full_desc changed';
                if ((int)($oldPrice ?? 0) !== $priceCents) $changes[] = 'price: ' . ($oldPrice ?? 0) . ' -> ' . $priceCents;
                if ($oldSku !== $sku) $changes[] = 'sku: ' . ($oldSku ?? '') . ' -> ' . $sku;
            }
        }
        $result['changes'] = $changes;
        $result['operation'] = $existingItem !== null ? 'UPDATE' : 'INSERT';

        try {
            $catalogObject = $this->exporter->upsertProduct(
                $sku,
                $newDisplayName,
                $item['description'],
                $catName,
                $priceCents,
                $currency,
                $taxName,
                $taxRate,
                $existingItem
            );

            $result['success'] = true;
            $result['catalogObject'] = $catalogObject;
            $result['squareId'] = $catalogObject->getId();

            // Record mapping
            $faLastUpdated = $this->stockMovesDao->getLastModified($stockId);
            $this->squareTokenDao->upsertToken(
                $stockId,
                $sku,
                $catalogObject->getId(),
                $catalogObject->getItemVariationData()->getId(),
                $faLastUpdated
            );

            // Upload images if needed
            if ($uploadImages) {
                $imageDir = company_path() . '/images/';
                $imageDir = rtrim($imageDir, '/');
                $sqId = $catalogObject->getId();

                // Primary image
                $this->exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, 0, true);
                
                // Additional images
                for ($idx = 1; $idx <= 10; $idx++) {
                    if (!$this->exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, $idx)) {
                        break;
                    }
                }
            }
        } catch (SquareException $e) {
            $result['error'] = $e->getMessage();
        } catch (ApiException $e) {
            $result['error'] = 'API - ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Checks for items to delete from Square.
     * 
     * @param array $existingSquareItems Existing Square items
     * @return array Results with deleted items and errors
     */
    public function checkItemsToDelete(array $existingSquareItems): array
    {
        $result = [
            'deleted' => 0,
            'errors' => [],
        ];

        foreach ($existingSquareItems as $sqSku => $sqItem) {
            $activeCount = $this->stockMasterDao->countActiveStockItems($sqSku);

            if ($activeCount == 0) {
                try {
                    $this->exporter->deleteProduct($sqItem->getId());
                    $result['deleted']++;
                    $result['items'][] = $sqSku;
                } catch (Exception $e) {
                    $result['errors'][] = 'Delete ' . $sqSku . ': ' . $e->getMessage();
                }
            }
        }

        return $result;
    }
}