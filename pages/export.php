<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = __DIR__ . "/../../..";

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

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
try {
    $settings = Settings::fromFADatabase($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    display_error(_("Failed to load configuration: ") . $e->getMessage());
}
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
    } else {
        $errors = $locResponse->getErrors();
        $errMsg = '';
        if ($errors !== null) {
            foreach ($errors as $e) {
                $errMsg .= $e->getCode() . ': ' . $e->getDetail() . '; ';
            }
        }
        display_error(_("Token rejected by Square: ") . $errMsg);
    }
} catch (Exception $e) {
    display_error(_("API Connection Error: ") . $e->getMessage());
    $squareLocations = [];
}

$msg = '';
$error = '';

// Check if ksf_generate_catalogue module is installed and load its prefs
$ksfGenCatalogueInstalled = defined('KSF_GENERATE_CATALOGUE_PREFS') && @file_exists('/tmp/ksf_generate/class.ksf_generate_catalogue.inc.php');
$ksfGenPrefs = [];
if ($ksfGenCatalogueInstalled) {
    $prefsTable = KSF_GENERATE_CATALOGUE_PREFS;
    $pResult = db_query("SELECT `pref_name`, `value` FROM {$tablePrefix}{$prefsTable}");
    if ($pResult !== false) {
        while ($pRow = db_fetch_assoc($pResult)) {
            $ksfGenPrefs[$pRow['pref_name']] = $pRow['value'];
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] == 'i_export') {
    $locationId = $_POST['location_id'] ?? '0';
    $category = (int)($_POST['category'] ?? -1);
    $stockLike = $_POST['stocklike'] ?? '';
    $uploadImages = (int)($_POST['upload'] ?? 0);
    $availableOnline = (int)($_POST['online'] ?? 0);
    $maxItems = (int)($_POST['max_items'] ?? 10);
    $sendInactive = (int)($_POST['send_inactive'] ?? 0);
    $fullSync = (int)($_POST['full_sync'] ?? 0);
    if ($maxItems < 1) $maxItems = 10;

    try {
        $env = $settings->getEnvironment();
        $tokenSource = '';
        if ($env === 'sandbox' && $settings->getSandboxAccessToken() !== null) {
            $tokenSource = 'sandbox_access_token';
        } elseif ($env === 'production' && $settings->getProductionAccessToken() !== null) {
            $tokenSource = 'production_access_token';
        } else {
            $tokenSource = 'access_token (legacy fallback)';
        }
        $token = $settings->getAccessToken();
        $tokenPrefix = substr($token ?? '', 0, 8);
        display_notification(_("Environment: ") . strtoupper($env) . _(" | Token source: ") . $tokenSource . _(" | Prefix: ") . $tokenPrefix . _("..."));
        display_notification(_("Square API connection: ") . (count($squareLocations) > 0 ? _("OK (") . count($squareLocations) . _(" locations found)") : _("FAILED")));

        $exporter = new CatalogExporter($client, $settings);

        display_notification(_("Fetching existing Square catalog items..."));
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
        display_notification(_("Found ") . count($existingSquareItems) . _(" existing items in Square"));

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
            . "WHERE 1=1";
        
        if (!$sendInactive) {
            $sql .= " AND item.inactive = 0";
        }
        
        if ($category > 0) {
            $sql .= " AND item.category_id = " . (int)$category;
        }
        
        if ($stockLike !== '') {
            $sql .= " AND item.stock_id LIKE " . db_escape('%' . $stockLike . '%');
        }
        
        // Special prefix handling from ksf_generate_catalogue
        $prefixConditions = [];
        if ($ksfGenCatalogueInstalled) {
            if (!empty($ksfGenPrefs['DISCONTINUED_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . db_escape($ksfGenPrefs['DISCONTINUED_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['SPECIAL_ORDER_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . db_escape($ksfGenPrefs['SPECIAL_ORDER_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['CLEARANCE_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . db_escape($ksfGenPrefs['CLEARANCE_PREFIX'] . '%');
            }
            if (!empty($ksfGenPrefs['CUSTOM_PREFIX'])) {
                $prefixConditions[] = "item.description LIKE " . db_escape($ksfGenPrefs['CUSTOM_PREFIX'] . '%');
            }
            
            if (!empty($prefixConditions)) {
                $sql .= " AND (" . implode(' OR ', $prefixConditions) . ")";
            }
            
            $orderBy = " ORDER BY item.category_id, item.stock_id";
            if (!empty($_POST['sort_recent'])) {
                // Check if the last modified column exists in stock_master
                $checkResult = db_query("SHOW COLUMNS FROM {$tablePrefix}stock_master LIKE 'last_updated'");
                if ($checkResult !== false && db_num_rows($checkResult) > 0) {
                    $orderBy = " ORDER BY item.last_updated DESC, item.category_id, item.stock_id";
                }
            }
            
            $sql .= $orderBy;

        $itemsResult = db_query($sql);
        $exported = 0;
        $skipped = 0;
        $deleted = 0;
        $errors = [];

        if ($itemsResult === false) {
            throw new \RuntimeException(_("Failed to query stock items"));
        }

        $totalFound = db_num_rows($itemsResult);
        display_notification(_("Total FA items to process: ") . $totalFound . _(" (limited to ") . $maxItems . _(")"));

        $processed = 0;
        while ($item = db_fetch_assoc($itemsResult)) {
            if ($item === false) break;
            $processed++;

            display_notification(_("[") . $processed . _("/") . $maxItems . _("] Processing ") . $item['stock_id'] . _(": ") . $item['description']);

            $stockId = $item['stock_id'];
            $sku = $stockId;

            $barcodeResult = get_all_item_codes($stockId);
            $barcodeRow = false;
            if ($barcodeResult !== false) {
                $barcodeRow = db_fetch($barcodeResult);
            }
            if ($barcodeRow && !empty($barcodeRow['item_code'])) {
                $sku = $barcodeRow['item_code'];
                display_notification(_("  Using barcode SKU: ") . $sku);
            }

            $myPrice = get_kit_price($stockId, $_POST['currency'] ?? get_company_pref('curr_default'), $_POST['sales_type'] ?? 0);
            if ($myPrice <= 0) {
                $myPrice = 999999.99;
                $priceCents = 99999999;
                display_notification(_("  WARNING: No price for ") . $stockId . _(" — set to \$999,999.99 (sentinel)"));
            } else {
                $priceCents = (int)round(100 * $myPrice);
                if ($priceCents > 99999999) {
                    display_notification(_("  WARNING: Price capped for ") . $stockId . _(" at \$999,999.99"));
                    $priceCents = 99999999;
                }
            }

            $catName = $item['cat_description'] ?? 'General';
            $taxName = $item['tax_name'] ?? '';
            $taxRate = $item['exempt'] ? 0.0 : 0.0;

            $existingItem = $existingSquareItems[$sku] ?? $existingSquareItems[$stockId] ?? null;

            if ($existingItem !== null) {
                if ($existingItem->getPresentAtAllLocations() && $locationId !== '0') {
                    display_notification(_("  Skipping (already at all locations)"));
                    $skipped++;
                    if ($processed >= $maxItems) break;
                    continue;
                }
            }

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
                display_notification(_("  UPDATE (") . (count($changes) > 0 ? implode(', ', $changes) : _('no field changes')) . _(")"));
            } else {
                display_notification(_("  INSERT (new item)"));
            }

            try {
                display_notification(_("  Calling Square API: upsertProduct..."));
                $catalogObject = $exporter->upsertProduct(
                    $sku,
                    str_replace("Whitewater Hill ", "", $item['description']),
                    $item['description'],
                    $catName,
                    $priceCents,
                    $_POST['currency'] ?? 'CAD',
                    $taxName,
                    $taxRate,
                    $existingItem
                );

            $exported++;
            display_notification(_("  SUCCESS: ") . $item['stock_id'] . _(" -> Square ID: ") . $catalogObject->getId());

            // Record successful mapping in square_tokens with timestamp of last modification
            $faLastUpdated = null;
            $lastUpdatedResult = db_query("SELECT modified_date FROM {$tablePrefix}stock_moves WHERE stock_id = " . db_escape($stockId) . " ORDER BY tran_date DESC LIMIT 1");
            if ($lastUpdatedResult !== false && db_num_rows($lastUpdatedResult) > 0) {
                $lastUpdatedRow = db_fetch_assoc($lastUpdatedResult);
                if ($lastUpdatedRow !== false && $lastUpdatedRow !== false) {
                    $faLastUpdated = $lastUpdatedRow['modified_date'];
                }
            }
            $sql = "INSERT INTO {$tablePrefix}0_square_tokens (stock_id, sku, square_catalog_object_id, square_variation_id, fa_last_updated, created_at) VALUES (" .
                db_escape($stockId) . ", " . db_escape($sku) . ", " . db_escape($catalogObject->getId()) . ", " . db_escape($catalogObject->getItemVariationData()->getId()) . ", " .
                ($faLastUpdated !== null ? "'" . db_escape($faLastUpdated) . "'" : "NULL") . ", NOW()) " .
                "ON DUPLICATE KEY UPDATE square_catalog_object_id = VALUES(square_catalog_object_id), square_variation_id = VALUES(square_variation_id), fa_last_updated = VALUES(fa_last_updated), updated_at = NOW()";
            db_query($sql);

            if ($uploadImages) {
                    $imageDir = company_path() . '/images/';
                    $imageDir = rtrim($imageDir, '/');
                    $sqId = $catalogObject->getId();

                    display_notification(_("  Uploading images..."));
                    $exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, 0, true);
                    display_notification(_("  Primary image uploaded"));

                    for ($idx = 1; $idx <= 10; $idx++) {
                        if (!$exporter->uploadImage($sqId, $stockId, $item['description'], $imageDir, $idx)) {
                            break;
                        }
                        display_notification(_("  Additional image ") . $idx . _(" uploaded"));
                    }
                }
            } catch (SquareException $e) {
                display_error(_("  FAILED: ") . $stockId . _(" -> ") . $e->getMessage());
                $errors[] = $stockId . ': ' . $e->getMessage();
            } catch (ApiException $e) {
                display_error(_("  API ERROR: ") . $stockId . _(" -> ") . $e->getMessage());
                $errors[] = $stockId . ': API - ' . $e->getMessage();
            }

            usleep(500000);

            if ($processed >= $maxItems) break;
        }

        display_notification(_("Checking for items to delete from Square..."));
        foreach ($existingSquareItems as $sqSku => $sqItem) {
            $chkSql = "SELECT COUNT(*) AS cnt FROM {$tablePrefix}stock_master WHERE stock_id = " . db_escape($sqSku) . " AND inactive = 0";
            $chkResult = db_query($chkSql);
            $chkRow = false;
            if ($chkResult !== false) {
                $chkRow = db_fetch_assoc($chkResult);
            }

            if ($chkRow && (int)$chkRow['cnt'] == 0) {
                try {
                    display_notification(_("Deleting from Square: ") . $sqSku);
                    $exporter->deleteProduct($sqItem->getId());
                    $deleted++;
                } catch (Exception $e) {
                    display_error(_("Failed to delete ") . $sqSku . _(": ") . $e->getMessage());
                    $errors[] = 'Delete ' . $sqSku . ': ' . $e->getMessage();
                }
            }
        }

        $msg = _("Export complete. Exported: ") . $exported . _(", Skipped: ") . $skipped . _(", Deleted: ") . $deleted;
        if (count($errors) > 0) {
            $msg .= _(", Errors: ") . count($errors);
        }

    } catch (ApiException $e) {
        $error = _("API Error: ") . $e->getMessage();
    } catch (Exception $e) {
        $error = _("Error: ") . $e->getMessage();
    }
}

start_form(true);
start_table(TABLESTYLE2, "width=40%");
table_section_title(_("Export Inventory Options"));

currencies_list_row(_("Customer Currency:"), 'currency', get_company_pref('curr_default'));
sales_types_list_row(_("Sales Type:"), 'sales_type', null);
locations_list_row(_("FA Location:"), 'default_location', null);

if (count($squareLocations) > 0) {
    $locList = array_merge(['0' => _('All')], $squareLocations);
    echo '<tr><td class="label">' . _("Square Location:") . '</td><td>';
    echo array_selector('location_id', '0', $locList, [
        'select_submit' => false,
        'async' => false,
    ]);
    echo '</td></tr>';
} else {
    hidden('location_id', '0');
}

stock_categories_list_row(_("Category:"), 'category', null, _("All Categories"));
text_row(_("Stock ID Pattern:"), 'stocklike', null, 10, 20);
text_row(_("Max Items (0=unlimited):"), 'max_items', '10', 5, 10);
yesno_list_row(_("Upload Images:"), 'upload', null);
yesno_list_row(_("Available Online:"), 'online', null);
yesno_list_row(_("Send Inactive Items:", true), 'send_inactive', null);
yesno_list_row(_("Full Sync (ignore existing mappings):"), 'full_sync', null);
yesno_list_row(_("Full Sync (ignore existing mappings):"), 'full_sync', null);

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
