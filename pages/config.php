<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = __DIR__ . "/../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\DebtorsMasterDAO;
use ksfraser\FrontAccounting\Square\DAO\LocationMappingDAO;
use ksfraser\FrontAccounting\Square\Staging\StagingTableManager;
use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;
use Square\Exceptions\ApiException;

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
$table = $tablePrefix . 'square';
$msg = '';
$error = '';

try {
    $settings = Settings::fromFADatabase($tablePrefix);
    $stagingManager = new StagingTableManager($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    $stagingManager = new StagingTableManager($tablePrefix);
    $error = _("Failed to load configuration: ") . $e->getMessage();
}

$accessToken = $settings->getAccessToken();
$squareLocations = [];
$faLocations = [];
$existingMappings = [];
$taxGroups = [];
if (function_exists('get_all_tax_groups')) {
    $taxGroupsResult = get_all_tax_groups(true);
    if ($taxGroupsResult !== false) {
        while ($taxGroupRow = db_fetch_assoc($taxGroupsResult)) {
            if ($taxGroupRow !== false) {
                $taxGroups[(int)$taxGroupRow['id']] = $taxGroupRow['name'];
            }
        }
    }
}

try {
    $locMappingDao = new LocationMappingDAO($tablePrefix);
    $locMappingDao->ensureTableExists();
    $faLocations = $locMappingDao->getAllFaLocations();
    $existingMappings = $locMappingDao->getAllMappings();
    
    if ($accessToken !== null && $accessToken !== '') {
        $client = SquareClientFactory::create($settings);
        $locationsApi = $client->getLocationsApi();
        $locResponse = $locationsApi->listLocations();
        if ($locResponse->isSuccess()) {
            $resultLocs = $locResponse->getResult()->getLocations();
            if ($resultLocs !== null) {
                foreach ($resultLocs as $loc) {
                    $squareLocations[$loc->getId()] = $loc->getName();
                }
            }
        }
    }
} catch (\Exception $e) {
    $squareLocations = [];
}

$mappingByFaLoc = [];
foreach ($existingMappings as $mapping) {
    $mappingByFaLoc[$mapping['fa_loc_code']] = $mapping['square_location_id'];
}

if (isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'update':
                $tokenFields = ['access_token', 'sandbox_access_token', 'production_access_token'];
                foreach ($tokenFields as $field) {
                    $value = $_POST[$field] ?? '';
                    if ($value !== '') {
                        Settings::saveToDatabase($tablePrefix, $field, $value);
                    }
                }

                $newEnv = $_POST['environment'] ?? 'sandbox';
                Settings::saveToDatabase($tablePrefix, 'environment', $newEnv);

                $defaultTaxGroup = $_POST['default_tax_group'] ?? '';
                Settings::saveToDatabase($tablePrefix, 'default_tax_group', $defaultTaxGroup);

                $msg = _("Configuration updated");
                $settings = Settings::fromFADatabase($tablePrefix);
                break;

            case 'save_mappings':
                $locMappingDao = new LocationMappingDAO($tablePrefix);
                
                $allMappingOption = $_POST['map_all_locations'] ?? '';
                if ($allMappingOption !== '' && $allMappingOption !== 'none') {
                    $locMappingDao->setMapping(LocationMappingDAO::ALL_LOCATIONS, $allMappingOption);
                } else {
                    $locMappingDao->removeMapping(LocationMappingDAO::ALL_LOCATIONS);
                }
                
                foreach ($faLocations as $faLoc) {
                    $faLocCode = $faLoc['loc_code'];
                    $mappingKey = 'map_loc_' . $faLocCode;
                    $selectedSquareLoc = $_POST[$mappingKey] ?? '';
                    
                    if ($selectedSquareLoc !== '') {
                        $locMappingDao->setMapping($faLocCode, $selectedSquareLoc);
                    } else {
                        $locMappingDao->removeMapping($faLocCode);
                    }
                }
                
                $msg = _("Location mappings saved");
                break;

            case 'create_tables':
                $stagingManager->createStagingTables();
                $msg = _("Staging tables created");
                break;

            case 'drop_tables':
                $stagingManager->dropStagingTables();
                $msg = _("Staging tables dropped");
                break;
        }
    } catch (\Exception $e) {
        $error = _("Error processing request: ") . $e->getMessage();
    }
}

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');

$help_context = "Square Configuration";
page(_($help_context), false, false, "", "");
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff !important; background-color: ' . $badgeColor . '; margin-left: 8px; }
.square-env-section { border: 2px solid ' . $badgeColor . '; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
</style>';

start_form();

start_table(TABLESTYLE);

table_section_title(_("Square API Configuration") . ' <span class="square-env-badge">' . $badgeText . '</span>');

$envOptions = ['sandbox' => _('Sandbox'), 'production' => _('Production')];
echo '<tr><td class="label">' . _("Environment:") . '</td><td>';
echo array_selector('environment', $env, $envOptions, [
    'onchange' => 'this.form.submit()',
    'select_submit' => false,
    'async' => false,
]);
echo '</td></tr>';

end_table(1);

echo '<div class="square-env-section">';

start_table(TABLESTYLE2);

if ($env === 'sandbox') {
    text_row(_("Sandbox Access Token:"), 'sandbox_access_token', $settings->getSandboxAccessToken() ?? '', 50, 100);
    label_row('', _('(Used when Environment is set to Sandbox)'));
}
if ($env === 'production') {
    text_row(_("Production Access Token:"), 'production_access_token', $settings->getProductionAccessToken() ?? '', 50, 100);
    label_row('', _('(Used when Environment is set to Production)'));
}
text_row(_("Legacy Access Token:"), 'access_token', $settings->getAccessToken() ?? '', 50, 100);
label_row('', _('(Fallback if env-specific token is empty)'));

end_table(1);
echo '</div>';

start_table(TABLESTYLE);

$lastDate = $settings->getLastImportDate();
label_row(_("Last Import Date:"), $lastDate !== null ? $lastDate->format('Y-m-d H:i:s') : _('Never'));

$destCust = $settings->getDestinationCustomer();
if ($destCust !== null) {
    $debtorsMasterDao = new DebtorsMasterDAO($tablePrefix);
    $customerName = $debtorsMasterDao->getCustomerName($destCust);
    label_row(_("Destination Customer:"), $customerName);
}

$defaultLoc = $settings->getDefaultLocation();
if ($defaultLoc !== null) {
    label_row(_("Default Location:"), $defaultLoc);
}

$defaultTaxGroup = $settings->getDefaultTaxGroup();
echo '<tr><td class="label">' . _("Default Tax Group:") . '</td><td>';
echo array_selector('default_tax_group', $defaultTaxGroup, $taxGroups, [
    'select_submit' => false,
    'async' => false,
]);
echo '</td></tr>';
label_row('', _('(Used to set the tax rate on items pushed to Square; leave unset to push items tax-free)'));

end_table(1);

if ($msg !== '') {
    display_notification($msg);
}
if ($error !== '') {
    display_error($error);
}

hidden('action', 'update');
submit_center('update', _("Update Configuration"));

end_form();

br();

if ($accessToken !== null && $accessToken !== '') {
    start_form();
    
    start_table(TABLESTYLE);
    table_section_title(_("Location Mapping (FA <-> Square)"));
    end_table(1);
    
    $squareLocOptions = ['none' => _('-- Not Mapped --')] + $squareLocations;
    
    start_table(TABLESTYLE2);
    
    $allMappingValue = $mappingByFaLoc[LocationMappingDAO::ALL_LOCATIONS] ?? 'none';
    echo '<tr><td class="label" style="font-weight: bold;">' . _("All Locations (sum QOH):") . '</td><td>';
    echo array_selector('map_all_locations', $allMappingValue, $squareLocOptions);
    echo '</td></tr>';
    
    label_row('', _('(If "All Locations" is mapped, individual location mappings are ignored. FA QOH summed across ALL locations.)'));
    
    end_table(1);
    
    br();
    
    start_table(TABLESTYLE);
    $th = [_("FA Location Code"), _("FA Location Name"), _("Map to Square Location")];
    table_header($th);
    
    $k = 0;
    foreach ($faLocations as $faLoc) {
        alt_table_row_color($k);
        $faLocCode = $faLoc['loc_code'];
        $mappedSquareLoc = $mappingByFaLoc[$faLocCode] ?? 'none';
        
        label_cell($faLocCode);
        label_cell($faLoc['location_name']);
        echo '<td>';
        echo array_selector('map_loc_' . $faLocCode, $mappedSquareLoc, $squareLocOptions);
        echo '</td>';
        end_row();
    }
    
    end_table(1);
    
    br();
    label_row(_("Mapping Behavior:"), "");
    label_row(_("N FA -> 1 Square:"), _("Multiple FA locations can map to the same Square location. QOH is summed across all mapped FA locations."));
    label_row(_("1 FA -> 1 Square:"), _("A single FA location maps to one Square location. QOH is passed through directly."));
    label_row(_("Not Mapped:"), _("FA locations not mapped will be included in 'All Locations' if that mapping is set; otherwise ignored for inventory push."));
    
    br();
    
    if ($msg !== '') {
        display_notification($msg);
    }
    if ($error !== '') {
        display_error($error);
    }
    
    hidden('action', 'save_mappings');
    submit_center('save_mappings', _("Save Location Mappings"));
    
    end_form();
    
    br();
}

start_form();

start_table(TABLESTYLE);

table_section_title(_("Staging Tables"));

$sql = "SHOW TABLES LIKE '{$tablePrefix}square_staging_transactions'";
$result = db_query($sql);
$tablesExist = $result !== false && db_num_rows($result) > 0;

label_row(_("Staging Tables Status:"), $tablesExist ? _("Created") : _("Not Created"));

end_table(1);

if ($tablesExist) {
    hidden('action', 'drop_tables');
    submit_center('drop', _("Drop Staging Tables"));
} else {
    hidden('action', 'create_tables');
    submit_center('create', _("Create Staging Tables"));
}

end_form();

end_page();
