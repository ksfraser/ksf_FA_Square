<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareVIEW';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;
use ksfraser\FrontAccounting\Square\DAO\SquareImportLogDAO;
use Square\Exceptions\ApiException;

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
try {
    $settings = Settings::fromFADatabase($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    display_error(_("Failed to load configuration: ") . $e->getMessage());
}
$accessToken = $settings->getAccessToken();

$help_context = "Square Dashboard";
page(_($help_context), false, false, "", "");

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff; background-color: ' . $badgeColor . '; margin-left: 8px; }
.square-sandbox { background: #fffde7; }
.square-production { background: #fff5f5; }
</style>';

echo '<div class="square-env-badge">' . $badgeText . '</div>';

start_table(TABLESTYLE);

table_section_title(_("Connection Status"));

if ($accessToken === null || $accessToken === '') {
    label_row(_("Status:"), _("Not configured - set Access Token in Configuration"));
    end_table(1);
    end_page();
    return;
}

label_row(_("Environment:"), $env === 'production' ? _("Production") : _("Sandbox"));
$lastDate = $settings->getLastImportDate();
label_row(_("Last Import Date:"), $lastDate !== null ? $lastDate->format('Y-m-d H:i:s') : _('Never'));

end_table(1);

try {
    $client = SquareClientFactory::create($settings);

    $locationsApi = $client->getLocationsApi();
    $apiResponse = $locationsApi->listLocations();

    if ($apiResponse->isSuccess()) {
        $locations = $apiResponse->getResult()->getLocations();
        $lastDate = $settings->getLastImportDate();

        br();
        start_table(TABLESTYLE);
        $th = array(_("Location"), _("Name"), _("Status"), _("Order Count"));
        table_header($th);

        $k = 0;
        foreach ($locations as $location) {
            alt_table_row_color($k);
            label_cell($location->getId());
            label_cell($location->getName());
            label_cell($location->getStatus());
            if ($lastDate !== null) {
                try {
                    $paymentsApi = $client->getPaymentsApi();
                    $fromDate = $lastDate->format('Y-m-d\TH:i:s\Z');
                    $toDate = gmdate('Y-m-d\TH:i:s\Z');
                    $paymentsResponse = $paymentsApi->listPayments($fromDate, $toDate, null, null, $location->getId(), null);
                    $payments = $paymentsResponse->getResult()->getPayments();
                    label_cell($payments !== null ? (string)count($payments) : '0');
                } catch (Exception $e) {
                    label_cell(_("Error"));
                }
            } else {
                label_cell(_("N/A"));
            }
            end_row();
        }
        end_table(1);
    } else {
        display_error(_("Failed to retrieve locations: ") . print_r($apiResponse->getErrors(), true));
    }
} catch (ApiException $e) {
    display_error(_("API Error: ") . $e->getMessage());
} catch (Exception $e) {
    display_error(_("Error: ") . $e->getMessage());
}

br();

start_table(TABLESTYLE);
table_section_title(_("Square Actions / Sync"));

$actions = [
    [
        'label' => _('Stage Customers from Square'),
        'url' => $path_to_root . '/modules/ksf_FA_Square/pages/import.php',
        'note' => _('Pull Square customers into Import Staging for review.'),
    ],
    [
        'label' => _('Export Customers to Square'),
        'url' => $path_to_root . '/modules/ksf_FA_Square/pages/export.php',
        'note' => _('Push FA debtors/customers to Square.'),
    ],
    [
        'label' => _('Import Square Orders'),
        'url' => $path_to_root . '/modules/ksf_FA_Square/pages/import.php',
        'note' => _('Import Square transactions into staging.'),
    ],
    [
        'label' => _('Export Items to Square'),
        'url' => $path_to_root . '/modules/ksf_FA_Square/pages/export.php',
        'note' => _('Push FA stock items to the Square catalog.'),
    ],
    [
        'label' => _('Review & Match Staged'),
        'url' => $path_to_root . '/modules/ksf_FA_Square/pages/review_match.php',
        'note' => _('Review staged transactions before importing.'),
    ],
];

$notImplemented = [
    _('Import Gift Cards') => _('Gift card staging is not yet wired to a responder.'),
    _('Export Gift Cards') => _('Gift card export is not yet implemented.'),
    _('Export Categories') => _('Category export is not yet implemented.'),
];

echo '<tr><td class="label">' . _("Available Actions:") . '</td><td>';
foreach ($actions as $action) {
    echo '<div style="margin: 4px 0;">';
    echo '<a href="' . htmlspecialchars($action['url']) . '" class="button">' . htmlspecialchars($action['label']) . '</a>';
    echo ' <span style="color:#666; font-size:0.9em;">— ' . htmlspecialchars($action['note']) . '</span>';
    echo '</div>';
}
echo '<hr style="margin:8px 0; border:none; border-top:1px solid #ddd;">';
echo '<div style="color:#888; font-size:0.9em;">' . _('Not yet implemented:') . '</div>';
foreach ($notImplemented as $label => $reason) {
    echo '<div style="margin: 2px 0;">' . htmlspecialchars($label) . ' — ' . htmlspecialchars($reason) . '</div>';
}
echo '</td></tr>';
end_table(1);

br();

start_table(TABLESTYLE);
table_section_title(_("Import Log (Last 10 Runs)"));

$squareImportLogDao = new SquareImportLogDAO($tablePrefix);
$logs = $squareImportLogDao->getRecentLogs(10);
$hasRows = count($logs) > 0;

if ($hasRows) {
    $th = array(_("Run Date"), _("Source"), _("Imported"), _("Skipped"), _("Failed"), _("Status"));
    table_header($th);
    $k = 0;
    foreach ($logs as $row) {
        alt_table_row_color($k);
        label_cell($row['run_date'] ?? '');
        label_cell($row['source'] ?? '');
        label_cell($row['orders_imported'] ?? 0);
        label_cell($row['orders_skipped'] ?? 0);
        label_cell($row['orders_failed'] ?? 0);
        label_cell($row['status'] ?? '');
        end_row();
    }
    end_table(1);
} else {
    label_row(_("No import runs yet"), "");
    end_table(1);
}

end_page();
