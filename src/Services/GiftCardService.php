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
        // Not staged. This used to broadcast a raw array to 'stage_gift_card',
        // which no module implements anywhere, so the payload was discarded and
        // the call looked like it had staged something.
        //
        // staging-dto ships no gift-card type at all (23 DTOs, none for gift
        // cards), so this cannot be built by wiring up a responder. It needs a
        // DTO, adapter support in ISU's DtoAdapter (which stages only 9 types),
        // and a decision on where a Square gift card lands in FA -- Square gift
        // cards are prepaid balances, which FA has no native equivalent for.
        // Left as a documented gap rather than a silent no-op.
    }

    public function processLoyalty(array $loyaltyData): void
    {
        // Not staged. The raw array went to 'stage_loyalty_program', which no
        // module implements, so it was discarded silently.
        //
        // Two problems, not one. StagingLoyaltyProgram exists in staging-dto but
        // ISU's DtoAdapter does not support it (it stages only order, invoice,
        // payment, refund, subscription, customer, product, productVariant and
        // category), so the responder would raise 'Unsupported DTO type'. And the
        // name lies: this payload is a per-customer points BALANCE
        // (customer_id + loyalty_points), not a program definition. Staging it as
        // StagingLoyaltyProgram would be wrong even once the adapter supports it;
        // StagingLoyaltyAccount is the closer fit.
    }
    public function processMultiLocationTransfer(array $transferData): void
    {
        // Not staged. The raw array went to 'stage_location_transfer', which no
        // module implements.
        //
        // This is an INTER-LOCATION inventory movement: the same stock leaving
        // one Square location to arrive at another. There is no DTO for it, and
        // it is not an inventory adjustment -- no quantity is created or
        // destroyed, so StagingInventory would be semantically wrong. Modeling
        // it correctly means deciding whether FA tracks this at all (FA's stock
        // is not location-scoped by default). Deliberately not faked.
    }

}

