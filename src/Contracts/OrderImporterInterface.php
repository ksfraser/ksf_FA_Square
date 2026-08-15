<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

use Square\Models\Order;
use Square\Models\Payment;

interface OrderImporterInterface
{
    public function listPayments(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $locationId = null
    ): array;

    public function getPaymentWithOrder(string $paymentId): array;

    public function getOrder(string $orderId): ?Order;

    public function getOrders(array $orderIds): array;
}
