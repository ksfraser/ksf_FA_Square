<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * DAO for the square invoice mapping table.
 *
 * Links FA debtor_trans (sales invoices) to Square Invoices,
 * enabling payment matching when Square transactions are imported.
 */
class SquareInvoiceMapDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix = '0_')
    {
        $this->tablePrefix = $tablePrefix;
    }

    private function table(): string
    {
        return $this->tablePrefix . 'square_invoice_map';
    }

    /**
     * Ensure the mapping table exists.
     */
    public function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . $this->table() . "` (
            `fa_invoice_no` int(11) NOT NULL,
            `square_invoice_id` varchar(64) NOT NULL DEFAULT '',
            `square_order_id` varchar(64) NOT NULL DEFAULT '',
            `square_customer_id` varchar(64) NOT NULL DEFAULT '',
            `amount_cents` int(11) NOT NULL DEFAULT 0,
            `currency` varchar(3) NOT NULL DEFAULT 'CAD',
            `destination` varchar(32) NOT NULL DEFAULT 'square_invoice',
            `status` varchar(16) NOT NULL DEFAULT 'DRAFT',
            `public_url` varchar(512) NOT NULL DEFAULT '',
            `version` int(11) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`fa_invoice_no`),
            KEY `square_invoice_id` (`square_invoice_id`),
            KEY `square_order_id` (`square_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        db_query($sql, 'Could not create square_invoice_map table');
    }

    /**
     * Insert a new mapping.
     */
    public function insert(array $data): bool
    {
        $sql = "INSERT INTO `" . $this->table() . "` (
            fa_invoice_no, square_invoice_id, square_order_id, square_customer_id,
            amount_cents, currency, destination, status, public_url
        ) VALUES (
            " . db_escape($data['fa_invoice_no']) . ",
            " . db_escape($data['square_invoice_id']) . ",
            " . db_escape($data['square_order_id'] ?? '') . ",
            " . db_escape($data['square_customer_id'] ?? '') . ",
            " . (int)($data['amount_cents'] ?? 0) . ",
            " . db_escape($data['currency'] ?? 'CAD') . ",
            " . db_escape($data['destination'] ?? 'square_invoice') . ",
            " . db_escape($data['status'] ?? 'DRAFT') . ",
            " . db_escape($data['public_url'] ?? '') . "
        )";
        return db_query($sql, 'Could not insert square invoice mapping');
    }

    /**
     * Update an existing mapping row by FA invoice number.
     *
     * Used when re-pushing an FA invoice that already has a Square Invoice
     * linked (update the Square-invoice id / version / url / status).
     */
    public function update(int $faInvoiceNo, array $data): bool
    {
        $sets = [];
        if (isset($data['square_invoice_id'])) {
            $sets[] = "square_invoice_id = " . db_escape($data['square_invoice_id']);
        }
        if (isset($data['square_order_id'])) {
            $sets[] = "square_order_id = " . db_escape($data['square_order_id']);
        }
        if (isset($data['square_customer_id'])) {
            $sets[] = "square_customer_id = " . db_escape($data['square_customer_id']);
        }
        if (isset($data['amount_cents'])) {
            $sets[] = "amount_cents = " . (int)$data['amount_cents'];
        }
        if (isset($data['currency'])) {
            $sets[] = "currency = " . db_escape($data['currency']);
        }
        if (isset($data['destination'])) {
            $sets[] = "destination = " . db_escape($data['destination']);
        }
        if (isset($data['status'])) {
            $sets[] = "status = " . db_escape($data['status']);
        }
        if (isset($data['public_url'])) {
            $sets[] = "public_url = " . db_escape($data['public_url']);
        }
        if (isset($data['version'])) {
            $sets[] = "version = " . (int)$data['version'];
        }
        if (empty($sets)) {
            return true;
        }
        $sets[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = "UPDATE `" . $this->table() . "` SET " . implode(', ', $sets) .
               " WHERE fa_invoice_no = " . (int)$faInvoiceNo;
        return db_query($sql, 'Could not update square invoice mapping');
    }

    /**
     * Find mapping by FA invoice number.
     */
    public function findByFaInvoiceNo(int $faInvoiceNo): ?array
    {
        $sql = "SELECT * FROM `" . $this->table() . "`
                WHERE fa_invoice_no = " . (int)$faInvoiceNo;
        $result = db_query($sql, 'Could not find square invoice mapping');
        return db_fetch($result) ?: null;
    }

    /**
     * Find mapping by Square Invoice ID.
     */
    public function findBySquareInvoiceId(string $squareInvoiceId): ?array
    {
        $sql = "SELECT * FROM `" . $this->table() . "`
                WHERE square_invoice_id = " . db_escape($squareInvoiceId);
        $result = db_query($sql, 'Could not find square invoice mapping by square ID');
        return db_fetch($result) ?: null;
    }

    /**
     * Find mapping by Square Order ID.
     */
    public function findBySquareOrderId(string $squareOrderId): ?array
    {
        $sql = "SELECT * FROM `" . $this->table() . "`
                WHERE square_order_id = " . db_escape($squareOrderId);
        $result = db_query($sql, 'Could not find square invoice mapping by order ID');
        return db_fetch($result) ?: null;
    }

    /**
     * Update mapping status.
     */
    public function updateStatus(int $faInvoiceNo, string $status): bool
    {
        $sql = "UPDATE `" . $this->table() . "`
                SET status = " . db_escape($status) . "
                WHERE fa_invoice_no = " . (int)$faInvoiceNo;
        return db_query($sql, 'Could not update square invoice mapping status');
    }

    /**
     * Find all unpaid/pending mappings.
     */
    public function findPending(): array
    {
        $sql = "SELECT * FROM `" . $this->table() . "`
                WHERE status IN ('DRAFT', 'UNPAID', 'SCHEDULED', 'PARTIALLY_PAID')
                ORDER BY created_at DESC";
        $result = db_query($sql, 'Could not find pending square invoice mappings');
        $rows = [];
        while ($row = db_fetch($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Delete a mapping.
     */
    public function delete(int $faInvoiceNo): bool
    {
        $sql = "DELETE FROM `" . $this->table() . "`
                WHERE fa_invoice_no = " . (int)$faInvoiceNo;
        return db_query($sql, 'Could not delete square invoice mapping');
    }
}
