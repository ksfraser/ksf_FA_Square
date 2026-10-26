<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

use Square\Models\Order;
use Square\Models\TerminalCheckout;

interface TerminalPaymentInterface
{
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function createOrderFromInvoice(
        string $locationId,
        array $lineItems,
        ?string $customerId = null,
        ?string $referenceId = null
    ): Order;

    public function createTerminalCheckout(
        Order $order,
        string $deviceId,
        string $idempotencyKey,
        ?int $tipCents = null
    ): TerminalCheckout;

    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function getCheckoutStatus(string $checkoutId): TerminalCheckout;

    public function cancelCheckout(string $checkoutId): void;
}
