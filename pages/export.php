<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";
include_once $path_to_root . "/inventory/includes/db/items_prices_db.inc";
include_once $path_to_root . "/inventory/includes/db/items_db.inc";
include_once $path_to_root . "/sales/includes/db/sales_types_db.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory;
use Ksfraser\Frontaccounting\SquareUp\Push\CatalogExporter;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Square\Exceptions\ApiException;

$tablePrefix = '0_';
$settings = Settings::fromFADatabase($tablePrefix);
$accessToken = $settings->getAccessToken();

$help_context = "Export to Square";
page(_($help_context), false, false, "", "");

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff; background-color: ' . $badgeColor . '; margin-left: 8px; }
body { background-color: ' . ($env === 'production' ? '#fff5f5' : '#fffde7') . '; }
</style>';
echo '<span class="square-env-badge">' . $badgeText . '</span>';

if ($accessToken === null || $accessToken === '') {
    display_error(_("Access Token not configured. Please configure in Square Configuration first."));
    end_page();
    return;
}

try {
    $client = SquareClientFactory::create($settings);
    $locationsApi = $client->getLocationsApi();
    $locResponse = $locationsApi->listLocations();
    $squareLocations = [];
    if ($locResponse->isSuccess()) {
        foreach ($locResponse->getResult()->getLocations() as $loc) {
            $squareLocations[$loc->getId()] = $loc->getName();
        }
    }
} catch (Exception $e) {
    display_error(_("API Connection Error: ") . $e->getMessage());
    $squareLocations = [];
}

$msg = '';
$error = '';

if (isset($_POST['action']) && $_POST['action'] == 'i_export') {
    $locationId = $_POST['location_id'] ?? '0';
    $category = (int)($_POST['category'] ?? -1);
    $stockLike = $_POST['stocklike'] ?? '';
    $uploadImages = (int)($_POST['upload'] ?? 0);
    $availableOnline = (int)($_POST['online'] ?? 0);

    try {
        $exporter = new CatalogExporter($client, $settings);

        $existingSquareItems = [];
        foreach ($exporter->listAllItems() as $obj) {
            $itemData = $obj->getItemData();
            if ($itemData !== null) {
                $variations = $itemData->getVariations();
                if ($variations !== null && count($variations) > 0) {
                    $varData = $variations[0]->getItemVariationData();
                    if ($varData !== null) {
                        $sku = $varData->getSku() ?? $varData->getName();
                        $existingSquareItems[$sku] = $obj;
                    }
                }
            }
        }

        $categoryFilter = '';
        if ($category > 0) {
            $categoryFilter = " AND item.category_id = " . (int)$category;
        }

        $stockFilter = '';
        if ($stockLike !== '') {
            $stockFilter = " AND item.stock_id LIKE " . db_escape('%' . $stockLike . '%');
        }

        $sql = "SELECT item.stock_id, item.description, item.units, item.inactive, "
            . "cat.description AS cat_description, tt.name AS tax_name, tt.exempt "
            . "FROM {$tablePrefix}stock_master item "
            . "LEFT JOIN {$tablePrefix}stock_category cat ON item.category_id = cat.category_id "
            . "LEFT JOIN {$tablePrefix}item_tax_types tt ON item.tax_type_id = tt.id "
            . "WHERE item.inactive = 0{$categoryFilter}{$stockFilter} "
            . "ORDER BY item.category_id, item.stock_id";

        $itemsResult = db_query($sql);
        $exported = 0;
        $skipped = 0;
        $deleted = 0;

        while ($item = db_fetch_assoc($itemsResult)) {
            $stockId = $item['stock_id'];
            $sku = $stockId;

            $barcodeResult = get_all_item_codes($stockId);
            $barcodeRow = db_fetch($barcodeResult);
            if ($barcodeRow && !empty($barcodeRow['item_code'])) {
                $sku = $barcodeRow['item_code'];
            }

            $myPrice = get_kit_price($stockId, $_POST['currency'] ?? get_company_pref('curr_default'), $_POST['sales_type'] ?? 0);
            if ($myPrice < 0) {
                $myPrice = 0;
            }
            $priceCents = (int)round(100 * $myPrice);

            $catName = $item['cat_description'] ?? 'General';
            $taxName = $item['tax_name'] ?? '';
            $taxRate = $item['exempt'] ? 0.0 : 0.0;

            $existingItem = $existingSquareItems[$sku] ?? $existingSquareItems[$stockId] ?? null;

            if ($existingItem !== null) {
                if ($existingItem->getPresentAtAllLocations() && $locationId !== '0') {
                    $skipped++;
                    continue;
                }
            }

            try {
                $catalogObject = $exporter->upsertProduct(
                    $sku,
                    str_replace("Whitewater Hill ", "", $item['description']),
                    $item['description'],
                    $catName,
                    $priceCents,
                    $_POST['currency'] ?? 'CAD',
                    $taxName,
                    $taxRate
                );

                $exported++;
                display_notification(_("Exported: ") . $item['description']);

                if ($uploadImages) {
                    $imageDir = company_path() . '/images/';
                    $imageDir = rtrim($imageDir, '/');
                    $sqId = $catalogObject->getId();

                    $exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, 0, true);

                    for ($idx = 1; $idx <= 10; $idx++) {
                        if (!$exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, $idx)) {
                            break;
                        }
                    }
                }
            } catch (SquareException $e) {
                display_error(_("Failed to export ") . $stockId . ": " . $e->getMessage());
            } catch (ApiException $e) {
                display_error(_("API Error for ") . $stockId . ": " . $e->getMessage());
            }
        }

        foreach ($existingSquareItems as $sqSku => $sqItem) {
            $chkSql = "SELECT COUNT(*) AS cnt FROM {$tablePrefix}stock_master WHERE stock_id = " . db_escape($sqSku) . " AND inactive = 0";
            $chkResult = db_query($chkSql);
            $chkRow = db_fetch_assoc($chkResult);

            if ($chkRow['cnt'] == 0) {
                try {
                    $exporter->deleteProduct($sqItem->getId());
                    display_notification(_("Deleted from Square: ") . $sqSku);
                    $deleted++;
                } catch (Exception $e) {
                    display_error(_("Failed to delete ") . $sqSku . ": " . $e->getMessage());
                }
            }
        }

        $msg = _("Export complete. Exported: ") . $exported . _(", Skipped: ") . $skipped . _(", Deleted: ") . $deleted;

    } catch (ApiException $e) {
        $error = _("API Error: ") . $e->getMessage();
    } catch (Exception $e) {
        $error = _("Error: ") . $e->getMessage();
    }
}

start_form(true);
start_table(TABLESTYLE2, "width=40%");
table_section_title(_("Export Inventory Options"));

currencies_list_row(_("Customer Currency:"), 'currency', get_company_pref("curr_default"));
sales_types_list_row(_("Sales Type:"), 'sales_type', null);
locations_list_row(_("FA Location:"), 'default_location', null);

if (count($squareLocations) > 0) {
    $locList = array_merge(['0' => _('All')], $squareLocations);
    array_selector_row(_("Square Location:"), 'location_id', '0', $locList, [
        'select_submit' => false,
        'async' => false,
    ]);
} else {
    hidden('location_id', '0');
}

stock_categories_list_row(_("Category:"), 'category', null, _("All Categories"));
text_row(_("Stock ID Pattern:"), 'stocklike', null, 10, 20);
yesno_list_row(_("Upload Images:"), 'upload', null);
yesno_list_row(_("Available Online:"), 'online', null);

end_table(1);

if ($msg !== '') {
    display_notification($msg);
}
if ($error !== '') {
    display_error($error);
}

hidden('action', 'i_export');
submit_center('pexport', _("Export FA Items To Square"));

end_form();
end_page();
