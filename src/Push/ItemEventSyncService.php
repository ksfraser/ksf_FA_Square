<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Push;

use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareTokenDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxRateResolver;
use Ksfraser\Frontaccounting\SquareUp\ValueObjects\SquarePrice;
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

    /** @var string */
    private $currency;

    /** @var int */
    private $salesType;

    /** @var TaxRateResolver */
    private $taxResolver;

    /**
     * @param SettingsInterface $settings       Square configuration
     * @param CatalogExporter   $exporter       Catalog push engine
     * @param StockMasterDAO    $stockMasterDao FA stock item access
     * @param SquareTokenDAO    $tokenDao       Square<->FA mapping store
     * @param string            $currency       Price currency code (e.g. 'CAD')
     * @param int               $salesType      FA sales type for pricing
     * @param TaxRateResolver   $taxResolver    Resolves the catalog tax rate
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
        TaxRateResolver $taxResolver = null
    ) {
        $this->settings = $settings;
        $this->exporter = $exporter;
        $this->stockMasterDao = $stockMasterDao;
        $this->tokenDao = $tokenDao;
        $this->currency = $currency;
        $this->salesType = $salesType;
        $this->taxResolver = $taxResolver !== null ? $taxResolver : new TaxRateResolver();
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

        try {
            $catalogObject = $this->exporter->upsertProduct(
                $sku,
                $description,
                $description,
                $categoryName,
                $priceCents,
                $currency,
                $taxName,
                $taxRate
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
