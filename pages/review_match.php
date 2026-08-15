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
include_once $path_to_root . "/gl/includes/db/gl_db.inc";

include_once __DIR__ . "/../vendor/autoload.php";

use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\TransactionStagingDAO;
use ksfraser\FrontAccounting\Square\DAO\ItemStagingDAO;
use ksfraser\FrontAccounting\Square\DAO\SalesMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\PaymentMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareImportLogDAO;
use ksfraser\FrontAccounting\Square\Services\ImportService;
use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
try {
    $settings = Settings::fromFADatabase($tablePrefix);
    $transactionStagingDao = new TransactionStagingDAO($tablePrefix);
    $itemStagingDao = new ItemStagingDAO($tablePrefix);
    $salesMatchDao = new SalesMatchDAO($tablePrefix);
    $paymentMatchDao = new PaymentMatchDAO($tablePrefix);
    $squareImportLogDao = new SquareImportLogDAO($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    $transactionStagingDao = new TransactionStagingDAO($tablePrefix);
    $itemStagingDao = new ItemStagingDAO($tablePrefix);
    $salesMatchDao = new SalesMatchDAO($tablePrefix);
    $paymentMatchDao = new PaymentMatchDAO($tablePrefix);
    $squareImportLogDao = new SquareImportLogDAO($tablePrefix);
}

$help_context = "Review & Match Square/FA Transactions";
page(_($help_context), false, false, "", "");

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff; background-color: ' . $badgeColor . '; margin-left: 8px; }
.match-pending { background-color: #fff3cd; }
.match-matched { background-color: #d4edda; }
.match-mismatched { background-color: #f8d7da; }
.date-gap { background-color: #f8d7da; }
.import-gap { background-color: #fff3cd; }
</style>';
echo '<span class="square-env-badge">' . $badgeText . '</span>';

$msg = '';
$error = '';
$action = $_POST['action'] ?? '';
$viewMode = $_POST['view_mode'] ?? 'match';
$dateFrom = $_POST['date_from'] ?? '';
$dateTo = $_POST['date_to'] ?? '';
$customerFilter = (int)($_POST['customer_filter'] ?? 0);
$matchType = $_POST['match_type'] ?? 'auto';

$importService = null;
try {
    $squareClient = SquareClientFactory::create($settings);
    $importService = new ImportService($tablePrefix, $settings, $squareClient);
} catch (Exception $e) {
    $error = _("API Connection Error: ") . $e->getMessage();
}

start_form(true);

table_section_title(_("Filter Options"));
start_table(TABLESTYLE2, "width=60%");

$viewOptions = [
    'match' => _("Match Review"),
    'date_gaps' => _("Date Gaps"),
    'import_log' => _("Import Log"),
    'unmatched' => _("Unmatched Transactions"),
];
echo '<tr><td class="label">' . _("View Mode:") . '</td><td>';
echo array_selector('view_mode', $viewMode, $viewOptions, [
    'select_submit' => true,
    'async' => false,
]);
echo '</td></tr>';

date_row(_("From Date:"), 'date_from');
date_row(_("To Date:"), 'date_to');
customer_list_row(_("Customer Filter:"), 'customer_filter', $customerFilter, true, false);

if ($viewMode === 'match') {
    $matchOptions = [
        'auto' => _("Auto Match"),
        'manual' => _("Manual Match Only"),
        'all' => _("Show All"),
    ];
    echo '<tr><td class="label">' . _("Match Type:") . '</td><td>';
    echo array_selector('match_type', $matchType, $matchOptions, [
        'select_submit' => true,
        'async' => false,
    ]);
    echo '</td></tr>';
}

end_table(1);

submit_center('search_submit', _("Search"));

echo '<hr>';

if ($viewMode === 'date_gaps') {
    table_section_title(_("Import Date Gaps"));
    start_table(TABLESTYLE, "width=90%");

    $gaps = $squareImportLogDao->findDateGaps($env);
    $lastImportDate = $squareImportLogDao->getLastImportedDate($env);

    if ($lastImportDate) {
        $lastImport = new DateTimeImmutable($lastImportDate);
        $today = new DateTimeImmutable();
        $gapStart = $lastImport->modify('+1 day');
        
        if ($gapStart <= $today) {
            $gaps[] = [
                'from_date' => $gapStart->format('Y-m-d'),
                'to_date' => $today->format('Y-m-d'),
            ];
        }
    }

    if (empty($gaps)) {
        display_notification(_("No date gaps found. All dates have been imported."));
    } else {
        $th = array(
            _("Gap From"),
            _("Gap To"),
            _("Days Missing"),
            _("Action"),
        );
        table_header($th);

        foreach ($gaps as $gap) {
            $gapStart = new DateTimeImmutable($gap['from_date']);
            $gapEnd = new DateTimeImmutable($gap['to_date']);
            $interval = $gapStart->diff($gapEnd);
            $daysMissing = $interval->days + 1;

            echo '<tr class="date-gap">';
            echo '<td>' . htmlspecialchars($gap['from_date']) . '</td>';
            echo '<td>' . htmlspecialchars($gap['to_date']) . '</td>';
            echo '<td align="center"><strong>' . $daysMissing . ' ' . _("days") . '</strong></td>';
            echo '<td>';
            echo '<a href="?view_mode=match&date_from=' . htmlspecialchars($gap['from_date']) . '&date_to=' . htmlspecialchars($gap['to_date']) . '" class="button">' . _("Review for Import") . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    end_table(1);
}

if ($viewMode === 'import_log') {
    table_section_title(_("Import Log"));
    start_table(TABLESTYLE, "width=90%");

    $logs = $squareImportLogDao->getLogsByEnvironment($env);

    if (empty($logs)) {
        display_notification(_("No import log entries found."));
    } else {
        $th = array(
            _("Run Date"),
            _("From Date"),
            _("To Date"),
            _("Operation"),
            _("Imported"),
            _("Skipped"),
            _("Failed"),
            _("Status"),
            _("Location"),
        );
        table_header($th);

        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($log['run_date'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($log['from_date'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($log['to_date'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($log['operation_type'] ?? 'direct') . '</td>';
            echo '<td align="right">' . htmlspecialchars((string)($log['orders_imported'] ?? 0)) . '</td>';
            echo '<td align="right">' . htmlspecialchars((string)($log['orders_skipped'] ?? 0)) . '</td>';
            echo '<td align="right">' . htmlspecialchars((string)($log['orders_failed'] ?? 0)) . '</td>';
            echo '<td>' . htmlspecialchars($log['status'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($log['location_filter'] ?? 'All') . '</td>';
            echo '</tr>';
        }
    }

    end_table(1);
}

if ($viewMode === 'match') {
    if (!$dateFrom || !$dateTo) {
        echo '<div class="message warning">' . _("Please select a date range to review matches.") . '</div>';
    } else {
        $squareTransactions = $transactionStagingDao->getByStatus(
            TransactionStagingDAO::STATUS_STAGED,
            $env,
            $dateFrom,
            $dateTo
        );

        if (empty($squareTransactions)) {
            display_notification(_("No staged transactions found for this date range."));
        } else {
            table_section_title(_("Match Review - Square vs FA"));
            start_table(TABLESTYLE, "width=95%");

            $th = array(
                _("Square ID"),
                _("Square Date"),
                _("Square Customer"),
                _("Square Amount"),
                _("FA Status"),
                _("FA Link"),
                _("Match"),
                _("Actions"),
            );
            table_header($th);

            foreach ($squareTransactions as $squareTrans) {
                $squareId = $squareTrans['transaction_id'] ?? '';
                $squareDate = $squareTrans['Date'] ?? '';
                $squareCustomer = $squareTrans['customer_name'] ?? '';
                $squareAmount = $squareTrans['total_collected'] ?? 0;

                $faMatch = null;
                $faStatus = 'unmatched';
                $faLink = '';

                if ($squareTrans['fa_invoice_no']) {
                    $faStatus = 'matched';
                    $faMatch = $salesMatchDao->getByInvoiceNo((int)$squareTrans['fa_invoice_no'])[0] ?? null;
                }

                if ($faMatch) {
                    $faStatus = 'matched';
                    $faLink = $faMatch['sales_invoice_no'] ?? '';
                }

                $rowClass = $faStatus === 'matched' ? 'match-matched' : 'match-pending';

                echo '<tr class="' . $rowClass . '">';
                echo '<td><strong>' . htmlspecialchars($squareId) . '</strong></td>';
                echo '<td>' . htmlspecialchars($squareDate) . '</td>';
                echo '<td>' . htmlspecialchars($squareCustomer) . '</td>';
                echo '<td align="right">' . number_format((float)$squareAmount, 2) . '</td>';
                echo '<td>' . htmlspecialchars(ucfirst($faStatus)) . '</td>';
                echo '<td>';
                if ($faLink) {
                    echo '<a href="' . $path_to_root . '/sales/invoice_view.php?trans_no=' . htmlspecialchars($faLink) . '" target="_blank">' . _('FA #' . $faLink) . '</a>';
                }
                echo '</td>';
                echo '<td>';
                
                if ($faStatus === 'matched') {
                    echo '<span class="label success">✓ Matched</span>';
                } else {
                    echo '<select name="match_square_' . htmlspecialchars($squareId) . '" class="match-select">';
                    echo '<option value="">-- Select FA Match --</option>';
                    
                    $sql = "SELECT t.*, d.name as customer_name 
                            FROM {$tablePrefix}debtor_trans t
                            LEFT JOIN {$tablePrefix}debtors_master d ON t.debtor_no = d.debtor_no
                            WHERE t.type = 10 
                            AND t.tran_date >= '" . db_escape($dateFrom) . "' 
                            AND t.tran_date <= '" . db_escape($dateTo) . "'";
                    if ($customerFilter > 0) {
                        $sql .= " AND t.debtor_no = " . (int)$customerFilter;
                    }
                    $sql .= " ORDER BY t.tran_date DESC";

                    $result = db_query($sql);
                    while ($row = db_fetch_assoc($result)) {
                        $selected = ($faLink == $row['trans_no']) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($row['trans_no']) . '" ' . $selected . '>';
                        echo 'FA #' . $row['trans_no'] . ' - ' . htmlspecialchars($row['reference'] ?? '') . ' - ' . number_format((float)($row['ov_amount'] ?? 0), 2);
                        echo '</option>';
                    }
                    
                    echo '</select>';
                }
                echo '</td>';
                echo '<td>';
                echo '<button type="button" onclick="editSquareTransaction(\'' . htmlspecialchars($squareId) . '\')" class="button">Edit Square</button>';
                echo ' | ';
                echo '<button type="button" onclick="viewSquareDetails(\'' . htmlspecialchars($squareId) . '\')" class="button">Details</button>';
                echo '</td>';
                echo '</tr>';
            }

            end_table(1);

            echo '<div style="text-align: center; margin: 20px 0;">';
            submit('save_matches', _("Save Matches"));
            echo ' &nbsp; ';
            submit('auto_match', _("Auto Match"));
            echo '</div>';
        }
    }
}

if ($viewMode === 'unmatched') {
    $unmatchedSquare = $transactionStagingDao->getByStatus(
        TransactionStagingDAO::STATUS_STAGED,
        $env,
        $dateFrom,
        $dateTo
    );

    $unmatchedFA = [];
    if ($dateFrom && $dateTo) {
        $sql = "SELECT t.*, d.name as customer_name 
                FROM {$tablePrefix}debtor_trans t
                LEFT JOIN {$tablePrefix}debtors_master d ON t.debtor_no = d.debtor_no
                WHERE t.type = 10 
                AND t.tran_date >= '" . db_escape($dateFrom) . "' 
                AND t.tran_date <= '" . db_escape($dateTo) . "'";
        if ($customerFilter > 0) {
            $sql .= " AND t.debtor_no = " . (int)$customerFilter;
        }
        $sql .= " AND NOT EXISTS (
            SELECT 1 FROM {$tablePrefix}ksf_import_square_sales 
            WHERE sales_invoice_no = t.trans_no
        ) ORDER BY t.tran_date DESC";

        $result = db_query($sql);
        while ($row = db_fetch_assoc($result)) {
            $unmatchedFA[] = $row;
        }
    }

    table_section_title(_("Unmatched Transactions"));
    start_table(TABLESTYLE, "width=95%");

    echo '<tr>';
    echo '<td colspan="4" style="background-color: #e7f3ff; padding: 10px;">';
    echo '<strong>' . count($unmatchedSquare) . '</strong> Square transactions to import';
    echo '</td>';
    echo '<td colspan="4" style="background-color: #e8f5e9; padding: 10px;">';
    echo '<strong>' . count($unmatchedFA) . '</strong> FA invoices not linked to Square';
    echo '</td>';
    echo '</tr>';

    echo '<tr><td colspan="8" style="height: 20px;"></td></tr>';

    echo '<tr style="background-color: #f8f9fa; font-weight: bold;">';
    echo '<td colspan="4">Square Transactions</td>';
    echo '<td colspan="4">FA Invoices</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td colspan="4" style="background-color: #fff3cd;">';
    foreach ($unmatchedSquare as $square) {
        echo '<div style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<strong>Square: ' . htmlspecialchars($square['transaction_id'] ?? '') . '</strong><br>';
        echo 'Date: ' . htmlspecialchars($square['Date'] ?? '') . ' | ';
        echo 'Customer: ' . htmlspecialchars($square['customer_name'] ?? '') . ' | ';
        echo 'Amount: ' . number_format((float)($square['total_collected'] ?? 0), 2);
        echo '</div>';
    }
    echo '</td>';
    echo '<td colspan="4" style="background-color: #d4edda;">';
    foreach ($unmatchedFA as $fa) {
        echo '<div style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<strong>FA: #' . htmlspecialchars($fa['trans_no'] ?? '') . '</strong><br>';
        echo 'Date: ' . htmlspecialchars($fa['tran_date'] ?? '') . ' | ';
        echo 'Customer: ' . htmlspecialchars($fa['customer_name'] ?? '') . ' | ';
        echo 'Amount: ' . number_format((float)($fa['ov_amount'] ?? 0), 2);
        echo '</div>';
    }
    echo '</td>';
    echo '</tr>';

    end_table(1);
}

hidden('action', 'process_matches');
end_form();

echo '<script>
function editSquareTransaction(squareId) {
    window.location.href = "?edit=1&square_id=" + squareId;
}

function viewSquareDetails(squareId) {
    window.open("?view_square=1&square_id=" + squareId, "_blank");
}
</script>';

end_page();
