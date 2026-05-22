<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareVIEW';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory;
use Square\Exceptions\ApiException;

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
$settings = Settings::fromFADatabase($tablePrefix);
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
body { background-color: ' . ($env === 'production' ? '#fff5f5' : '#fffde7') . '; }
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
label_row(_("Last Import Date:"), $settings->getLastImportDate()?->format('Y-m-d H:i:s') ?? _('Never'));

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

table_section_title(_("Import Log (Last 10 Runs)"));

$sql = "SELECT * FROM {$tablePrefix}square_import_log ORDER BY run_date DESC LIMIT 10";
$result = db_query($sql);
if (db_num_rows($result) > 0) {
    $th = array(_("Run Date"), _("Source"), _("Imported"), _("Skipped"), _("Failed"), _("Status"));
    table_header($th);
    $k = 0;
    while ($row = db_fetch_assoc($result)) {
        alt_table_row_color($k);
        label_cell($row['run_date']);
        label_cell($row['source']);
        label_cell($row['orders_imported']);
        label_cell($row['orders_skipped']);
        label_cell($row['orders_failed']);
        label_cell($row['status']);
        end_row();
    }
    end_table(1);
} else {
    label_row(_("No import runs yet"), "");
    end_table(1);
}

end_page();
