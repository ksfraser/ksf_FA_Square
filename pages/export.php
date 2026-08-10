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
use Ksfraser\Frontaccounting\SquareUp\DTO\ExportRequest;
use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory;
use Ksfraser\Frontaccounting\SquareUp\Push\CatalogExporter;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareTokenDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMasterDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\ProductAttributesDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\LocationMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\Services\TaxRateResolver;
use Ksfraser\Frontaccounting\SquareUp\ValueObjects\SquarePrice;
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
$taxResolver = new TaxRateResolver();

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

/**
 * Tries to discover ksf_generate_catalogue module using hook_invoke.
 * This is the preferred method for inter-module communication.
 * 
 * @param string $tablePrefix Database table prefix
 * @return array|null Array with 'installed' and 'prefs_table' if found, null otherwise
 */
function discoverKsfGenCatalogueViaHooks(string $tablePrefix): ?array {
    global $Hooks;

    // Common ksf_generate module names to try
    $moduleNames = [
        'ksf_generate',
        'ksf_generate_catalogue',
        'ksf_gen_catalogue',
    ];

    foreach ($moduleNames as $moduleName) {
        // Check if the module's hooks are registered
        if (isset($Hooks[$moduleName])) {
            // Try to get constants from the module
            $data = [];
            $constants = hook_invoke($moduleName, 'getModuleConstants', $data);

            if ($constants !== null) {
                // Check if it has the prefs table constant
                if (isset($constants['KSF_GENERATE_CATALOGUE_PREFS'])) {
                    return [
                        'installed' => true,
                        'prefs_table' => $constants['KSF_GENERATE_CATALOGUE_PREFS'],
                        'via_hooks' => true,
                        'module_name' => $moduleName,
                    ];
                }
            }

            // Also try the generic capability request method
            $data2 = [];
            $response = hook_invoke($moduleName, 'respondToCapabilityRequest', $data2, ['request' => 'constants']);

            if ($response !== null && is_array($response)) {
                if (isset($response['KSF_GENERATE_CATALOGUE_PREFS'])) {
                    return [
                        'installed' => true,
                        'prefs_table' => $response['KSF_GENERATE_CATALOGUE_PREFS'],
                        'via_hooks' => true,
                        'module_name' => $moduleName,
                    ];
                }
            }
        }
    }

    return null;
}

/**
 * Detects if ksf_generate_catalogue module is installed.
 * First tries hook_invoke (preferred), then falls back to other methods.
 * 
 * @param string $tablePrefix Database table prefix
 * @return bool True if module is detected, false otherwise
 */
function isKsfGenCatalogueInstalled(string $tablePrefix): bool {
    // 1. Try hook_invoke first (preferred method for inter-module communication)
    $discovered = discoverKsfGenCatalogueViaHooks($tablePrefix);
    if ($discovered !== null && $discovered['installed']) {
        return true;
    }

    // 2. Check if constant is defined (might be set in hooks or loaded elsewhere)
    if (defined('KSF_GENERATE_CATALOGUE_PREFS')) {
        return true;
    }

    // 3. Check if the preferences table exists in the database
    // This is the most reliable indicator since we need this table anyway
    $checkTable = db_query("SHOW TABLES LIKE '{$tablePrefix}ksf_gen_catalogue_prefs'");
    if ($checkTable !== false && db_num_rows($checkTable) > 0) {
        return true;
    }

    // 4. Check for module in common locations
    $modulePaths = [
        // Dev environment (temporary location)
        '/tmp/ksf_generate/',
        // Production environment (modules directory)
        dirname(__DIR__, 4) . '/modules/ksf_generate/',
        dirname(__DIR__, 3) . '/modules/ksf_generate/',
    ];

    foreach ($modulePaths as $path) {
        if (@file_exists($path . 'class.ksf_generate_catalogue.inc.php') || 
            @file_exists($path . 'hooks.php') ||
            @is_dir($path)) {
            return true;
        }
    }

    return false;
}

/**
 * Gets the ksf_generate_catalogue preferences.
 * First tries hook_invoke, then falls back to other methods.
 * 
 * @param string $tablePrefix Database table prefix
 * @return array Array of preferences, empty if module not installed
 */
function getKsfGenCataloguePrefs(string $tablePrefix): array {
    $prefs = [];

    // 1. Try hook_invoke first (preferred method)
    $discovered = discoverKsfGenCatalogueViaHooks($tablePrefix);
    if ($discovered !== null && isset($discovered['prefs_table'])) {
        $prefsTable = $discovered['prefs_table'];
        $pResult = db_query("SELECT `pref_name`, `value` FROM {$tablePrefix}{$prefsTable}");
        if ($pResult !== false) {
            while ($pRow = db_fetch_assoc($pResult)) {
                $prefs[$pRow['pref_name']] = $pRow['value'];
            }
        }
        return $prefs;
    }

    // 2. Determine the table name from constant or fallback
    $prefsTable = '';
    if (defined('KSF_GENERATE_CATALOGUE_PREFS')) {
        $prefsTable = KSF_GENERATE_CATALOGUE_PREFS;
    } else {
        // Check if the standard table name exists
        $checkTable = db_query("SHOW TABLES LIKE '{$tablePrefix}ksf_gen_catalogue_prefs'");
        if ($checkTable !== false && db_num_rows($checkTable) > 0) {
            $prefsTable = 'ksf_gen_catalogue_prefs';
        }
    }

    // 3. If we found a table, load the preferences
    if ($prefsTable !== '') {
        $pResult = db_query("SELECT `pref_name`, `value` FROM {$tablePrefix}{$prefsTable}");
        if ($pResult !== false) {
            while ($pRow = db_fetch_assoc($pResult)) {
                $prefs[$pRow['pref_name']] = $pRow['value'];
            }
        }
    }

    return $prefs;
}

// Check if ksf_generate_catalogue module is installed and load its prefs
$ksfGenCatalogueInstalled = isKsfGenCatalogueInstalled($tablePrefix);
$ksfGenPrefs = [];
if ($ksfGenCatalogueInstalled) {
    $ksfGenPrefs = getKsfGenCataloguePrefs($tablePrefix);
}

if (isset($_POST['action']) && $_POST['action'] == 'i_export') {
    $exportRequest = ExportRequest::fromPost(
        get_company_pref('curr_default'),
        0
    );

    $locationId = $exportRequest->getLocationId();
    $category = $exportRequest->getCategory();
    $stockLike = $exportRequest->getStockLike();
    $uploadImages = $exportRequest->shouldUploadImages();
    $availableOnline = $exportRequest->isAvailableOnline();
    $maxItems = $exportRequest->getMaxItems();
    $sendInactive = $exportRequest->shouldSendInactive();
    $fullSync = $exportRequest->isFullSync();

    set_time_limit(0);
    ob_implicit_flush(1);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    try {
        $env = $settings->getEnvironment();
        $tokenSource = $settings->getTokenSourceDescription();
        $token = $settings->getAccessToken();
        $tokenPrefix = substr($token ?? '', 0, 8);
        
        display_notification(_("Environment: ") . strtoupper($env) . _(" | Token source: ") . $tokenSource . _(" | Prefix: ") . $tokenPrefix . _("..."));
        display_notification(_("Square API connection: ") . (count($squareLocations) > 0 ? _("OK (") . count($squareLocations) . _(" locations found)") : _("FAILED")));

        $exporter = new CatalogExporter($client, $settings);

        // Ensure square_tokens table exists (handles auto-upgrade for environment column)
        $squareTokenDao = new SquareTokenDAO($tablePrefix, $env);
        $squareTokenDao->ensureTableExists();

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

        $locMappingDao = new LocationMappingDAO($tablePrefix);
        $locMappingDao->ensureTableExists();
        $allFaLocations = $locMappingDao->getAllFaLocations();
        $mappingsBySquareLoc = $locMappingDao->getMappingsBySquareLocation();
        $allLocationsMapping = $locMappingDao->getAllLocationsMapping();
        
        $hasMappings = (!empty($mappingsBySquareLoc) || $allLocationsMapping !== null);
        if ($hasMappings) {
            if ($allLocationsMapping !== null) {
                $locName = $squareLocations[$allLocationsMapping] ?? $allLocationsMapping;
                display_notification(_("Inventory mapping: All FA locations -> Square '") . $locName . _("'"));
            } else {
                display_notification(_("Found ") . count($mappingsBySquareLoc) . _(" Square location mappings"));
            }
        } else {
            display_notification(_("No location mappings configured. Inventory push will be skipped."));
        }

        $stockMasterDao = new StockMasterDAO($tablePrefix);
        $attributesDao = new ProductAttributesDAO($tablePrefix);
        $itemsResult = $stockMasterDao->getItemsForExport(
            $exportRequest->getCategoryId(),
            $exportRequest->getStockLike(),
            $exportRequest->shouldExcludeInactive(),
            $exportRequest->shouldSortRecent(),
            $ksfGenPrefs,
            $ksfGenCatalogueInstalled
        );

        $exported = 0;
        $skipped = 0;
        $deleted = 0;
        $errors = [];

        $totalFound = db_num_rows($itemsResult);
        display_notification(_("Total FA items to process: ") . $totalFound . _(" (limited to ") . $maxItems . _(")"));

        $processed = 0;
        while ($item = db_fetch_assoc($itemsResult)) {
            if ($item === false) break;
            $processed++;

            display_notification(_("[") . $processed . _("/") . $maxItems . _("] Processing ") . $item['stock_id'] . _(": ") . $item['description']);

            $stockId = $item['stock_id'];
            $sku = $stockId;

            $itemSku = $stockMasterDao->getItemSku($stockId);
            if ($itemSku !== null) {
                $sku = $itemSku;
                display_notification(_("  Using barcode SKU: ") . $sku);
            }

            $myPrice = $stockMasterDao->getItemPrice(
                $stockId,
                $exportRequest->getCurrency(),
                $exportRequest->getSalesType()
            );
            $squarePrice = SquarePrice::fromDollars($myPrice);
            $priceCents = $squarePrice->getCents();
            
            $warning = $squarePrice->getWarningMessage($stockId);
            if ($warning !== null) {
                display_notification(_("  ") . $warning);
            }

            $catName = $item['cat_description'] ?? 'General';
            $taxName = $item['tax_name'] ?? '';
            $taxRate = $taxResolver->resolveForItem(!empty($item['exempt']), $settings->getDefaultTaxGroup());

            $measurementUnitId = $attributesDao->getMeasurementUnitId($stockId);
            $customAttributes = $attributesDao->getCustomAttributes($stockId);
            $modifierLists = $attributesDao->getModifierLists($stockId);

            $attributes = [
                'measurement_unit_id'  => $measurementUnitId !== null ? (string)$measurementUnitId : null,
                'custom_attributes'    => is_array($customAttributes) ? $customAttributes : [],
                'modifier_lists'       => is_array($modifierLists) ? $modifierLists : [],
                'category_parent_name' => null,
                'fulfillment'          => $attributesDao->getFulfillment($stockId),
                'upc'                  => $attributesDao->getUpc($stockId),
            ];

            $categoryId = isset($item['category_id']) ? (int)$item['category_id'] : 0;
            if ($categoryId > 0) {
                $parentCategoryId = $attributesDao->getCategoryParent($categoryId);
                if ($parentCategoryId !== null) {
                    $parentName = $stockMasterDao->getCategoryName((int)$parentCategoryId);
                    if ($parentName !== null && $parentName !== '') {
                        $attributes['category_parent_name'] = $parentName;
                    }
                }
            }

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
            $displayName = $item['description'];
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
                    if ($oldName !== $displayName) $changes[] = 'desc: "' . ($oldName ?? '') . '" -> "' . $displayName . '"';
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
                    $displayName,
                    $item['description'],
                    $catName,
                    $priceCents,
                    $exportRequest->getCurrency(),
                    $taxName,
                    $taxRate,
                    $existingItem,
                    $attributes
                );

                $exported++;
                display_notification(_("  SUCCESS: ") . $item['stock_id'] . _(" -> Square ID: ") . $catalogObject->getId());

                // Record successful mapping in square_tokens with timestamp of last modification
                $faLastUpdated = $squareTokenDao->getFaLastUpdated($stockId);
                
                // Extract variation ID from the ITEM object
                $variationId = null;
                $itemData = $catalogObject->getItemData();
                if ($itemData !== null) {
                    $variations = $itemData->getVariations();
                    if ($variations !== null && count($variations) > 0) {
                        $variationId = $variations[0]->getId();
                    }
                }
                
                $squareTokenDao->upsertToken(
                    $stockId,
                    $sku,
                    $catalogObject->getId(),
                    $variationId,
                    $faLastUpdated
                );

                if ($hasMappings) {
                    display_notification(_("  Pushing inventory to Square..."));
                    
                    if ($allLocationsMapping !== null) {
                        $totalQoh = $locMappingDao->getQohForLocations($stockId, null);
                        $locName = $squareLocations[$allLocationsMapping] ?? $allLocationsMapping;
                        display_notification(_("    QOH (all locations): ") . $totalQoh . _(" -> Square '") . $locName . _("'"));
                        
                        try {
                            $exporter->pushInventory($catalogObject->getId(), $allLocationsMapping, (float)$totalQoh);
                            display_notification(_("    Inventory pushed successfully"));
                        } catch (SquareException $e) {
                            display_error(_("    Failed to push inventory: ") . $e->getMessage());
                            $errors[] = $stockId . ': Inventory push failed - ' . $e->getMessage();
                        }
                    } else {
                        $qohBySquareLoc = [];
                        
                        foreach ($mappingsBySquareLoc as $sqLocId => $faLocCodes) {
                            $qoh = $locMappingDao->getQohForLocations($stockId, $faLocCodes);
                            $qohBySquareLoc[$sqLocId] = $qoh;
                            
                            $locName = $squareLocations[$sqLocId] ?? $sqLocId;
                            display_notification(_("    QOH (") . implode(',', $faLocCodes) . _("): ") . $qoh . _(" -> Square '") . $locName . _("'"));
                        }
                        
                        $inventoryChanges = [];
                        foreach ($qohBySquareLoc as $sqLocId => $qoh) {
                            $inventoryChanges[] = [
                                'catalog_object_id' => $catalogObject->getId(),
                                'location_id' => $sqLocId,
                                'quantity' => (float)$qoh,
                            ];
                        }
                        
                        if (!empty($inventoryChanges)) {
                            try {
                                $exporter->batchPushInventory($inventoryChanges);
                                display_notification(_("    Inventory pushed successfully"));
                            } catch (SquareException $e) {
                                display_error(_("    Failed to push inventory: ") . $e->getMessage());
                                $errors[] = $stockId . ': Inventory push failed - ' . $e->getMessage();
                            }
                        }
                    }
                }

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
        $stockMasterDao = new StockMasterDAO($tablePrefix);
        foreach ($existingSquareItems as $sqSku => $sqItem) {
            $activeCount = $stockMasterDao->countActiveStockItems((string)$sqSku);

            if ($activeCount == 0) {
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
    } catch (Throwable $e) {
        $error = _("Error: ") . $e->getMessage() . " (" . get_class($e) . ")";
        error_log("Square export error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}
start_form(true);

if ($msg !== '') {
    echo '<div style="background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 12px 20px; margin: 10px 0;">';
    echo '<strong style="color: #155724;">' . _("Export Complete") . '</strong><br>';
    echo '<span style="color: #155724;">' . $msg . '</span>';
    echo '</div>';
}
if ($error !== '') {
    display_error($error);
}

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
yesno_list_row(_("Send Inactive Items:"), 'send_inactive', null);
yesno_list_row(_("Full Sync (ignore existing mappings):"), 'full_sync', null);

end_table(1);

hidden('action', 'i_export');
submit_center('pexport', _("Export FA Items To Square"));

end_form();
end_page();
