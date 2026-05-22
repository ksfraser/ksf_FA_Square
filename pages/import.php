<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";
include_once $path_to_root . "/sales/includes/db/customers_db.inc";
include_once $path_to_root . "/sales/includes/db/sales_order_db.inc";
include_once $path_to_root . "/sales/includes/cart_class.inc";
include_once $path_to_root . "/sales/includes/ui/sales_order_ui.inc";
include_once $path_to_root . "/inventory/includes/db/items_prices_db.inc";
include_once $path_to_root . "/taxes/db/item_tax_types_db.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory;
use Ksfraser\Frontaccounting\SquareUp\Pull\OrderImporter;
use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;

use Square\Exceptions\ApiException;

if (!function_exists('sales_service_items_list_row')) {
    function sales_service_items_list_row($label, $name, $selected_id = null, $all_option = false, $submit_on_change = false) {
        echo '<tr>';
        if ($label !== null) {
            echo '<td class="label">' . $label . '</td>';
        }
        echo '<td>';
        echo sales_items_list($name, $selected_id, $all_option, $submit_on_change,
            'local', ['where' => ["mb_flag='D'"], 'cells' => false, 'editable' => false]);
        echo '</td></tr>';
    }
}

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
try {
    $settings = Settings::fromFADatabase($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    display_error(_("Failed to load configuration: ") . $e->getMessage());
}
$accessToken = $settings->getAccessToken();

$help_context = "Import Square Orders";
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
    $locations = [];
    if ($locResponse->isSuccess()) {
        foreach ($locResponse->getResult()->getLocations() as $loc) {
            $locations[$loc->getId()] = $loc->getName();
        }
    }
} catch (Exception $e) {
    display_error(_("API Connection Error: ") . $e->getMessage());
    $locations = [];
}

$msg = '';
$error = '';

if (isset($_POST['action']) && $_POST['action'] == 'o_import') {
    $destCust = (int)($_POST['destCust'] ?? 0);
    $fromDate = $_POST['from_date'] ?? '';
    $toDate = $_POST['to_date'] ?? '';
    $trialRun = (int)($_POST['trial_run'] ?? 0);
    $adjustmentItem = $_POST['adjustment'] ?? '';
    $tipsItem = $_POST['tips'] ?? '';
    $locationFilter = $_POST['location_id'] ?? '';

    if ($destCust <= 0) {
        $error = _("Please select a destination customer.");
    } elseif ($fromDate === '' || $toDate === '') {
        $error = _("Please select a date range.");
    } else {
        $sql = "SELECT * FROM {$tablePrefix}debtors_master WHERE debtor_no = " . $destCust;
        $result = db_query($sql);
        $customer = false;
        if ($result !== false) {
            $customer = db_fetch_assoc($result);
        }
        if (!$customer) {
            $error = _("Customer not found.");
        } else {
            $stagingManager = new StagingTableManager($tablePrefix);
            $stagingManager->createStagingTables();

            $importResults = [
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ];

            try {
                $orderImporter = new OrderImporter($client, $settings);

                $fromDateTime = new \DateTimeImmutable($fromDate);
                $toDateTime = new \DateTimeImmutable($toDate);

                foreach ($locations as $locId => $locName) {
                    if ($locationFilter !== '' && $locationFilter !== $locId) {
                        continue;
                    }

                    $sql = "SELECT * FROM {$tablePrefix}cust_branch "
                        . "WHERE debtor_no = " . $destCust . " AND br_name = " . db_escape($locName);
                    $branchResult = db_query($sql);
                    $branch = false;
                    if ($branchResult !== false) {
                        $branch = db_fetch_assoc($branchResult);
                    }
                    if (!$branch) {
                        display_notification(_("Skipping location: ") . $locName . _(" - no matching FA branch"));
                        continue;
                    }

                    $payments = $orderImporter->listPayments($fromDateTime, $toDateTime, $locId);

                    foreach ($payments as $payment) {
                        $paymentMethod = str_replace("CREDIT_CARD", "CARD", $payment->getSourceType());
                        if ($paymentMethod === "NO_SALE") {
                            continue;
                        }

                        $sql = "SELECT COUNT(*) AS cnt FROM {$tablePrefix}sales_orders "
                            . "WHERE customer_ref = " . db_escape($payment->getId());
                        $chkResult = db_query($sql);
                        $chkRow = false;
                        if ($chkResult !== false) {
                            $chkRow = db_fetch_assoc($chkResult);
                        }
                        if ($chkRow && (int)$chkRow['cnt'] > 0) {
                            display_notification(_("Skipping (already imported): ") . $payment->getId());
                            $importResults['skipped']++;
                            continue;
                        }

                        $refunded = $payment->getRefundedMoney();
                        if ($refunded !== null && $refunded->getAmount() != 0) {
                            display_notification(_("Skipping refund: ") . $payment->getId());
                            $importResults['skipped']++;
                            continue;
                        }

                        $orderId = $payment->getOrderId();
                        if ($orderId === null) {
                            continue;
                        }

                        $result = $orderImporter->getPaymentWithOrder($payment->getId());
                        $order = $result['order'];
                        if ($order === null) {
                            display_notification(_("Cannot retrieve order: ") . $orderId);
                            $importResults['skipped']++;
                            continue;
                        }

                        $lineItems = $order->getLineItems();
                        if ($lineItems === null) {
                            continue;
                        }

                        if ($trialRun) {
                            display_notification(_("TRIAL: Would import ") . $payment->getId()
                                . " (" . $payment->getTotalMoney()->getAmount() / 100 . " "
                                . $payment->getTotalMoney()->getCurrency() . ")");
                        } else {
                            $cart = new Cart(ST_SALESINVOICE);
                            $cart->customer_id = $customer['debtor_no'];
                            $cart->customer_currency = $customer['curr_code'];
                            $cart->Comments = 'Imported from Square: ' . $payment->getId();

                            $dt = \DateTime::createFromFormat(
                                'Y-m-d\TH:i:s\Z',
                                $payment->getCreatedAt(),
                                new \DateTimeZone('UTC')
                            );
                            if ($dt) {
                                $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                                $cart->document_date = $dt->format('Y-m-d');
                                $cart->due_date = $dt->format('Y-m-d');
                            }

                            $cart->set_branch(
                                $branch['branch_code'],
                                $branch['tax_group_id'],
                                $branch['br_address']
                            );
                            $cart->cust_ref = $payment->getId();

                            $taxType = '';
                            $orderTaxes = $order->getTaxes();
                            if ($orderTaxes !== null && count($orderTaxes) > 0) {
                                $taxType = $orderTaxes[0]->getType();
                            }

                            $catApi = $client->getCatalogApi();
                            foreach ($lineItems as $item) {
                                $catObjId = $item->getCatalogObjectId();
                                $sku = $item->getName();
                                if ($catObjId !== null) {
                                    try {
                                        $catResponse = $catApi->retrieveCatalogObject($catObjId, false);
                                        if ($catResponse->isSuccess()) {
                                            $catObj = $catResponse->getResult()->getObject();
                                            if ($catObj !== null) {
                                                $varData = $catObj->getItemVariationData();
                                                if ($varData !== null && $varData->getSku() !== null) {
                                                    $sku = $varData->getSku();
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                    }
                                }

                                $basePrice = $item->getBasePriceMoney();
                                $unitPrice = $basePrice !== null ? $basePrice->getAmount() / 100 : 0;

                                $discount = 0;
                                if ($taxType === "INCLUSIVE") {
                                    $totalPrice = $item->getVariationTotalPriceMoney();
                                    $totalAmt = $totalPrice !== null ? $totalPrice->getAmount() : 1;
                                    $discMoney = $item->getTotalDiscountMoney();
                                    if ($discMoney !== null && $totalAmt > 0) {
                                        $discount = $discMoney->getAmount() / $totalAmt;
                                    }
                                } else {
                                    $grossSales = $item->getGrossSalesMoney();
                                    $grossAmt = $grossSales !== null ? $grossSales->getAmount() : 1;
                                    $discMoney = $item->getTotalDiscountMoney();
                                    if ($discMoney !== null && $grossAmt > 0) {
                                        $discount = $discMoney->getAmount() / $grossAmt;
                                    }
                                }

                                add_to_order($cart, $sku, $item->getQuantity(), $unitPrice, $discount);
                            }

                            $tipMoney = $payment->getTipMoney();
                            if ($tipMoney !== null && $tipMoney->getAmount() != 0 && $tipsItem !== '') {
                                add_to_order($cart, $tipsItem, 1, $tipMoney->getAmount() / 100, 0);
                            }

                            $total = $cart->get_trans_total();
                            $totalOrder = $payment->getTotalMoney()->getAmount() / 100;
                            $adj = round($totalOrder - $total, 2);
                            if ($adj != 0 && $adjustmentItem !== '') {
                                display_warning(_("Adjustment needed: ") . $adj);
                                add_to_order($cart, $adjustmentItem, 1, $adj, 0);
                            }

                            $orderNo = $cart->write(1);
                            display_notification(_("Created invoice #") . $orderNo
                                . _(" for ") . $payment->getId());
                            $importResults['imported']++;
                        }
                    }
                }

                if (!$trialRun) {
                    $sql = "INSERT INTO {$tablePrefix}square_import_log "
                        . "(run_date, source, orders_imported, orders_skipped, orders_failed, status) VALUES ("
                        . "'" . date('Y-m-d H:i:s') . "', 'api', "
                        . $importResults['imported'] . ", "
                        . $importResults['skipped'] . ", "
                        . $importResults['failed'] . ", 'completed')";
                    db_query($sql);

                    $newLastDate = date('Y-m-d', strtotime($toDate));
                    $lastSetting = $settings->getLastImportDate();
                    if ($lastSetting === null || $newLastDate > $lastSetting->format('Y-m-d')) {
                        $table = $tablePrefix . 'square';
                        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'lastdate'";
                        $result = db_query($sql);
                        $row = false;
                        if ($result !== false) {
                            $row = db_fetch_assoc($result);
                        }
                        if ($row && (int)$row['cnt'] > 0) {
                            $sql = "UPDATE {$table} SET value = '{$newLastDate}' WHERE name = 'lastdate'";
                        } else {
                            $sql = "INSERT INTO {$table} (name, value) VALUES ('lastdate', '{$newLastDate}')";
                        }
                        db_query($sql);
                    }
                }

                $msg = _("Import complete. Imported: ") . $importResults['imported']
                    . _(", Skipped: ") . $importResults['skipped']
                    . _(", Failed: ") . $importResults['failed'];

            } catch (ApiException $e) {
                $error = _("API Error: ") . $e->getMessage();
            } catch (Exception $e) {
                $error = _("Error: ") . $e->getMessage();
            }
        }
    }
}

start_form(true);
start_table(TABLESTYLE2, "width=40%");
table_section_title(_("Order Import Options"));

if (!isset($_POST['from_date'])) {
    $lastDate = $settings->getLastImportDate();
    $_POST['from_date'] = $lastDate !== null ? $lastDate->format('Y-m-d') : Today();
}
if (!isset($_POST['to_date'])) {
    $_POST['to_date'] = Today();
}

date_row(_("From Order Date:"), 'from_date');
date_row(_("To Order Date:"), 'to_date');
customer_list_row(_("Destination Customer:"), 'destCust', $settings->getDestinationCustomer() ?? 0, false);

if (count($locations) > 0) {
    echo '<tr><td class="label">' . _("Location Filter:") . '</td><td>';
    echo array_selector('location_id', '', $locations, [
        'select_submit' => false,
        'async' => false,
    ]);
    echo '</td></tr>';
} else {
    hidden('location_id', '');
}

sales_service_items_list_row(_("Adjustment Item:"), 'adjustment', null, false, false, false);
sales_service_items_list_row(_("Tips Item:"), 'tips', null, false, false, false);

yesno_list_row(_("Trial Run"), 'trial_run', null, "", "", false);

end_table(1);

if ($msg !== '') {
    display_notification($msg);
}
if ($error !== '') {
    display_error($error);
}

hidden('action', 'o_import');
submit_center('oimport', _("Import Orders"));

end_form();
end_page();
