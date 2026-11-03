<?php
/**
 * KSF FrontAccounting Module Hooks -- Square Integration
 *
 * Hooks for Square POS integration: payment destinations, invoice creation,
 * CRM note creation, item sync, and webhook handling.
 *
 * @package ksf_FA_Square
 * @version 2.4.4
 */
define('SS_ksf_FA_Square', 108 << 8);

/**
 * Constants for inter-module communication
 */
define('KSF_SQUARE_MODULE_NAME', 'ksf_FA_Square');
define('KSF_SQUARE_CAPABILITIES', 'export,import,payments,config');

// ComposerDependencies bootstrap -- per-module copy of template
require_once __DIR__ . '/ComposerDependencies.php';

\ksfraser\FrontAccounting\Square\Utils\ComposerDependencies::ensure(__DIR__);

$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

use ksfraser\FrontAccounting\Square\Services\SquareInvoiceService;
use ksfraser\FrontAccounting\Square\Services\SquareNoteService;
use ksfraser\FrontAccounting\Square\DAO\SquareInvoiceMapDAO;

class hooks_ksf_FA_Square extends hooks {

    const RESPONDERS = array('getModuleConstants', 'getModuleCapabilities', 'hasCapability', 'respondToCapabilityRequest');

    var $module_name = 'ksf_FA_Square';
    var $version = '2.4.4';

    /**
     * Temporary storage for cart data between prewrite and postwrite
     */
    private static $pendingSquareInvoice = [];

    function __construct() {
        $this->module_name = 'ksf_FA_Square';
    }

    /**
     * Write a debug log entry to company log file.
     */
    private function sqLog($level, $message, array $context = []) {
        if (function_exists("ksf_log")) {
            ksf_log("square", $level, $message, $context);
        }
        // Also write directly to square log file as fallback
        $logDir = company_path() . "/logs";
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $file = $logDir . "/square_" . date("Y-m-d") . ".log";
        $entry = [
            "ts" => date("Y-m-d H:i:s.u"),
            "level" => strtoupper($level),
            "msg" => $message,
            "ctx" => $context,
            "pid" => getmypid(),
        ];
        @file_put_contents($logDir . "/square_" . date("Y-m-d") . ".log", json_encode($entry) . "
", FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // INTER-MODULE COMMUNICATION
    // =========================================================================

    public function getModuleConstants(&$data, $opts = null) {
        $data['constants'] = [
            'KSF_SQUARE_MODULE_NAME' => KSF_SQUARE_MODULE_NAME,
            'KSF_SQUARE_CAPABILITIES' => KSF_SQUARE_CAPABILITIES,
        ];
        return $data;
    }

    public function getModuleCapabilities(&$data, $opts = null) {
        $data['capabilities'] = self::RESPONDERS;
        return $data;
    }

    public function hasCapability(&$data, $opts = null) {
        $cap = $opts['capability'] ?? $data['capability'] ?? null;
        $data['has_capability'] = in_array($cap, self::RESPONDERS, true);
        return $data['has_capability'];
    }

    public function respondToCapabilityRequest(&$data, $opts = null) {
        $req = $opts['request'] ?? $data['request'] ?? 'capabilities';
        if (strpos($req, 'has:') === 0) {
            return $this->hasCapability($data, ['capability' => substr($req, 4)]);
        }
        $data['request'] = $req;
        $data['module'] = $this->module_name;
        switch ($req) {
            case 'capabilities': return $this->getModuleCapabilities($data, $opts);
            case 'constants':    return $this->getModuleConstants($data, $opts);
            default:
                $data['error'] = 'Unknown request: ' . $req;
                return null;
        }
    }

    // =========================================================================
    // FA CORE HOOKS
    // =========================================================================

    function install_tabs($app) { }
    function install_options($app) { }

    function install_access() {
        $security_sections[SS_ksf_FA_Square] = _("Square POS");
        $security_areas['SA_SQUARE_VIEW']     = array(SS_ksf_FA_Square | 1, _("View Square integration"));
        $security_areas['SA_SQUARE_MANAGE']   = array(SS_ksf_FA_Square | 2, _("Manage Square integration"));
        $security_areas['SA_SQUARE_CONFIG']   = array(SS_ksf_FA_Square | 4, _("Configure Square settings"));
        return array($security_areas, $security_sections);
    }

    function activate_extension($company, $check_only = true) {
        if ($check_only) return true;
        $this->ensure_composer_dependencies();
        $this->update_databases($company, [
            '0_ksf_Square_map.sql'       => array('ksf_Square_map'),
            '0_ksf_Square_customer.sql'  => array('ksf_Square_customer'),
            '0_ksf_Square_token.sql'     => array('ksf_Square_token'),
            '0_ksf_Square_webhook.sql'   => array('ksf_Square_webhook'),
        ], false);
        return true;
    }

    private function ensure_composer_dependencies() {
        \ksfraser\FrontAccounting\Square\Utils\ComposerDependencies::ensure(__DIR__);
    }

    // =========================================================================
    // PREWRITE HOOK -- detect Square payment destination, suppress auto-payment
    // =========================================================================

    function db_prewrite(&$cart, $trans_type) {
        if ($trans_type !== ST_SALESINVOICE) return null;
        if (!isset($cart->payment_terms['terms_indicator'])) return null;

        $destination = $this->resolvePaymentDestination((int)$cart->payment_terms['terms_indicator']);
        if ($destination === null || (strpos($destination, 'square_invoice') !== 0 && strpos($destination, 'square invoice') !== 0)) {
            return null;
        }

        $cart->payment_terms['cash_sale'] = 0; // Suppress auto-payment

        self::$pendingSquareInvoice = [
            'customer_id'      => (int)$cart->customer_id,
            'branch_code'      => isset($cart->Branch) ? (string)$cart->Branch : (isset($cart->branch_code) ? (string)$cart->branch_code : ''),
            'line_items'       => $this->extractLineItems($cart),
            'destination'      => $destination,
            'terms_indicator'  => (int)$cart->payment_terms['terms_indicator'],
        ];

        return true;
    }

    // =========================================================================
    // POSTWRITE HOOK -- create Square Invoice after FA invoice committed
    // =========================================================================

    function db_postwrite(&$cart, $trans_type) {
        $this->sqLog("info", "db_postwrite called", ["trans_type" => $trans_type, "pending_empty" => empty(self::$pendingSquareInvoice)]);
        if ($trans_type !== ST_SALESINVOICE || empty(self::$pendingSquareInvoice)) {
            $this->sqLog("warning", "db_postwrite early return", ["trans_type" => $trans_type, "pending_empty" => empty(self::$pendingSquareInvoice)]);
            return null;
        }

        $data = self::$pendingSquareInvoice;
        self::$pendingSquareInvoice = [];

        // Resolve FA invoice number
        $faInvoiceNo = $this->resolveInvoiceNumber($cart, $data);
        $this->sqLog("info", "Resolved invoice number", ["faInvoiceNo" => $faInvoiceNo]);
        if ($faInvoiceNo <= 0) {
            $this->sqLog("warning", "Could not resolve invoice number", ["faInvoiceNo" => $faInvoiceNo]);
            return null;
        }

        try {
            $service = $this->buildSquareInvoiceService();
            $this->sqLog("info", "Built SquareInvoiceService", ["service" => $service ? "ok" : "null"]);
            if ($service === null) {
                $this->sqLog("warning", "SquareInvoiceService is null");
                return null;
            }

            $dueDate = date('Y-m-d', strtotime('+30 days'));
            $deliveryMethod = $data['destination'] === 'square_invoice_email' ? 'EMAIL' : 'SHARE_MANUALLY';
            $autoPaymentSource = $data['destination'] === 'square_invoice_card' ? 'CARD_ON_FILE' : null;

            $this->sqLog("info", "Calling createInvoiceFromFA", ["faInvoiceNo" => $faInvoiceNo]);
            $result = $service->createInvoiceFromFA(
                $faInvoiceNo,
                $data['customer_id'],
                $data['line_items'],
                date('Y-m-d', strtotime('+30 days')),
                $deliveryMethod,
                $autoPaymentSource
            );

            $squareInvoiceId = $result['square_invoice_id'] ?? '';
            $this->sqLog("info", "Square API response", ["result" => $result]);
            $squareInvoiceShortId = SquareNoteService::extractShortSquareId($squareInvoiceId);

            $this->sqLog("info", "Creating CRM note via SquareNoteService");
            // Create CRM note via SquareNoteService
            $noteService = new SquareNoteService();
            $this->sqLog("info", "Calling SquareNoteService::createSquareInvoiceNote");
            $noteService->createSquareInvoiceNote(
                $faInvoiceNo,
                $squareInvoiceShortId,
                $result['public_url'] ?? '',
                ['trans_no' => $faInvoiceNo, 'customer_id' => (string)$data['customer_id']],
                $result,
                $data,
                ['loginname' => $_SESSION['wa_current_user']->loginname ?? '']
            );
            $this->sqLog("info", "SquareNoteService::createSquareInvoiceNote completed");

            // Log activity (no display_notification to avoid redirect interference)
            $url = $result['public_url'] ?? '';
            if (!empty($result['created'])) {
                $msg = _("Square Invoice created") . ': #' . $faInvoiceNo;
                if ($url) $msg .= ' -- <a href="' . htmlspecialchars($url) . '" target="_blank">' . _("View Square Payment Link") . '</a>';
                $this->sqLog('info', $msg);
            } else {
                $this->sqLog('info', 'Square Invoice already exists: #', ['faInvoiceNo' => $faInvoiceNo]);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->sqLog('error', 'Square Invoice creation/update failed', ['faInvoiceNo' => $faInvoiceNo, 'error' => $e->getMessage()]);
            if (function_exists('display_error')) display_error(_("Square Invoice creation/update failed") . ': ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Resolve FA invoice number from cart/session/db.
     */
private function resolveInvoiceNumber($cart, $data): int {
        $faInvoiceNo = 0;

        // Priority 1: cart->trans_no set by write_sales_invoice before postwrite hooks fire
        if (isset($cart->trans_no) && $cart->trans_no > 0) {
            $faInvoiceNo = (int)$cart->trans_no;
        }
        // Priority 2: session items trans_no (FA sets this after write_sales_invoice)
        elseif (isset($_SESSION['items']->trans_no) && $_SESSION['items']->trans_no > 0) {
            $faInvoiceNo = (int)$_SESSION['items']->trans_no;
        }
        // Priority 3: db_insert_id() - only as fallback (other hooks may have run INSERTs)
        elseif (($lastInsertId = (int)db_insert_id()) > 0) {
            $faInvoiceNo = $lastInsertId;
        }

        // Last resort: query debtor_trans for most recent invoice for this customer
        if ($faInvoiceNo <= 0 && isset($data['customer_id']) && $data['customer_id'] > 0) {
            $today = date('Y-m-d');
            $sql = "SELECT trans_no FROM " . TB_PREF . "debtor_trans
                    WHERE debtor_no = " . db_escape($data['customer_id']) . "
                      AND type = " . ST_SALESINVOICE . "
                      AND tran_date = " . db_escape(date('Y-m-d')) . "
                    ORDER BY trans_no DESC LIMIT 1";
            $result = db_query($sql);
            if ($result && ($row = db_fetch($result))) {
                $faInvoiceNo = (int)$row['trans_no'];
                $this->sqLog('info', 'Resolved invoice number via customer+date query', ['faInvoiceNo' => $faInvoiceNo]);
            }
        }

        if ($faInvoiceNo <= 0) {
            $this->sqLog('warning', 'Could not resolve invoice number', ['cart_trans_no' => $cart->trans_no ?? 'unset', 'session_trans_no' => $_SESSION['items']->trans_no ?? 'unset', 'db_insert_id' => (int)db_insert_id()]);
        }

        $lastInsertId = (int)db_insert_id();
        if ($lastInsertId > 0 && $lastInsertId !== $faInvoiceNo) {
            $this->sqLog('warning', 'db_insert_id mismatch', ['db_insert_id' => $lastInsertId, 'resolved' => $faInvoiceNo]);
        }

        return $faInvoiceNo;
    }

    /**
     * Resolve payment destination from terms indicator.
     */
    private function resolvePaymentDestination(int $termsIndicator): ?string {
        if (!function_exists('db_query')) return null;

        $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
        
        // First check the ksf_payment_destinations table
        $sql = "SELECT destination FROM " . $tablePrefix . "ksf_payment_destinations
                WHERE terms_indicator = " . (int)$termsIndicator . " LIMIT 1";
        $result = db_query($sql);
        if ($result && ($row = db_fetch($result))) {
            $dest = trim((string)$row['destination']);
            if ($dest !== '') return $dest;
        }

        // Fallback: check payment term name for square_invoice pattern
        $sql = "SELECT terms FROM " . $tablePrefix . "payment_terms
                WHERE terms_indicator = " . (int)$termsIndicator . " LIMIT 1";
        $result = db_query($sql);
        if ($result && ($row = db_fetch($result))) {
            $termName = strtolower(trim((string)$row['terms']));
            if (strpos($termName, 'square_invoice') === 0 || strpos($termName, 'square invoice') === 0) {
                return 'square_invoice';
            }
        }
        return null;
    }

    /**
     * Extract line items from cart.
     */
    private function extractLineItems($cart): array {
        $items = [];
        if (isset($cart->line_items) && is_array($cart->line_items)) {
            foreach ($cart->line_items as $line) {
                if (!empty($line['stock_id'])) {
                    $items[] = [
                        'stock_id'       => $line['stock_id'],
                        'item_description' => $line['item_description'] ?? '',
                        'quantity'       => $line['quantity'] ?? 1,
                        'price'          => $line['price'] ?? 0,
                        'tax_type_id'    => $line['tax_type_id'] ?? 0,
                    ];
                } elseif (!empty($line['item_description'])) {
                    $items[] = [
                        'stock_id'       => '',
                        'item_description' => $line['item_description'],
                        'quantity'       => $line['quantity'] ?? 1,
                        'price'          => $line['price'] ?? 0,
                        'tax_type_id'    => $line['tax_type_id'] ?? 0,
                    ];
                }
            }
        }
        return $items;
    }

    /**
     * Build SquareInvoiceService instance.
     */
    private function buildSquareInvoiceService(): ?SquareInvoiceService {
        if (!function_exists('db_query')) return null;

        // The 0_square table already includes the company prefix in its name
        // so we query it directly without TB_PREF prefix
        $sql = "SELECT name, value FROM 0_square WHERE name IN ('access_token', 'sandbox_access_token', 'environment', 'location_id')";
        $result = db_query($sql);
        $config = [];
        while ($row = db_fetch($result)) {
            $config[$row['name']] = $row['value'];
        }

        $accessToken = trim((string)($config['access_token'] ?? $config['sandbox_access_token'] ?? ''));
        $locationId = trim((string)($config['location_id'] ?? ''));
        $environment = trim((string)($config['environment'] ?? 'sandbox'));

        if ($accessToken === '' || $locationId === '') return null;

        try {
            $client = new \Square\SquareClient([
                'accessToken' => $accessToken,
                'environment' => $environment === 'production' ? \Square\Environment::PRODUCTION : \Square\Environment::SANDBOX,
            ]);

            $mapDao = new SquareInvoiceMapDAO();
            return new SquareInvoiceService($client, $mapDao, $locationId);

        } catch (\Throwable $e) {
            $this->sqLog('error', 'Failed to build SquareInvoiceService', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // PAGE HOOKS
    // =========================================================================

    function pre_header($fun_args) {
        if (!isset($fun_args['page']) || $fun_args['page'] !== 'inventory/manage/items.php') return;

        // Add Square Invoice tab to items page
        if (isset($fun_args['stock_id'])) {
            $stockId = $fun_args['stock_id'];
            // Tab logic handled by FA_ProductAttributes
        }
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    private function injectSquareInvoicePaymentTerm() {
        // Inject square_invoice_* payment terms if not present
    }

    private function ensureSquareInvoiceMapTable() {
        if (!function_exists('db_query')) return;
        $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
        $sql = "CREATE TABLE IF NOT EXISTS " . $tablePrefix . "square_invoice_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fa_invoice_no INT NOT NULL,
            square_invoice_id VARCHAR(64) NOT NULL,
            square_order_id VARCHAR(64) NOT NULL,
            square_customer_id VARCHAR(64) DEFAULT '',
            amount_cents BIGINT NOT NULL DEFAULT 0,
            currency CHAR(3) DEFAULT 'CAD',
            destination VARCHAR(32) NOT NULL DEFAULT 'square_invoice',
            status VARCHAR(32) DEFAULT 'UNPAID',
            public_url TEXT,
            version INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fa_invoice (fa_invoice_no),
            KEY idx_square_invoice (square_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        db_query($sql, "Could not create square_invoice_map table");
    }
}
