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
    public function processMultiLocationTransfer(array $transferData): void
    {
        $hookData = [
            'location_from' => $transferData['from_location'] ?? null,
            'location_to' => $transferData['to_location'] ?? null,
            'item_code' => $transferData['item_code'] ?? null,
            'quantity' => $transferData['quantity'] ?? 0,
            'transfer_type' => 'inventory',
        ];
        \hook_invoke_all('stage_location_transfer', $hookData);
    }

}

