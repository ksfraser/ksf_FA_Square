<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use ksfraser\FrontAccounting\Square\Services\SquareInvoiceService;

/**
 * Square Note Service.
 *
 * Creates CRM notes for Square Invoice activities.
 * Handles note creation with proper entity links (customer, invoice, contacts).
 *
 * @BABOK Related: NFR-ISU-007
 */
class SquareNoteService
{
    /**
     * Create a CRM note for a Square Invoice creation.
     *
     * @param int    $faInvoiceNo        FA invoice number
     * @param string $squareInvoiceShortId Short Square invoice ID
     * @param string $publicUrl          Square Invoice public URL
     * @param array  $cartDebug          Cart debug data
     * @param array  $squareResponse     Square API response
     * @param array  $data               Prewrite data (customer_id, branch_code, etc.)
     * @param array  $sessionUser        Session user info
     * @return array Note creation result
     */
    public function createSquareInvoiceNote(
        int $faInvoiceNo,
        string $squareInvoiceShortId,
        string $publicUrl,
        array $cartDebug,
        array $squareResponse,
        array $data,
        array $sessionUser
    ): array {
        // Build note links
        $noteLinks = $this->buildNoteLinks($faInvoiceNo, $data);

        // Build note data
        $noteData = [
            'note'      => _("Square Invoice created for FA Sales Invoice #") . $faInvoiceNo . ' — Square Invoice: ' . $publicUrl,
            'subject'   => _("FA Invoice #") . $faInvoiceNo . ' / ' . _("Square Invoice #") . $squareInvoiceShortId,
            'note_type' => 'square_invoice',
            'owner'     => $sessionUser['loginname'] ?? '',
        ];
        $noteOpts = ['links' => $noteLinks];

        $noteResult = hook_invoke('ksf_FA_Notes', 'CREATE_NOTE', $noteData, $noteOpts);
        
        // Debug log the result
        $this->log('info', 'CREATE_NOTE hook result', [
            'result' => $noteResult,
            'noteData_error' => $noteData['error'] ?? 'none',
            'noteData_note_id' => $noteData['note_id'] ?? 'none',
            'noteData_links_added' => $noteData['links_added'] ?? 'none',
        ]);
        
        if ($noteResult === false) {
            $this->log('error', 'CREATE_NOTE failed for Square Invoice #' . $faInvoiceNo . ': ' . ($noteData['error'] ?? 'unknown') . ', customer_id=' . ($data['customer_id'] ?? 'NOT_SET'));
            return ['ok' => false, 'error' => $noteData['error'] ?? 'unknown'];
        }

        $noteId = $noteData['note_id'] ?? 0;
        $this->log('info', 'CRM note created (id=' . $noteId . ') for Square Invoice #' . $faInvoiceNo, ['links_added' => $noteData['links_added'] ?? 0]);
        
        return ['ok' => true, 'note_id' => $noteId];
    }

    /**
     * Simple logger for this service.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('ksf_log')) {
            ksf_log('square', $level, $message, $context);
        }
        $logDir = "/var/www/html/company/0/logs";
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = [
            "ts" => date("Y-m-d H:i:s.u"),
            "level" => strtoupper($level),
            "msg" => $message,
            "ctx" => $context,
            "pid" => getmypid(),
        ];
        @file_put_contents($logDir . "/square_" . date("Y-m-d") . ".log", json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Build note links for Square Invoice.
     *
     * @param int   $faInvoiceNo FA invoice number
     * @param array $data        Prewrite data (customer_id, branch_code)
     * @return array Note links array
     */
    private function buildNoteLinks(int $faInvoiceNo, array $data): array
    {
        $noteLinks = [
            ['customer', (string)$data['customer_id'], 'reference'],
            ['invoice', (string)$faInvoiceNo, 'source_invoice'],
        ];

        if (!empty($data['branch_code'])) {
            $this->log('info', 'Looking up branch contacts', ['branch_code' => $data['branch_code'], 'customer_id' => (string)$data['customer_id']]);
            $branchContacts = $this->getBranchInvoiceContacts($data['branch_code'], (string)$data['customer_id']);
            $this->log('info', 'Found branch contacts', ['branchContacts' => $branchContacts]);
            foreach ($branchContacts as $contactId) {
                $noteLinks[] = ['contact', (int)$contactId, 'invoice_contact'];
            }
        }

        return $noteLinks;
    }

    /**
     * Get branch invoice contacts using native FA function.
     *
     * @param string $branchCode Branch code
     * @param string $customerId Customer ID (debtor_no)
     * @return array Contact IDs
     */
    private function getBranchInvoiceContacts(string $branchCode, string $customerId): array
    {
        if (!function_exists('db_query') || $branchCode === '' || $customerId === '') {
            return [];
        }

        if (!function_exists('get_crm_persons')) {
            require_once($GLOBALS['path_to_root'] . '/includes/db/crm_contacts_db.inc');
        }

        $result = get_crm_persons('customer', 'Invoice', $branchCode);
        
        $contacts = [];
        if ($result) {
            while ($row = db_fetch_assoc($result)) {
                if ((string)$row['ref'] === $customerId) {
                    $contacts[] = (int)$row['contact_id'];
                }
            }
        }

        return $contacts;
    }

    /**
     * Extract short Square invoice ID from full ID.
     *
     * @param string $squareInvoiceId Full Square invoice ID
     * @return string Short ID
     */
    public static function extractShortSquareId(string $squareInvoiceId): string
    {
        $lastDash = strrpos($squareInvoiceId, '-');
        if ($lastDash !== false && $lastDash < strlen($squareInvoiceId) - 1) {
            $candidate = substr($squareInvoiceId, $lastDash + 1);
            if (preg_match('/^[A-Za-z0-9_]{6,}$/', $candidate)) {
                return $candidate;
            }
        }
        return $squareInvoiceId;
    }
}
