<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Disputes Management Service
 * Handles chargeback and dispute tracking.
 */
class DisputesService
{
    public function processDispute(array $disputeData): void
    {
        $hookData = [
            'dispute_id' => $disputeData['id'] ?? null,
            'payment_id' => $disputeData['payment_id'] ?? null,
            'amount_disputed' => $disputeData['amount_disputed'] ?? 0,
            'currency' => $disputeData['currency'] ?? 'USD',
            'reason' => $disputeData['reason'] ?? '',
            'status' => $disputeData['status'] ?? 'open',
            'evidence_due' => $disputeData['evidence_due'] ?? null,
        ];
        \hook_invoke_all('log_dispute_crm', $hookData);
    }
}
