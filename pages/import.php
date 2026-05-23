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
use Ksfraser\Frontaccounting\SquareUp\Services\ImportService;

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
    $trialRun = (bool)($_POST['trial_run'] ?? 0);
    $adjustmentItem = $_POST['adjustment'] ?? '';
    $tipsItem = $_POST['tips'] ?? '';
    $locationFilter = $_POST['location_id'] ?? '';

    try {
        $importService = new ImportService($tablePrefix, $settings, $client);
        $validation = $importService->validateImportParams($destCust, $fromDate, $toDate);

        if (!$validation['valid']) {
            $error = $validation['error'];
        } else {
            $results = $importService->performImport(
                $validation['customer'],
                $fromDate,
                $toDate,
                $trialRun,
                $adjustmentItem,
                $tipsItem,
                $locationFilter,
                $locations
            );

            // Display all messages from the import
            foreach ($results['errors'] as $message) {
                if (strpos($message, _("TRIAL:")) !== false || strpos($message, _("Skipping")) !== false) {
                    display_notification($message);
                } elseif (strpos($message, _("Adjustment needed")) !== false) {
                    display_warning($message);
                } elseif (strpos($message, _("Created invoice")) !== false) {
                    display_notification($message);
                }
            }

            $msg = $results['msg'];
            if (isset($results['error']) && $results['error'] !== '') {
                $error = $results['error'];
            }
        }
    } catch (ApiException $e) {
        $error = _("API Error: ") . $e->getMessage();
    } catch (Exception $e) {
        $error = _("Error: ") . $e->getMessage();
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
