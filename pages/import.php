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

use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\TransactionStagingDAO;
use ksfraser\FrontAccounting\Square\DAO\ItemStagingDAO;
use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;
use ksfraser\FrontAccounting\Square\Services\ImportService;
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
    $transactionStagingDao = new TransactionStagingDAO($tablePrefix);
    $itemStagingDao = new ItemStagingDAO($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    $transactionStagingDao = new TransactionStagingDAO($tablePrefix);
    $itemStagingDao = new ItemStagingDAO($tablePrefix);
    $error = _("Failed to load configuration: ") . $e->getMessage();
}
$accessToken = $settings->getAccessToken();

$help_context = "Import Square Orders";
page(_($help_context), false, false, "", "");

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff; background-color: ' . $badgeColor . '; margin-left: 8px; }
.square-status-card { display: inline-block; padding: 10px 20px; margin: 5px; border-radius: 4px; text-align: center; min-width: 100px; }
.status-staged { background-color: #fff3cd; color: #856404; }
.status-imported { background-color: #d4edda; color: #155724; }
.status-failed { background-color: #f8d7da; color: #721c24; }
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
        $resultLocs = $locResponse->getResult()->getLocations();
        if ($resultLocs !== null) {
            foreach ($resultLocs as $loc) {
                $locations[$loc->getId()] = $loc->getName();
            }
        }
    }
} catch (Exception $e) {
    display_error(_("API Connection Error: ") . $e->getMessage());
    $locations = [];
}

$msg = '';
$error = '';

try {
    $importService = new ImportService($tablePrefix, $settings, $client);
    $importService->ensureStagingTablesExist();
    $statusCounts = $transactionStagingDao->getStatusCounts($env);
} catch (Exception $e) {
    $statusCounts = [];
}

$action = $_POST['action'] ?? '';
$importMode = $_POST['import_mode'] ?? 'direct';
$editId = (int)($_GET['edit'] ?? 0);
$deleteId = (int)($_GET['delete'] ?? 0);

if ($deleteId > 0) {
    try {
        $transaction = $transactionStagingDao->getById($deleteId);
        if ($transaction !== null) {
            $itemStagingDao->deleteByTransactionId($transaction['transaction_id']);
            $transactionStagingDao->delete($deleteId);
            $msg = _("Transaction deleted successfully.");
            $statusCounts = $transactionStagingDao->getStatusCounts($env);
        }
    } catch (Exception $e) {
        $error = _("Error deleting transaction: ") . $e->getMessage();
    }
}

if ($action === 'save_edit') {
    $editId = (int)($_POST['edit_id'] ?? 0);
    if ($editId > 0) {
        try {
            $data = [
                'Date' => $_POST['edit_date'] ?? '',
                'Time' => $_POST['edit_time'] ?? '',
                'gross_sales' => (float)($_POST['edit_gross_sales'] ?? 0),
                'discounts' => (float)($_POST['edit_discounts'] ?? 0),
                'net_sales' => (float)($_POST['edit_net_sales'] ?? 0),
                'tax' => (float)($_POST['edit_tax'] ?? 0),
                'tip' => (float)($_POST['edit_tip'] ?? 0),
                'total_collected' => (float)($_POST['edit_total_collected'] ?? 0),
                'customer_name' => $_POST['edit_customer_name'] ?? '',
                'location' => $_POST['edit_location'] ?? '',
                'description' => $_POST['edit_description'] ?? '',
            ];

            $transactionStagingDao->update($editId, $data);
            $msg = _("Transaction updated successfully.");
            $editId = 0;
        } catch (Exception $e) {
            $error = _("Error updating transaction: ") . $e->getMessage();
        }
    }
}

if ($action === 'o_import') {
    $destCust = (int)($_POST['destCust'] ?? 0);
    $fromDate = $_POST['from_date'] ?? '';
    $toDate = $_POST['to_date'] ?? '';
    $trialRun = (bool)($_POST['trial_run'] ?? 0);
    $adjustmentItem = $_POST['adjustment'] ?? '';
    $tipsItem = $_POST['tips'] ?? '';
    $locationFilter = $_POST['location_id'] ?? '';

    try {
        $validation = $importService->validateImportParams($destCust, $fromDate, $toDate);

        if (!$validation['valid']) {
            $error = $validation['error'];
        } else {
            $customer = $validation['customer'];

            if ($importMode === 'stage') {
                $fromDateTime = new DateTimeImmutable($fromDate);
                $toDateTime = new DateTimeImmutable($toDate);

                $results = $importService->stageFromApi(
                    $fromDateTime,
                    $toDateTime,
                    $locationFilter,
                    $locations
                );

                foreach ($results['errors'] as $message) {
                    display_notification($message);
                }

                $msg = _("Staging complete. Staged: ") . $results['staged']
                    . _(", Skipped: ") . $results['skipped'];

                $statusCounts = $transactionStagingDao->getStatusCounts($env);
            } else {
                $results = $importService->performImport(
                    $customer,
                    $fromDate,
                    $toDate,
                    $trialRun,
                    $adjustmentItem,
                    $tipsItem,
                    $locationFilter,
                    $locations
                );

                foreach ($results['errors'] as $message) {
                    if (strpos($message, _("TRIAL:")) !== false) {
                        display_notification($message);
                    } elseif (strpos($message, _("Skipping")) !== false) {
                        display_notification($message);
                    } elseif (strpos($message, _("Adjustment needed")) !== false) {
                        display_warning($message);
                    } elseif (strpos($message, _("Created invoice")) !== false) {
                        display_notification($message);
                    } else {
                        display_notification($message);
                    }
                }

                $msg = $results['msg'] ?? '';
                if ($results['imported'] == 0 && $results['skipped'] == 0 && $results['payments_found'] == 0) {
                    $msg = _("No payments found for the selected date range and locations.");
                }

                if (isset($results['error']) && $results['error'] !== '') {
                    $error = $results['error'];
                }
            }
        }
    } catch (ApiException $e) {
        $error = _("API Error: ") . $e->getMessage();
    } catch (Exception $e) {
        $error = _("Error: ") . $e->getMessage();
    }
} elseif ($action === 'process_staged') {
    $processIds = $_POST['process_ids'] ?? [];
    $destCust = (int)($_POST['destCust'] ?? 0);
    $branchCode = (int)($_POST['branch_code'] ?? 0);
    $adjustmentItem = $_POST['adjustment'] ?? '';
    $tipsItem = $_POST['tips'] ?? '';
    $processedCount = 0;
    $failedCount = 0;

    if (empty($processIds)) {
        $error = _("No transactions selected for processing.");
    } else {
        try {
            $customer = $transactionStagingDao->getByStatus('staged');

            foreach ($processIds as $stagingId) {
                try {
                    $result = $importService->processStagedTransaction(
                        (int)$stagingId,
                        $destCust,
                        $branchCode,
                        $adjustmentItem,
                        $tipsItem
                    );

                    if ($result['success']) {
                        display_notification($result['message']);
                        $processedCount++;
                    } else {
                        display_warning($result['message']);
                    }
                } catch (Exception $e) {
                    display_error(_("Error processing transaction ") . $stagingId . ": " . $e->getMessage());
                    $failedCount++;
                }
            }

            $statusCounts = $transactionStagingDao->getStatusCounts($env);
            $msg = _("Processing complete. Processed: ") . $processedCount . _(", Failed: ") . $failedCount;
        } catch (Exception $e) {
            $error = _("Error: ") . $e->getMessage();
        }
    }
}

start_form(true);

if (!empty($statusCounts)) {
    table_section_title(_("Staging Status"));
    start_table(TABLESTYLE, "width=60%");

    echo '<tr><td colspan="4" align="center">';

    $stagedCount = $statusCounts[TransactionStagingDAO::STATUS_STAGED] ?? 0;
    $importedCount = $statusCounts[TransactionStagingDAO::STATUS_IMPORTED] ?? 0;
    $failedCount = $statusCounts[TransactionStagingDAO::STATUS_FAILED] ?? 0;
    $matchedCount = $statusCounts[TransactionStagingDAO::STATUS_MATCHED] ?? 0;

    echo '<div class="square-status-card status-staged">';
    echo '<div style="font-size: 1.5em; font-weight: bold;">' . $stagedCount . '</div>';
    echo '<div>' . _("Staged") . '</div>';
    echo '</div>';

    echo '<div class="square-status-card status-imported">';
    echo '<div style="font-size: 1.5em; font-weight: bold;">' . $importedCount . '</div>';
    echo '<div>' . _("Imported") . '</div>';
    echo '</div>';

    if ($failedCount > 0) {
        echo '<div class="square-status-card status-failed">';
        echo '<div style="font-size: 1.5em; font-weight: bold;">' . $failedCount . '</div>';
        echo '<div>' . _("Failed") . '</div>';
        echo '</div>';
    }

    echo '</td></tr>';
    end_table(1);
}

if ($editId > 0) {
    $editTransaction = $transactionStagingDao->getById($editId);
    if ($editTransaction !== null) {
        table_section_title(_("Edit Staged Transaction"));
        start_form(true);
        start_table(TABLESTYLE2, "width=60%");

        echo '<tr><td class="label">' . _("Transaction ID:") . '</td><td>' . htmlspecialchars($editTransaction['transaction_id'] ?? '') . '</td></tr>';
        echo '<tr><td class="label">' . _("Payment ID:") . '</td><td>' . htmlspecialchars($editTransaction['payment_id'] ?? '') . '</td></tr>';

        echo '<tr><td class="label">' . _("Date:") . '</td><td>';
        date_cells('edit_date', $editTransaction['Date'] ?? '');
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Time:") . '</td><td>';
        echo '<input type="text" name="edit_time" value="' . htmlspecialchars($editTransaction['Time'] ?? '') . '" size="10">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Customer Name:") . '</td><td>';
        echo '<input type="text" name="edit_customer_name" value="' . htmlspecialchars($editTransaction['customer_name'] ?? '') . '" size="40">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Location:") . '</td><td>';
        echo '<input type="text" name="edit_location" value="' . htmlspecialchars($editTransaction['location'] ?? '') . '" size="30">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Gross Sales:") . '</td><td>';
        echo '<input type="number" name="edit_gross_sales" value="' . htmlspecialchars((string)($editTransaction['gross_sales'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Discounts:") . '</td><td>';
        echo '<input type="number" name="edit_discounts" value="' . htmlspecialchars((string)($editTransaction['discounts'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Net Sales:") . '</td><td>';
        echo '<input type="number" name="edit_net_sales" value="' . htmlspecialchars((string)($editTransaction['net_sales'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Tax:") . '</td><td>';
        echo '<input type="number" name="edit_tax" value="' . htmlspecialchars((string)($editTransaction['tax'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Tip:") . '</td><td>';
        echo '<input type="number" name="edit_tip" value="' . htmlspecialchars((string)($editTransaction['tip'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Total Collected:") . '</td><td>';
        echo '<input type="number" name="edit_total_collected" value="' . htmlspecialchars((string)($editTransaction['total_collected'] ?? 0)) . '" step="0.01" size="15">';
        echo '</td></tr>';

        echo '<tr><td class="label">' . _("Description:") . '</td><td>';
        echo '<input type="text" name="edit_description" value="' . htmlspecialchars($editTransaction['description'] ?? '') . '" size="50">';
        echo '</td></tr>';

        end_table(1);

        hidden('action', 'save_edit');
        hidden('edit_id', (string)$editId);

        echo '<div style="text-align: center; margin: 10px;">';
        submit('save_edit_submit', _("Save Changes"));
        echo ' &nbsp; ';
        echo '<a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">' . _("Cancel") . '</a>';
        echo '</div>';

        end_form();

        $editItems = $itemStagingDao->getByTransactionId($editTransaction['transaction_id'] ?? '');
        if (!empty($editItems)) {
            table_section_title(_("Line Items"));
            start_table(TABLESTYLE, "width=90%");

            $th = array(
                _("Item"),
                _("SKU"),
                _("Qty"),
                _("Unit Price"),
                _("Net Sales"),
                _("Tax"),
            );
            table_header($th);

            foreach ($editItems as $item) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($item['name'] ?? $item['Item'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($item['sku'] ?? $item['stock_id'] ?? '') . '</td>';
                echo '<td align="right">' . htmlspecialchars((string)($item['quantity'] ?? 0)) . '</td>';
                echo '<td align="right">' . number_format((float)($item['unit_price'] ?? $item['Price_Point_Name'] ?? 0), 2) . '</td>';
                echo '<td align="right">' . number_format((float)($item['net_sales'] ?? 0), 2) . '</td>';
                echo '<td align="right">' . number_format((float)($item['tax'] ?? 0), 2) . '</td>';
                echo '</tr>';
            }

            end_table(1);
        }

        echo '<hr>';
    }
}

$stagedTransactions = $transactionStagingDao->getByStatus(
    TransactionStagingDAO::STATUS_STAGED,
    $env
);

if (!empty($stagedTransactions)) {
    table_section_title(_("Staged Transactions (Ready to Process)"));
    start_table(TABLESTYLE, "width=95%");

    $th = array(
        '',
        _("Date"),
        _("Transaction ID"),
        _("Payment ID"),
        _("Location"),
        _("Customer"),
        _("Total"),
        _("Tax"),
        _("Tip"),
        _("Actions"),
    );
    table_header($th);

    foreach ($stagedTransactions as $trans) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="process_ids[]" value="' . htmlspecialchars((string)$trans['id']) . '"></td>';
        echo '<td>' . htmlspecialchars($trans['Date'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['transaction_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['payment_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['location'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['customer_name'] ?? '') . '</td>';
        echo '<td align="right">' . number_format((float)($trans['total_collected'] ?? 0), 2) . '</td>';
        echo '<td align="right">' . number_format((float)($trans['tax'] ?? 0), 2) . '</td>';
        echo '<td align="right">' . number_format((float)($trans['tip'] ?? 0), 2) . '</td>';
        echo '<td>';
        echo '<a href="?edit=' . htmlspecialchars((string)$trans['id']) . '">' . _("Edit") . '</a>';
        echo ' | ';
        echo '<a href="?delete=' . htmlspecialchars((string)$trans['id']) . '" onclick="return confirm(\'' . _("Are you sure you want to delete this transaction?") . '\');">' . _("Delete") . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    end_table(1);

    table_section_title(_("Process Selected Staged Transactions"));
    start_table(TABLESTYLE2, "width=40%");

    customer_list_row(_("Destination Customer:"), 'destCust', $settings->getDestinationCustomer() ?? 0, false);

    $branches = [];
    $destCust = (int)($_POST['destCust'] ?? $settings->getDestinationCustomer() ?? 0);
    if ($destCust > 0) {
        $branchDao = new \ksfraser\FrontAccounting\Square\DAO\CustBranchDAO($tablePrefix);
        $customerBranches = $branchDao->getByDebtorNo($destCust);
        foreach ($customerBranches as $br) {
            $branches[$br['branch_code']] = $br['br_name'];
        }
    }

    if (count($branches) > 0) {
        echo '<tr><td class="label">' . _("Branch:") . '</td><td>';
        echo array_selector('branch_code', '', $branches, [
            'select_submit' => true,
            'async' => false,
        ]);
        echo '</td></tr>';
    } else {
        hidden('branch_code', '0');
    }

    sales_service_items_list_row(_("Adjustment Item:"), 'adjustment', null, false, false, false);
    sales_service_items_list_row(_("Tips Item:"), 'tips', null, false, false, false);

    end_table(1);

    hidden('action', 'process_staged');
    submit_center('process_staged_submit', _("Process Selected Transactions"));

    echo '<hr>';
}

table_section_title(_("Import Options"));
start_table(TABLESTYLE2, "width=40%");

$modeOptions = [
    'direct' => _("Direct Import (Legacy)"),
    'stage' => _("Stage Only (Pull from API to Staging)"),
];
echo '<tr><td class="label">' . _("Import Mode:") . '</td><td>';
echo array_selector('import_mode', $importMode, $modeOptions, [
    'select_submit' => false,
    'async' => false,
]);
echo '</td></tr>';

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
    $locationOptions = ['' => _("All Locations")] + $locations;
    echo array_selector('location_id', '', $locationOptions, [
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
submit_center('oimport', _("Run Import"));

end_form();
end_page();
