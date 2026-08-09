<?php
declare(strict_types=1);

namespace Square\Models;

class WebhookEventType
{
    const PAYMENT_CREATED = 'payment.created';
    const ORDER_CREATED = 'order.created';
    const CUSTOMER_CREATED = 'customer.created';
    const CUSTOMER_UPDATED = 'customer.updated';
    const INVOICE_CREATED = 'invoice.created';
    const REFUND_CREATED = 'refund.created';

    public static function from(string $event): string
    {
        return $event;
    }

    public static function name(string $event): string
    {
        $map = [
            'payment.created' => 'Payment Created',
            'order.created' => 'Order Created',
            'customer.created' => 'Customer Created',
            'customer.updated' => 'Customer Updated',
            'invoice.created' => 'Invoice Created',
            'refund.created' => 'Refund Created',
        ];
        return $map[$event] ?? $event;
    }
}
