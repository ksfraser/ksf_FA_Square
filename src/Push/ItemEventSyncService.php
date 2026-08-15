<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Push;

use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;
use ksfraser\FrontAccounting\Square\DAO\ProductAttributesDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareTokenDAO;
use ksfraser\FrontAccounting\Square\DAO\StockMasterDAO;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;
use ksfraser\FrontAccounting\Square\Services\TaxRateResolver;
use ksfraser\FrontAccounting\Square\ValueObjects\SquarePrice;
use Square\Exceptions\ApiException;

/**
 * Item Event Sync Service
 *
 * Reacts to FA stock item lifecycle events (item_created / item_updated)
 * broadcast by ksf_FA_Common's shared ItemEventPublisher. Re-fetches the
 * full item data from FA through StockMasterDAO and pushes it to Square via
 * the same CatalogExporter path used by pages/export.php (Settings ->
 * SquareClientFactory -> CatalogExporter::upsertProduct).
 *
 * The upsert is idempotent: Square treats create and update identically, so
 * the same code path serves both events. When Square is not configured or
 * the item is not exportable, the sync is skipped without error.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-SQUARE-006 item event sync
 * @since 2.4.4
 */
class ItemEventSyncService
{
    /** @var SettingsInterface */
    private $settings;

    /** @var CatalogExporter */
    private $exporter;

    /** @var StockMasterDAO */
    private $stockMasterDao;

    /** @var SquareTokenDAO */
    private $tokenDao;

    /** @var ProductAttributesDAO */
    private $attributesDao;

    /** @var string */
    private $currency;

    /** @var int */
    private $salesType;

    /** @var TaxRateResolver */
    private $taxResolver;

    /**
     * @param SettingsInterface    $settings       Square configuration
     * @param CatalogExporter      $exporter       Catalog push engine
     * @param StockMasterDAO       $stockMasterDao FA stock item access
     * @param SquareTokenDAO       $tokenDao       Square<->FA mapping store
     * @param string               $currency       Price currency code (e.g. 'CAD')
     * @param int                  $salesType      FA sales type for pricing
     * @param TaxRateResolver      $taxResolver    Resolves the catalog tax rate
     * @param ProductAttributesDAO $attributesDao  Stage 3 product attributes access
     *
     * @since 2.4.4
     */
    public function __construct(
        SettingsInterface $settings,
        CatalogExporter $exporter,
        StockMasterDAO $stockMasterDao,
        SquareTokenDAO $tokenDao,
        string $currency = '',
        int $salesType = 0,
        TaxRateResolver $taxResolver = null,
        ProductAttributesDAO $attributesDao = null
    ) {
        $this->settings = $settings;
        $this->exporter = $exporter;
        $this->stockMasterDao = $stockMasterDao;
        $this->tokenDao = $tokenDao;
        $this->currency = $currency;
        $this->salesType = $salesType;
        $this->taxResolver = $taxResolver !== null ? $taxResolver : new TaxRateResolver();
        $this->attributesDao = $attributesDao !== null
            ? $attributesDao
            : new ProductAttributesDAO(defined('TB_PREF') ? TB_PREF : '0_');
    }

    /**
     * Push a single stock item to the Square catalog in response to an
     * item_created or item_updated event.
     *
     * @param string $stockId FA stock_id
     * @param string $event   'created' or 'updated'
     *
     * @return array{status: string, event?: string, reason?: string, square_id?: string}
     *
     * @since 2.4.4
     */
    public function sync(string $stockId, string $event): array
    {
        $token = $this->settings->getAccessToken();
        if ($token === null || $token === '') {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'no_token'];
        }

        $item = $this->stockMasterDao->getItemForSync($stockId);
        if ($item === null) {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'not_found'];
        }
        if ((int) $item['inactive'] === 1) {
            return ['status' => 'skipped', 'event' => $event, 'reason' => 'inactive'];
        }

        $sku = $this->stockMasterDao->getItemSku($stockId) ?? $stockId;

        $price = $this->stockMasterDao->getItemPrice($stockId, $this->currency, $this->salesType);
        $priceCents = SquarePrice::fromDollars((float) $price)->getCents();

        $description = (string) $item['description'];
        $categoryName = !empty($item['cat_description']) ? (string) $item['cat_description'] : 'General';
        $taxName = !empty($item['tax_name']) ? (string) $item['tax_name'] : '';
        $taxRate = $this->taxResolver->resolveForItem(
            !empty($item['exempt']),
            $this->settings->getDefaultTaxGroup()
        );
        $currency = $this->currency !== '' ? $this->currency : 'CAD';

        $attributes = $this->buildAttributesBag($stockId, $item);

        try {
            $catalogObject = $this->exporter->upsertProduct(
                $sku,
                $description,
                $description,
                $categoryName,
                $priceCents,
                $currency,
                $taxName,
                $taxRate,
                null,
                $attributes
            );
        } catch (SquareException $e) {
            return ['status' => 'failed', 'event' => $event, 'reason' => $e->getMessage()];
        } catch (ApiException $e) {
            return ['status' => 'failed', 'event' => $event, 'reason' => $e->getMessage()];
        }

        $variationId = $this->extractVariationId($catalogObject);

        $this->tokenDao->upsertToken(
            $stockId,
            $sku,
            $catalogObject->getId(),
            $variationId,
            $this->tokenDao->getFaLastUpdated($stockId)
        );

        return [
            'status'    => 'pushed',
            'event'     => $event,
            'square_id' => $catalogObject->getId(),
        ];
    }

    /**
     * Assemble the Stage 3 product attributes bag for the exporter.
     *
     * Attribute sources that are absent (Stage 3 module not installed, no
     * records, or a missing hierarchy row) degrade to empty values so the
     * export still succeeds.
     *
     * @param string $stockId FA stock_id
     * @param array  $item    Item row from StockMasterDAO::getItemForSync()
     *
     * @return array{measurement_unit_id: string|null, custom_attributes: array, modifier_lists: array, category_parent_name: string|null}
     *
     * @since 2.4.4
     */
    private function buildAttributesBag(string $stockId, array $item): array
    {
        $measurementUnitId = $this->attributesDao->getMeasurementUnitId($stockId);
        $customAttributes = $this->attributesDao->getCustomAttributes($stockId);
        $modifierLists = $this->attributesDao->getModifierLists($stockId);

        $attributes = [
            'measurement_unit_id'  => $measurementUnitId !== null ? (string)$measurementUnitId : null,
            'custom_attributes'    => is_array($customAttributes) ? $customAttributes : [],
            'modifier_lists'       => is_array($modifierLists) ? $modifierLists : [],
            'category_parent_name' => null,
            'fulfillment'          => $this->attributesDao->getFulfillment($stockId),
            'upc'                  => $this->attributesDao->getUpc($stockId),
        ];

        $categoryId = isset($item['category_id']) ? (int)$item['category_id'] : 0;
        if ($categoryId > 0) {
            $parentCategoryId = $this->attributesDao->getCategoryParent($categoryId);
            if ($parentCategoryId !== null) {
                $parentName = $this->stockMasterDao->getCategoryName((int)$parentCategoryId);
                if ($parentName !== null && $parentName !== '') {
                    $attributes['category_parent_name'] = $parentName;
                }
            }
        }

        return $attributes;
    }

    /**
     * Extract the Square variation id from the upserted catalog object.
     *
     * @param object $catalogObject Square CatalogObject
     * @return string|null
     */
    private function extractVariationId($catalogObject): ?string
    {
        $itemData = $catalogObject->getItemData();
        if ($itemData === null) {
            return null;
        }
        $variations = $itemData->getVariations();
        if ($variations === null || count($variations) === 0) {
            return null;
        }
        return $variations[0]->getId();
    }
}
