<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;

$tablePrefix = '0_';
$msg = '';

$settings = Settings::fromFADatabase($tablePrefix);
$stagingManager = new StagingTableManager($tablePrefix);
$env = $settings->getEnvironment();

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'update') {
        $table = $tablePrefix . 'square';

        $tokenFields = [
            'access_token',
            'sandbox_access_token',
            'production_access_token',
        ];

        foreach ($tokenFields as $field) {
            $value = $_POST[$field] ?? '';
            if ($value !== '') {
                $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = " . db_escape($field);
                $result = db_query($sql);
                $row = db_fetch_assoc($result);
                if ((int)$row['cnt'] > 0) {
                    $sql = "UPDATE {$table} SET value = " . db_escape($value) . " WHERE name = " . db_escape($field);
                } else {
                    $sql = "INSERT INTO {$table} (name, value) VALUES (" . db_escape($field) . ", " . db_escape($value) . ")";
                }
                db_query($sql);
            }
        }

        $env = $_POST['environment'] ?? 'sandbox';
        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'environment'";
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        if ((int)$row['cnt'] > 0) {
            $sql = "UPDATE {$table} SET value = " . db_escape($env) . " WHERE name = 'environment'";
        } else {
            $sql = "INSERT INTO {$table} (name, value) VALUES ('environment', " . db_escape($env) . ")";
        }
        db_query($sql);

        $msg = _("Configuration updated");
        $settings = Settings::fromFADatabase($tablePrefix);
    }

    if ($_POST['action'] == 'create_tables') {
        $stagingManager->createStagingTables();
        $msg = _("Staging tables created");
    }

    if ($_POST['action'] == 'drop_tables') {
        $stagingManager->dropStagingTables();
        $msg = _("Staging tables dropped");
    }
}

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');

$help_context = "Square Configuration";
page(_($help_context), false, false, "", "");
echo '<style>
.square-env-badge {
    display: inline-block; padding: 4px 12px; border-radius: 4px;
    font-weight: bold; font-size: 0.85em; color: #fff;
    background-color: ' . $badgeColor . ';
}
.square-env-section { border: 2px solid ' . $badgeColor . '; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
</style>';

start_form();

start_table(TABLESTYLE);

table_section_title(_("Square API Configuration") . ' <span class="square-env-badge">' . $badgeText . '</span>');

$envOptions = [
    'sandbox' => _('Sandbox'),
    'production' => _('Production'),
];
environments_list_row(_("Environment:"), 'environment', $env, $envOptions);

echo '</table>';
echo '<div class="square-env-section">';
start_table(TABLESTYLE2);

text_row(_("Sandbox Access Token:"), 'sandbox_access_token', $settings->getSandboxAccessToken() ?? '', 50, 100);
label_row('', _('(Used when Environment is set to Sandbox)'));
text_row(_("Production Access Token:"), 'production_access_token', $settings->getProductionAccessToken() ?? '', 50, 100);
label_row('', _('(Used when Environment is set to Production)'));
text_row(_("Legacy Access Token:"), 'access_token', $settings->getAccessToken() ?? '', 50, 100);
label_row('', _('(Fallback if env-specific token is empty)'));

end_table(1);
echo '</div>';

start_table(TABLESTYLE);

label_row(_("Last Import Date:"), $settings->getLastImportDate()?->format('Y-m-d H:i:s') ?? _('Never'));

$destCust = $settings->getDestinationCustomer();
if ($destCust !== null) {
    $sql = "SELECT name FROM {$tablePrefix}debtors_master WHERE debtor_no = " . (int)$destCust;
    $result = db_query($sql);
    $row = db_fetch_assoc($result);
    label_row(_("Destination Customer:"), ($row ? $row['name'] : (string)$destCust));
}

$defaultLoc = $settings->getDefaultLocation();
if ($defaultLoc !== null) {
    label_row(_("Default Location:"), $defaultLoc);
}

end_table(1);

if ($msg !== '') {
    display_notification($msg);
}

hidden('action', 'update');
submit_center('update', _("Update Configuration"));

end_form();

br();

start_form();

start_table(TABLESTYLE);

table_section_title(_("Staging Tables"));

$sql = "SHOW TABLES LIKE '{$tablePrefix}square_staging_transactions'";
$result = db_query($sql);
$tablesExist = db_num_rows($result) > 0;

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
