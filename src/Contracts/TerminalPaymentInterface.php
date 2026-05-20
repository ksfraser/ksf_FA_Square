<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

use Square\Models\Order;
use Square\Models\TerminalCheckout;

interface TerminalPaymentInterface
{
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

    public function getCheckoutStatus(string $checkoutId): TerminalCheckout;

    public function cancelCheckout(string $checkoutId): void;
}
