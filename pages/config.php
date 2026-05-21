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

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'update') {
        $token = $_POST['access_token'] ?? '';
        if ($token !== '') {
            $table = $tablePrefix . 'square';
            $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'access_token'";
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if ($row['cnt'] > 0) {
                $sql = "UPDATE {$table} SET value = " . db_escape($token) . " WHERE name = 'access_token'";
            } else {
                $sql = "INSERT INTO {$table} (name, value) VALUES ('access_token', " . db_escape($token) . ")";
            }
            db_query($sql);
        }

        $env = $_POST['environment'] ?? 'sandbox';
        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'environment'";
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        if ($row['cnt'] > 0) {
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

$help_context = "Square Configuration";
page(_($help_context), false, false, "", "");

start_form();

start_table(TABLESTYLE);

table_section_title(_("Square API Configuration"));

text_row(_("Access Token:"), 'access_token', $settings->getAccessToken() ?? '', 50, 100);

$env_options = array(
    'sandbox' => _('Sandbox'),
    'production' => _('Production'),
);
$selected_env = $settings->getEnvironment();
if (!isset($env_options[$selected_env])) {
    $selected_env = 'sandbox';
}
environments_list_row(_("Environment:"), 'environment', $selected_env, $env_options);

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
