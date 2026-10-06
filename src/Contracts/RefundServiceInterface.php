<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

use Square\Models\Payment;
use Square\Models\Refund;

/**
 * Contract for Square refund and payment-cancellation operations.
 *
 * Restored after being lost in the 54fbf55 namespace migration, which left
 * RefundService implementing an unresolvable interface and therefore
 * unloadable by the Composer autoloader.
 *
 * Signatures mirror RefundService exactly. Note recordRefundInFA() returns
 * int (the created FA credit note ID); the earlier DEVELOPMENT_PLAN.md draft
 * declared it as void, which was wrong.
 *
 * @BABOK Related: FR-SQUARE-REFUND
 */
interface RefundServiceInterface
{
    /**
     * Create a full or partial refund against a Square payment.
     *
     * @param Payment $payment Square payment to refund
     * @param int $amountInCents Amount to refund, in cents
     * @param string $reason Refund reason
     * @param string|null $locationId Square location ID
     * @return Refund Created Square refund
     */
    public function createRefund(
        Payment $payment,
        int $amountInCents,
        string $reason,
        ?string $locationId = null
    ): Refund;

    /**
     * List refunds for a date range.
     *
     * @param string|null $beginTime Start of range
     * @param string|null $endTime End of range
     * @param string|null $locationId Square location ID
     * @return array Refund records
     */
    public function listRefunds(
        ?string $beginTime = null,
        ?string $endTime = null,
        ?string $locationId = null
    ): array;

    /**
     * Cancel a Square payment outright.
     *
     * @param string $paymentId Square payment ID
     * @return bool True when cancelled
     */
    public function cancelPayment(string $paymentId): bool;

    /**
     * Record a Square refund against an FA invoice as a credit note.
     *
     * @param Refund $refund Square refund object
     * @param int $invoiceId FA invoice ID
     * @return int Created FA credit note ID
     */
    public function recordRefundInFA(Refund $refund, int $invoiceId): int;

    /**
     * Process a batch of refunds.
     *
     * @param array $refunds Refunds to process
     * @return array Per-refund processing results
     */
    public function processRefundBatch(array $refunds): array;

    /**
     * Aggregate refund statistics for a date range.
     *
     * @param string|null $beginTime Start of range
     * @param string|null $endTime End of range
     * @return array Statistics
     */
    public function getRefundStatistics(
        ?string $beginTime = null,
        ?string $endTime = null
    ): array;
}