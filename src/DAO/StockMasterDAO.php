<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for stock_master table.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class StockMasterDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Fetches stock items based on filters for Square export.
     * 
     * @param int|null $categoryId Category ID filter
     * @param string|null $stockLike Stock ID pattern
     * @param bool $excludeInactive Whether to exclude inactive items
     * @param bool $sortRecent Whether to sort by recent first
     * @param array|null $ksfGenPrefs ksf_generate_catalogue preferences
     * @param bool $ksfGenCatalogueInstalled Whether ksf_generate_catalogue is installed
     * @return resource Database query result
     * @throws Exception if query fails
     */
    public function getItemsForExport(
        ?int $categoryId = null,
        ?string $stockLike = null,
        bool $excludeInactive = false,
        bool $sortRecent = false,
        ?array $ksfGenPrefs = null,
        bool $ksfGenCatalogueInstalled = false
    ) {
        $sql = "SELECT item.stock_id, item.description, item.units, item.inactive, "
            . "cat.description AS cat_description, tt.name AS tax_name, tt.exempt "
            . "FROM {$this->tablePrefix}stock_master item "
            . "LEFT JOIN {$this->tablePrefix}stock_category cat ON item.category_id = cat.category_id "
            . "LEFT JOIN {$this->tablePrefix}item_tax_types tt ON item.tax_type_id = tt.id "
            . "WHERE 1=1";
        
        if ($excludeInactive) {
            $sql .= " AND item.inactive = 0";
        }
        
        if ($categoryId !== null && $categoryId > 0) {
            $sql .= " AND item.category_id = " . (int)$categoryId;
        }
        
        if ($stockLike !== '' && $stockLike !== null) {
            $sql .= " AND item.stock_id LIKE " . \db_escape('%' . $stockLike . '%');
        }
        
        // Special prefix handling from ksf_generate_catalogue
        $prefixConditions = [];
        if ($ksfGenCatalogueInstalled && is_array($ksfGenPrefs)) {
            if (!empty($ksfGenPrefs['DISCONTINUED_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . \db_escape($ksfGenPrefs['DISCONTINUED_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['SPECIAL_ORDER_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . \db_escape($ksfGenPrefs['SPECIAL_ORDER_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['CLEARANCE_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . \db_escape($ksfGenPrefs['CLEARANCE_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['CUSTOM_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . \db_escape($ksfGenPrefs['CUSTOM_PREFIX'] . '%');
            }
            
            if (!empty($prefixConditions)) {
                $sql .= " AND (" . implode(' OR ', $prefixConditions) . ")";
            }
            
            $orderBy = " ORDER BY item.category_id, item.stock_id";
            if (!empty($_POST['sort_recent'])) {
                // Check if the last modified column exists in stock_master
                $checkResult = \db_query("SHOW COLUMNS FROM {$this->tablePrefix}stock_master LIKE 'last_updated'");
                if ($checkResult !== false && \db_num_rows($checkResult) > 0) {
                    $orderBy = " ORDER BY item.last_updated DESC, item.category_id, item.stock_id";
                }
            }
            
            $sql .= $orderBy;
        } else {
            $sql .= " ORDER BY item.category_id, item.stock_id";
        }

        $result = \db_query($sql);
        if ($result === false) {
            throw new Exception(_("Failed to query stock items"));
        }
        
        return $result;
    }

    /**
     * Gets the price for a stock item.
     * 
     * @param string $stockId Stock ID
     * @param string $currency Currency code
     * @param int $salesType Sales type ID
     * @return float Price
     */
    public function getItemPrice(string $stockId, string $currency, int $salesType): float
    {
        return get_kit_price($stockId, $currency, $salesType);
    }

    /**
     * Gets the barcode/SKU for a stock item.
     * 
     * @param string $stockId Stock ID
     * @return string|null SKU or null if not found
     */
    public function getItemSku(string $stockId): ?string
    {
        $barcodeResult = get_all_item_codes($stockId);
        $barcodeRow = false;
        if ($barcodeResult !== false) {
            $barcodeRow = \db_fetch($barcodeResult);
        }
        if ($barcodeRow && !empty($barcodeRow['item_code'])) {
            return $barcodeRow['item_code'];
        }
        return null;
    }

    /**
     * Fetches a single stock item (with category and tax context) for
     * event-driven sync, e.g. from item_created / item_updated broadcasts.
     *
     * @param string $stockId Stock ID
     * @return array|null Item row or null when the item does not exist
     * @throws Exception if query fails
     */
    public function getItemForSync(string $stockId): ?array
    {
        $sql = "SELECT item.stock_id, item.description, item.units, item.inactive, "
            . "cat.description AS cat_description, tt.name AS tax_name, tt.exempt "
            . "FROM {$this->tablePrefix}stock_master item "
            . "LEFT JOIN {$this->tablePrefix}stock_category cat ON item.category_id = cat.category_id "
            . "LEFT JOIN {$this->tablePrefix}item_tax_types tt ON item.tax_type_id = tt.id "
            . "WHERE item.stock_id = " . \db_escape($stockId) . " LIMIT 1";

        $result = \db_query($sql);
        if ($result === false) {
            throw new Exception(_("Failed to query stock item"));
        }
        $row = \db_fetch_assoc($result);
        return $row === false ? null : $row;
    }

    /**
     * Counts the number of active stock items by ID.
     * 
     * @param string $stockId Stock ID
     * @return int Count of active items
     */
    public function countActiveStockItems(string $stockId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->tablePrefix}stock_master WHERE stock_id = " . \db_escape($stockId) . " AND inactive = 0";
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            if ($row !== false) {
                return (int)$row['cnt'];
            }
        }
        return 0;
    }
}