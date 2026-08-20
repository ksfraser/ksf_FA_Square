<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

use Square\Models\Invoice;

/**
 * Contract for Square Invoice operations.
 *
 * Creates and manages Square Invoices via the InvoicesApi,
 * linking them to FA invoices for payment tracking.
 */
interface SquareInvoiceServiceInterface
{
    /**
     * Create a Square Invoice from an FA sales invoice.
     *
     * @param int    $faInvoiceNo    FA debtor_trans trans_no
     * @param int    $debtorNo       FA debtor_no
     * @param array  $lineItems      FA sales_order_details rows
     * @param string $dueDate        YYYY-MM-DD
     * @param string $deliveryMethod EMAIL | SHARE_MANUALLY | SMS
     * @param string|null $automaticPaymentSource NONE | CARD_ON_FILE | BANK_ON_FILE
     * @return array ['square_invoice_id' => string, 'square_order_id' => string, 'public_url' => string]
     */
    public function createInvoiceFromFA(
        int $faInvoiceNo,
        int $debtorNo,
        array $lineItems,
        string $dueDate,
        string $deliveryMethod = 'SHARE_MANUALLY',
        ?string $automaticPaymentSource = null
    ): array;

    /**
     * Publish a DRAFT Square Invoice.
     *
     * @param string $squareInvoiceId
     * @param int    $version  Optimistic locking version
     * @return array ['status' => string, 'public_url' => string]
     */
    public function publishInvoice(string $squareInvoiceId, int $version): array;

    /**
     * Get the current status of a Square Invoice.
     *
     * @param string $squareInvoiceId
     * @return string Invoice status (DRAFT, UNPAID, PAID, etc.)
     */
    public function getInvoiceStatus(string $squareInvoiceId): string;

    /**
     * Look up FA invoice mapping by Square Invoice ID.
     *
     * @param string $squareInvoiceId
     * @return array|null Mapping row or null
     */
    public function findBySquareInvoiceId(string $squareInvoiceId): ?array;

    /**
     * Look up FA invoice mapping by Square Order ID.
     *
     * @param string $squareOrderId
     * @return array|null Mapping row or null
     */
    public function findBySquareOrderId(string $squareOrderId): ?array;

    /**
     * Update the status of a mapping record.
     *
     * @param int    $faInvoiceNo
     * @param string $status
     * @return bool
     */
    public function updateMappingStatus(int $faInvoiceNo, string $status): bool;
}
