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
        // Not staged, and not logged to CRM.
        //
        // This used to broadcast a raw array to 'log_dispute_crm', which no
        // module implements, so nothing was recorded and the caller saw no
        // error.
        //
        // It was also miscategorized: a dispute is not staging data, and
        // 'log_*' is not a capability this codebase has. Recording disputes
        // against a payment needs an owner -- CRM audit trail or the payment
        // record -- which is a decision, not a hook rename. Square disputes are
        // financially material (chargebacks), so this gap is worth closing
        // properly rather than pretending to.
    }
}
