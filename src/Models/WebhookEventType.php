<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Models;

class WebhookEventType
{
    const PAYMENT_CREATED = 'payment.created';
    const ORDER_CREATED = 'order.created';
    const CUSTOMER_CREATED = 'customer.created';
    const CUSTOMER_UPDATED = 'customer.updated';
    const INVOICE_CREATED = 'invoice.created';
    const REFUND_CREATED = 'refund.created';

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function from(string $event): string
    {
        return $event;
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
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
