<?php
declare(strict_types=1);

$page_security = 'SA_ksf_FA_SquareMANAGE';
$path_to_root = "../../..";

include_once $path_to_root . "/includes/session.inc";
add_access_extensions();

include_once $path_to_root . "/includes/ui.inc";
include_once $path_to_root . "/includes/data_checks.inc";

$logFile = basename($_GET['file'] ?? '');
$logDir = company_path() . '/logs/';
$logPath = $logDir . $logFile;

$logContents = '';
$logError = '';

if (empty($logFile)) {
    $logError = _("No log file specified.");
} elseif (!file_exists($logPath) || !is_readable($logPath)) {
    $logError = _("Log file not found or not readable: ") . htmlspecialchars($logFile);
} else {
    $logContents = file_get_contents($logPath);
    if ($logContents === false) {
        $logError = _("Failed to read log file: ") . htmlspecialchars($logFile);
        $logContents = '';
    }
}

$help_context = "View Square Import Log";
page(_($help_context), false, false, "", "");

echo '<style>
.log-container { border: 1px solid #ccc; border-radius: 4px; padding: 15px; margin: 10px 0; background: #f8f9fa; font-family: monospace; white-space: pre-wrap; max-height: 600px; overflow-y: auto; }
.log-error { color: #dc3545; font-weight: bold; margin: 10px 0; }
</style>';

start_table(TABLESTYLE);
table_section_title(_("Square Import Log"));

if ($logError !== '') {
    echo '<tr><td class="label">' . _("Status:") . '</td><td>';
    echo '<div class="log-error">' . htmlspecialchars($logError) . '</div>';
    echo '</td></tr>';
} elseif ($logContents !== '') {
    echo '<tr><td class="label">' . _("Log File:") . '</td><td>';
    echo htmlspecialchars($logFile);
    echo '</td></tr>';
    echo '<tr><td class="label">' . _("Contents:") . '</td><td>';
    echo '<div class="log-container">' . htmlspecialchars($logContents) . '</div>';
    echo '</td></tr>';
} else {
    echo '<tr><td class="label">' . _("Log File:") . '</td><td>';
    echo _("Log file is empty.");
    echo '</td></tr>';
}

end_table(1);

echo '<div style="margin-top: 15px;">';
echo '<a href="' . $path_to_root . '/modules/ksf_FA_Square/pages/config.php" class="button">' . _("Back to Configuration") . '</a>';
echo ' <a href="' . $path_to_root . '/modules/ksf_FA_Square/pages/import.php" class="button">' . _("Back to Import") . '</a>';
echo '</div>';

end_page();
