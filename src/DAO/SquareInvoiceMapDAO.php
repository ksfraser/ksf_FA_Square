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
