<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = __DIR__ . "/../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
use Ksfraser\Frontaccounting\SquareUp\Staging\StagingTableManager;

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
$env = $settings->getEnvironment();

if (isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'switch_env':
                $newEnv = $_POST['environment'] ?? 'sandbox';
                $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'environment'";
                $result = db_query($sql);
                if ($result !== false) {
                    $row = db_fetch_assoc($result);
                    $e = db_escape($newEnv);
                    if ((int)$row['cnt'] > 0) {
                        db_query("UPDATE {$table} SET value = {$e} WHERE name = 'environment'");
                    } else {
                        db_query("INSERT INTO {$table} (name, value) VALUES ('environment', {$e})");
                    }
                }
                $settings = Settings::fromFADatabase($tablePrefix);
                $env = $settings->getEnvironment();
                $msg = _("Switched to ") . ($env === 'production' ? _('Production') : _('Sandbox'));
                break;

            case 'update':
                $tokenFields = ['access_token', 'sandbox_access_token', 'production_access_token'];
                foreach ($tokenFields as $field) {
                    $value = $_POST[$field] ?? '';
                    if ($value !== '') {
                        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = " . db_escape($field);
                        $result = db_query($sql);
                        if ($result !== false) {
                            $row = db_fetch_assoc($result);
                            if ((int)$row['cnt'] > 0) {
                                $sql = "UPDATE {$table} SET value = " . db_escape($value) . " WHERE name = " . db_escape($field);
                            } else {
                                $sql = "INSERT INTO {$table} (name, value) VALUES (" . db_escape($field) . ", " . db_escape($value) . ")";
                            }
                            db_query($sql);
                        }
                    }
                }

                $newEnv = $_POST['environment'] ?? 'sandbox';
                $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = 'environment'";
                $result = db_query($sql);
                if ($result !== false) {
                    $row = db_fetch_assoc($result);
                    $e = db_escape($newEnv);
                    if ((int)$row['cnt'] > 0) {
                        db_query("UPDATE {$table} SET value = {$e} WHERE name = 'environment'");
                    } else {
                        db_query("INSERT INTO {$table} (name, value) VALUES ('environment', {$e})");
                    }
                }

                $msg = _("Configuration updated");
                $settings = Settings::fromFADatabase($tablePrefix);
                $env = $settings->getEnvironment();
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

start_form('', '', 'env_form');

start_table(TABLESTYLE);

table_section_title(_("Square API Configuration") . ' <span class="square-env-badge">' . $badgeText . '</span>');

$envOptions = ['sandbox' => _('Sandbox'), 'production' => _('Production')];
echo '<tr><td class="label">' . _("Environment:") . '</td><td>';
echo array_selector('environment', $env, $envOptions, ['onchange' => 'this.form.submit()']);
echo '&nbsp;';
submit('go_env', _("Go"));
echo '</td></tr>';

end_table(1);

hidden('action', 'switch_env');
end_form();

echo '<div class="square-env-section">';

start_form();
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
    $sql = "SELECT name FROM {$tablePrefix}debtors_master WHERE debtor_no = " . (int)$destCust;
    $result = db_query($sql);
    $customerName = (string)$destCust;
    if ($result !== false) {
        $row = db_fetch_assoc($result);
        if ($row) {
            $customerName = $row['name'];
        }
    }
    label_row(_("Destination Customer:"), $customerName);
}

$defaultLoc = $settings->getDefaultLocation();
if ($defaultLoc !== null) {
    label_row(_("Default Location:"), $defaultLoc);
}

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
