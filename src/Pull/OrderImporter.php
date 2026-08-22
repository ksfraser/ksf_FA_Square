<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Pull;

use ksfraser\FrontAccounting\Square\Contracts\OrderImporterInterface;
use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;
use Square\SquareClient;
use Square\Exceptions\ApiException;
use Square\Models\Order;
use Square\Models\SearchOrdersRequest;
use Square\Models\SearchOrdersQuery;
use Square\Models\SearchOrdersFilter;
use Square\Models\SearchOrdersDateTimeFilter;
use Square\Models\TimeRange;

class OrderImporter implements OrderImporterInterface
{
    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var SettingsInterface
     */
    private $settings;

    public function __construct(SquareClient $client, SettingsInterface $settings)
    {
        $this->client = $client;
        $this->settings = $settings;
    }

    public function listPayments(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $locationId = null
    ): array {
        $payments = [];
        $cursor = null;

        // Square endTime is exclusive for range queries. Add 1 day to cover
        // the full "to" date (e.g. "2026-08-21" → endTime at 2026-08-22T00:00:00Z).
        $endTime = clone $to;
        if ($endTime instanceof \DateTimeImmutable) {
            $endTime = $endTime->modify('+1 day');
        } else {
            $endTime = new \DateTime($endTime->format('Y-m-d') . ' +1 day');
        }

        try {
            do {
                // Don't pass locationId to the API — Square sandbox ignores it.
                // Filter locally after fetching instead.
                $response = $this->client->getPaymentsApi()->listPayments(
                    $from->format('Y-m-d\TH:i:s\Z'),
                    $endTime->format('Y-m-d\TH:i:s\Z'),
                    null,
                    $cursor,
                    null,
                    100
                );

                if ($response->isSuccess()) {
                    $result = $response->getResult();
                    if ($result->getPayments() !== null) {
                        foreach ($result->getPayments() as $payment) {
                            if ($locationId === null
                                || $payment->getLocationId() === $locationId
                            ) {
                                $payments[] = $payment;
                            }
                        }
                    }
                    $cursor = $result->getCursor();
                } else {
                    break;
                }
            } while ($cursor !== null);

            return $payments;
        } catch (ApiException $e) {
            throw SquareException::apiError('listPayments', $e->getMessage());
        }
    }

    public function getPaymentWithOrder(string $paymentId): array
    {
        try {
            $response = $this->client->getPaymentsApi()->getPayment($paymentId);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'getPayment',
                    "Payment {$paymentId} not found",
                    $response->getErrors()
                );
            }

            $payment = $response->getResult()->getPayment();
            $order = null;

            if ($payment->getOrderId() !== null) {
                $order = $this->getOrder($payment->getOrderId());
            }

            return ['payment' => $payment, 'order' => $order];
        } catch (ApiException $e) {
            throw SquareException::apiError('getPayment', $e->getMessage());
        }
    }

    public function getOrder(string $orderId): ?Order
    {
        try {
            $response = $this->client->getOrdersApi()->retrieveOrder($orderId);

            if (!$response->isSuccess()) {
                return null;
            }

            return $response->getResult()->getOrder();
        } catch (ApiException $e) {
            throw SquareException::apiError('retrieveOrder', $e->getMessage());
        }
    }

    public function getOrders(array $orderIds): array
    {
        try {
            $response = $this->client->getOrdersApi()->batchRetrieveOrders(
                new \Square\Models\BatchRetrieveOrdersRequest($orderIds)
            );

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'batchRetrieveOrders',
                    'Failed to retrieve orders',
                    $response->getErrors()
                );
            }

            return $response->getResult()->getOrders() ?? [];
        } catch (ApiException $e) {
            throw SquareException::apiError('batchRetrieveOrders', $e->getMessage());
        }
    }
}
