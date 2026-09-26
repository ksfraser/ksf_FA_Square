<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Gift Cards & Loyalty Service
 * Handles gift card and loyalty program synchronization.
 */
class GiftCardService
{
    public function processGiftCard(array $giftData): void
    {
        $hookData = [
            'gift_card_id' => $giftData['id'] ?? null,
            'balance' => $giftData['balance'] ?? 0,
            'currency' => $giftData['currency'] ?? 'USD',
            'status' => $giftData['status'] ?? 'active',
        ];
        \hook_invoke_all('stage_gift_card', $hookData);
    }

    public function processLoyalty(array $loyaltyData): void
    {
        $hookData = [
            'customer_id' => $loyaltyData['customer_id'] ?? null,
            'loyalty_points' => $loyaltyData['points'] ?? 0,
            'program_id' => $loyaltyData['program_id'] ?? null,
        ];
        \hook_invoke_all('stage_loyalty_program', $hookData);
    }
    public function processB2BInvoice(array $invoiceData): void
    {
        $hookData = [
            'invoice_id' => $invoiceData['id'] ?? null,
            'customer_id' => $invoiceData['customer_id'] ?? null,
            'amount_due' => $invoiceData['amount_due'] ?? 0,
            'currency' => $invoiceData['currency'] ?? 'USD',
            'status' => $invoiceData['status'] ?? 'pending',
            'due_date' => $invoiceData['due_date'] ?? null,
        ];
        \hook_invoke_all('stage_invoice_b2b', $hookData);
    }
}
