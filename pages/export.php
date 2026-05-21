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
use Square\SquareClient;
use Square\Environment;
use Square\Exceptions\ApiException;

$tablePrefix = '0_';
$settings = Settings::fromFADatabase($tablePrefix);
$accessToken = $settings->getAccessToken();

$help_context = "Export to Square";
page(_($help_context), false, false, "", "");

if ($accessToken === null || $accessToken === '') {
    display_error(_("Access Token not configured. Please configure in Square Configuration first."));
    end_page();
    return;
}

try {
    $client = new SquareClient([
        'accessToken' => $accessToken,
        'environment' => $settings->getEnvironment() === 'production'
            ? Environment::PRODUCTION
            : Environment::SANDBOX,
    ]);

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
        $catApi = $client->getCatalogApi();

        $existingSquareItems = [];
        $cursor = null;
        do {
            $listResponse = $catApi->listCatalog($cursor, 'ITEM');
            if ($listResponse->isSuccess()) {
                $objects = $listResponse->getResult()->getObjects();
                if ($objects !== null) {
                    foreach ($objects as $obj) {
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
                }
                $cursor = $listResponse->getCursor();
            } else {
                break;
            }
        } while ($cursor !== null);

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
            $catId = null;

            $searchResponse = $catApi->searchCatalogObjects(new \Square\Models\SearchCatalogObjectsRequest());
            $searchResponse->getBody()->setObjectTypes(["CATEGORY"]);
            $query = new \Square\Models\SearchCatalogObjectsRequest();
            $query->setObjectTypes(["CATEGORY"]);

            $exactQuery = new \Square\Models\CatalogQueryExact();
            $exactQuery->setAttributeName('name');
            $exactQuery->setAttributeValue($catName);

            $catQuery = new \Square\Models\CatalogQuery();
            $catQuery->setExactQuery($exactQuery);

            $query->setQuery($catQuery);

            try {
                $searchCatResponse = $catApi->searchCatalogObjects($query);
                if ($searchCatResponse->isSuccess()) {
                    $matchedObjects = $searchCatResponse->getResult()->getObjects();
                    if ($matchedObjects !== null && count($matchedObjects) > 0) {
                        $catId = $matchedObjects[0]->getId();
                    }
                }
            } catch (Exception $e) {
            }

            if ($catId === null) {
                $idemKey = uniqid();
                $catObj = new \Square\Models\CatalogObject('CATEGORY', '#cat_' . $stockId);
                $catData = new \Square\Models\CatalogCategory();
                $catData->setName($catName);
                $catObj->setCategoryData($catData);

                $upsertCatRequest = new \Square\Models\UpsertCatalogObjectRequest($idemKey, $catObj);
                try {
                    $catUpsertResponse = $catApi->upsertCatalogObject($upsertCatRequest);
                    if ($catUpsertResponse->isSuccess()) {
                        $catId = $catUpsertResponse->getResult()->getCatalogObject()->getId();
                    }
                } catch (Exception $e) {
                    display_error(_("Failed to create category: ") . $catName);
                    continue;
                }
            }

            $existingItem = $existingSquareItems[$sku] ?? $existingSquareItems[$stockId] ?? null;

            if ($existingItem !== null) {
                if ($existingItem->getPresentAtAllLocations() && $locationId !== '0') {
                    $skipped++;
                    continue;
                }
            }

            $idemKey = uniqid();
            $itemObj = new \Square\Models\CatalogObject('ITEM', '#item_' . $stockId);
            $itemData = new \Square\Models\CatalogItem();
            $itemData->setName(str_replace("Whitewater Hill ", "", $item['description']));
            $itemData->setDescription($item['description']);
            if ($catId !== null) {
                $itemData->setCategoryId($catId);
            }

            $variation = new \Square\Models\CatalogObject('ITEM_VARIATION', '#var_' . $stockId);
            $varData = new \Square\Models\CatalogItemVariation();
            $varData->setName($stockId);
            $varData->setSku($sku);
            $varData->setPricingType('FIXED_PRICING');

            $priceMoney = new \Square\Models\Money();
            $priceMoney->setAmount($priceCents);
            $priceMoney->setCurrency($_POST['currency'] ?? 'CAD');
            $varData->setPriceMoney($priceMoney);

            $variation->setItemVariationData($varData);
            $itemData->setVariations([$variation]);

            if ($availableOnline) {
                $itemData->setAvailableOnline(true);
            }

            $itemObj->setItemData($itemData);

            if ($locationId === '0') {
                $itemObj->setPresentAtAllLocations(true);
            } else {
                $itemObj->setPresentAtAllLocations(false);
                $itemObj->setPresentAtLocationIds([$locationId]);
            }

            $upsertRequest = new \Square\Models\UpsertCatalogObjectRequest($idemKey, $itemObj);
            try {
                $itemResponse = $catApi->upsertCatalogObject($upsertRequest);
                if ($itemResponse->isSuccess()) {
                    $sqId = $itemResponse->getResult()->getCatalogObject()->getId();
                    $exported++;

                    if ($uploadImages) {
                        $filename = company_path() . '/images/' . item_img_name($stockId) . '.jpg';
                        if (file_exists($filename)) {
                            $output = tempnam(sys_get_temp_dir(), "sq") . '.jpeg';
                            try {
                                $srcImg = @imagecreatefromjpeg($filename);
                                if ($srcImg !== false) {
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
                                    imagejpeg($finalImg, $output, 90);
                                    imagedestroy($srcImg);
                                    imagedestroy($finalImg);

                                    $idemKey2 = uniqid();
                                    $imageObj = new \Square\Models\CatalogObject('IMAGE', '#img_' . $stockId);
                                    $imgData = new \Square\Models\CatalogImage();
                                    $imgData->setCaption($item['description']);
                                    $imageObj->setImageData($imgData);

                                    $imgUpsertRequest = new \Square\Models\UpsertCatalogObjectRequest($idemKey2, $imageObj);
                                    $imgResponse = $catApi->upsertCatalogObject($imgUpsertRequest);
                                    if ($imgResponse->isSuccess()) {
                                        $imageId = $imgResponse->getResult()->getCatalogObject()->getId();
                                        $createImgReq = new \Square\Models\CreateCatalogImageRequest($idemKey2, $imageId);
                                        $createImgReq->setImage($imageObj);
                                    }
                                    unlink($output);
                                }
                            } catch (Exception $e) {
                                display_warning(_("Failed to upload image for ") . $stockId);
                            }
                        }
                    }

                    display_notification(_("Exported: ") . $item['description']);
                } else {
                    $errors = $itemResponse->getErrors();
                    if ($errors !== null) {
                        display_error(_("Failed to export ") . $stockId . ": " . print_r($errors, true));
                    }
                }
            } catch (ApiException $e) {
                display_error(_("API Error for ") . $stockId . ": " . $e->getMessage());
            }
        }

        foreach ($existingSquareItems as $sqSku => $sqItem) {
            if (!isset($sqItem['id'])) {
                continue;
            }
            if ($category > 0) {
                $itemData = $sqItem->getItemData();
                if ($itemData !== null && $itemData->getCategoryId() !== null) {
                    $sql = "SELECT category_id FROM {$tablePrefix}stock_category cat "
                        . "LEFT JOIN {$tablePrefix}stock_master item ON item.category_id = cat.category_id "
                        . "WHERE item.stock_id = " . db_escape($sqSku);
                    $catResult = db_query($sql);
                    $catRow = db_fetch_assoc($catResult);
                    if ($catRow && (int)$catRow['category_id'] !== $category) {
                        continue;
                    }
                }
            }

            $sqStockId = $sqSku;

            $sql = "SELECT COUNT(*) AS cnt FROM {$tablePrefix}stock_master WHERE stock_id = " . db_escape($sqStockId) . " AND inactive = 0";
            $chkResult = db_query($sql);
            $chkRow = db_fetch_assoc($chkResult);

            if ($chkRow['cnt'] == 0) {
                try {
                    $catApi->deleteCatalogObject($sqItem->getId());
                    display_notification(_("Deleted from Square: ") . $sqStockId);
                    $deleted++;
                } catch (Exception $e) {
                    display_error(_("Failed to delete ") . $sqStockId . ": " . $e->getMessage());
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
