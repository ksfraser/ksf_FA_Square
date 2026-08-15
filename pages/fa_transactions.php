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

include_once __DIR__ . "/../vendor/autoload.php";

use ksfraser\FrontAccounting\Square\Config\Settings;
use ksfraser\FrontAccounting\Square\DAO\SalesMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\PaymentMatchDAO;
use ksfraser\FrontAccounting\Square\DAO\SquareImportLogDAO;

$tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
try {
    $settings = Settings::fromFADatabase($tablePrefix);
    $salesMatchDao = new SalesMatchDAO($tablePrefix);
    $paymentMatchDao = new PaymentMatchDAO($tablePrefix);
    $squareImportLogDao = new SquareImportLogDAO($tablePrefix);
} catch (\Exception $e) {
    $settings = new Settings();
    $salesMatchDao = new SalesMatchDAO($tablePrefix);
    $paymentMatchDao = new PaymentMatchDAO($tablePrefix);
    $squareImportLogDao = new SquareImportLogDAO($tablePrefix);
}

$help_context = "FA Transaction Management";
page(_($help_context), false, false, "", "");

$env = $settings->getEnvironment();
$badgeColor = $env === 'production' ? '#dc3545' : '#ffc107';
$badgeText = $env === 'production' ? _('LIVE') : _('SANDBOX');
echo '<style>
.square-env-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 0.85em; color: #fff; background-color: ' . $badgeColor . '; margin-left: 8px; }
.transaction-invoice { background-color: #e7f3ff; }
.transaction-payment { background-color: #e8f5e9; }
.transaction-void { background-color: #ffebee; }
.date-gap { background-color: #fff3cd; }
.import-gap { background-color: #f8d7da; }
</style>';
echo '<span class="square-env-badge">' . $badgeText . '</span>';

$msg = '';
$error = '';
$action = $_POST['action'] ?? '';
$viewType = $_POST['view_type'] ?? 'recent';
$fromDate = $_POST['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate = $_POST['to_date'] ?? date('Y-m-d');
$debtorNo = (int)($_POST['debtor_no'] ?? 0);

start_form(true);

table_section_title(_("Filter Options"));
start_table(TABLESTYLE2, "width=60%");

$typeOptions = [
    'recent' => _("Recent Transactions"),
    'invoices' => _("Invoices Only"),
    'payments' => _("Payments Only"),
    'all' => _("All Types"),
];
echo '<tr><td class="label">' . _("View Type:") . '</td><td>';
echo array_selector('view_type', $viewType, $typeOptions, [
    'select_submit' => true,
    'async' => false,
]);
echo '</td></tr>';

date_row(_("From Date:"), 'from_date');
date_row(_("To Date:"), 'date_to');
customer_list_row(_("Customer (optional):"), 'debtor_no', $debtorNo, true);

end_table(1);

submit_center('search_submit', _("Search"));

echo '<hr>';

if ($env === 'production') {
    $gaps = $squareImportLogDao->findDateGaps($env);
    if (!empty($gaps)) {
        table_section_title(_("⚠️ Import Date Gaps Detected"));
        start_table(TABLESTYLE, "width=90%");

        echo '<tr class="date-gap">';
        echo '<td style="padding: 15px; border: 1px solid #ffc107; border-radius: 4px;">';
        echo '<strong>' . count($gaps) . ' ' . _("date gap(s) found") . '</strong><br>';
        echo 'Some dates may be missing from import history. Please review and import missing data.<br><br>';
        
        foreach ($gaps as $gap) {
            $gapStart = new DateTimeImmutable($gap['from_date']);
            $gapEnd = new DateTimeImmutable($gap['to_date']);
            $interval = $gapStart->diff($gapEnd);
            $daysMissing = $interval->days + 1;
            
            echo '<div style="margin-bottom: 10px;">';
            echo '<strong>Gap:</strong> ' . htmlspecialchars($gap['from_date']) . ' to ' . htmlspecialchars($gap['to_date']) . ' (' . $daysMissing . ' days)<br>';
            echo '<a href="../review_match.php?view_mode=date_gaps" class="button" style="margin-top: 5px;">Review Gaps</a>';
            echo '</div>';
        }
        
        echo '</td>';
        echo '</tr>';
        end_table(1);
        echo '<hr>';
    }
}

table_section_title(_("Quick Actions"));
start_table(TABLESTYLE2, "width=60%");

echo '<tr><td>';
echo '<a href="../review_match.php" class="button" style="margin-right: 10px;">' . _("Review & Match Transactions") . '</a>';
echo '<a href="../import.php" class="button">' . _("Import from Square") . '</a>';
echo '</td></tr>';

echo '<tr><td>';
echo '<a href="../config.php" class="button" style="margin-right: 10px;">' . _("Configuration") . '</a>';
echo '<a href="../export.php" class="button">' . ("Export to Square") . '</a>';
echo '</td></tr>';

end_table(1);
echo '<hr>';

function getFaTransactions(string $tablePrefix, string $viewType, string $fromDate, string $toDate, int $debtorNo = 0): array
{
    $transTable = $tablePrefix . 'debtor_trans';
    $debtorsTable = $tablePrefix . 'debtors_master';

    $where = "t.tran_date >= '" . db_escape($fromDate) . "' AND t.tran_date <= '" . db_escape($toDate) . "'";

    if ($viewType === 'invoices') {
        $where .= " AND t.type = 10";
    } elseif ($viewType === 'payments') {
        $where .= " AND t.type = 12";
    }

    if ($debtorNo > 0) {
        $where .= " AND t.debtor_no = " . (int)$debtorNo;
    }

    $sql = "SELECT t.*, d.name AS customer_name, d.debtor_ref
            FROM {$transTable} t
            LEFT JOIN {$debtorsTable} d ON t.debtor_no = d.debtor_no
            WHERE {$where}
            ORDER BY t.tran_date DESC, t.trans_no DESC
            LIMIT 100";

    $result = db_query($sql);
    $transactions = [];

    if ($result !== false) {
        while ($row = db_fetch_assoc($result)) {
            if ($row !== false) {
                $transactions[] = $row;
            }
        }
    }

    return $transactions;
}

function getTypeLabel(int $type): string
{
    switch ($type) {
        case 10:
            return _("Invoice");
        case 11:
            return _("Credit Note");
        case 12:
            return _("Payment");
        default:
            return (string)$type;
    }
}

function getTypeClass(int $type): string
{
    switch ($type) {
        case 10:
            return 'transaction-invoice';
        case 12:
            return 'transaction-payment';
        default:
            return '';
    }
}

function getEditLink(int $type, int $transNo): string
{
    global $path_to_root;

    switch ($type) {
        case 10:
            return $path_to_root . "/sales/customer_invoice.php?ModifyInvoice=Yes&trans_no=" . $transNo;
        case 12:
            return $path_to_root . "/sales/customer_payments.php?trans_no=" . $transNo . "&type=12";
        default:
            return '';
    }
}

function getViewLink(int $type, int $transNo): string
{
    global $path_to_root;

    switch ($type) {
        case 10:
            return $path_to_root . "/sales/invoice_view.php?trans_no=" . $transNo;
        case 12:
            return $path_to_root . "/sales/allocations/customer_allocation_main.php?type=12&trans_no=" . $transNo;
        default:
            return '';
    }
}

$transactions = getFaTransactions($tablePrefix, $viewType, $fromDate, $toDate, $debtorNo);

if (!empty($transactions)) {
    table_section_title(_("FA Transactions") . " (" . count($transactions) . " " . _("found") . ")");
    start_table(TABLESTYLE, "width=95%");

    $th = array(
        _("Type"),
        _("Trans #"),
        _("Date"),
        _("Customer"),
        _("Reference"),
        _("Amount"),
        _("Allocated"),
        _("Square Link"),
        _("Actions"),
    );
    table_header($th);

    foreach ($transactions as $trans) {
        $type = (int)$trans['type'];
        $transNo = (int)$trans['trans_no'];
        $rowClass = getTypeClass($type);

        $squareMatch = null;
        if ($type === 10) {
            $squareMatch = $salesMatchDao->getByInvoiceNo($transNo);
        } elseif ($type === 12) {
            $squareMatch = $paymentMatchDao->getByFaTransaction($type, $transNo);
        }

        echo '<tr class="' . $rowClass . '">';
        echo '<td>' . getTypeLabel($type) . '</td>';
        echo '<td>' . htmlspecialchars((string)$transNo) . '</td>';
        echo '<td>' . htmlspecialchars($trans['tran_date'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['customer_name'] ?? $trans['debtor_ref'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($trans['reference'] ?? '') . '</td>';
        echo '<td align="right">' . number_format((float)($trans['ov_amount'] ?? 0), 2) . '</td>';
        echo '<td align="right">' . number_format((float)($trans['alloc'] ?? 0), 2) . '</td>';

        if (!empty($squareMatch)) {
            $matchInfo = $squareMatch[0] ?? $squareMatch;
            $squareId = $matchInfo['square_transaction_id'] ?? $matchInfo['square_payment_id'] ?? '';
            echo '<td>';
            echo '<span style="color: #28a745; font-weight: bold;">' . _("Linked") . '</span><br>';
            echo '<small>' . htmlspecialchars(substr($squareId, 0, 12)) . '...</small>';
            echo '</td>';
        } else {
            echo '<td><span style="color: #6c757d;">' . _("Not linked") . '</span></td>';
        }

        echo '<td>';
        $viewLink = getViewLink($type, $transNo);
        $editLink = getEditLink($type, $transNo);

        if ($viewLink !== '') {
            echo '<a href="' . htmlspecialchars($viewLink) . '" target="_blank">' . _("View") . '</a>';
        }
        if ($editLink !== '') {
            echo ' | <a href="' . htmlspecialchars($editLink) . '" target="_blank">' . _("Edit") . '</a>';
        }

        echo '</td>';
        echo '</tr>';
    }

    end_table(1);
} else {
    display_notification(_("No transactions found for the selected criteria."));
}

echo '<hr>';

table_section_title(_("Quick Links to FA Native Screens"));
start_table(TABLESTYLE2, "width=60%");

echo '<tr><td>';
echo '<a href="' . $path_to_root . '/sales/inquiry/customer_inquiry.php" target="_blank">' . _("Customer Transaction Inquiry") . '</a>';
echo ' - ' . _("View all customer invoices, payments, and credits");
echo '</td></tr>';

echo '<tr><td>';
echo '<a href="' . $path_to_root . '/sales/customer_invoice.php" target="_blank">' . _("Create Invoice") . '</a>';
echo ' - ' . _("Create a new sales invoice");
echo '</td></tr>';

echo '<tr><td>';
echo '<a href="' . $path_to_root . '/sales/customer_payments.php" target="_blank">' . _("Record Payment") . '</a>';
echo ' - ' . _("Record a customer payment");
echo '</td></tr>';

echo '<tr><td>';
echo '<a href="' . $path_to_root . '/sales/allocations/customer_allocation_main.php" target="_blank">' . _("Allocate Payments") . '</a>';
echo ' - ' . _("Allocate payments to invoices");
echo '</td></tr>';

end_table(1);

end_form();
end_page();
