<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Push;

use Ksfraser\Frontaccounting\SquareUp\Contracts\TerminalPaymentInterface;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Square\SquareClient;
use Square\Exceptions\ApiException;
use Square\Models\Order;
use Square\Models\CreateOrderRequest;
use Square\Models\OrderLineItem;
use Square\Models\Money;
use Square\Models\TerminalCheckout;
use Square\Models\CreateTerminalCheckoutRequest;
use Square\Models\DeviceCheckoutOptions;

class TerminalPayment implements TerminalPaymentInterface
{
    private SquareClient $client;
    private SettingsInterface $settings;

    public function __construct(SquareClient $client, SettingsInterface $settings)
    {
        $this->client = $client;
        $this->settings = $settings;
    }

    public function createOrderFromInvoice(
        string $locationId,
        array $lineItems,
        ?string $customerId = null,
        ?string $referenceId = null
    ): Order {
        try {
            $items = [];
            foreach ($lineItems as $item) {
                $lineItem = new OrderLineItem($item['quantity']);
                $lineItem->setName($item['name'] ?? '');
                $lineItem->setCatalogObjectId($item['catalog_object_id'] ?? null);
                $lineItem->setBasePriceMoney(new Money());
                $lineItem->getBasePriceMoney()->setAmount($item['base_price_cents'] ?? 0);
                $lineItem->getBasePriceMoney()->setCurrency($item['currency'] ?? 'CAD');

                if (isset($item['variation_name'])) {
                    $lineItem->setVariationName($item['variation_name']);
                }

                $items[] = $lineItem;
            }

            $order = new Order($locationId);
            $order->setLineItems($items);

            if ($customerId !== null) {
                $order->setCustomerId($customerId);
            }

            if ($referenceId !== null) {
                $order->setReferenceId($referenceId);
            }

            $request = new CreateOrderRequest();
            $request->setIdempotencyKey(uniqid('', true));
            $request->setOrder($order);

            $response = $this->client->getOrdersApi()->createOrder($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'createOrder',
                    'Failed to create order',
                    $response->getErrors()
                );
            }

            return $response->getResult()->getOrder();
        } catch (ApiException $e) {
            throw SquareException::apiError('createOrder', $e->getMessage());
        }
    }

    public function createTerminalCheckout(
        Order $order,
        string $deviceId,
        string $idempotencyKey,
        ?int $tipCents = null
    ): TerminalCheckout {
        try {
            $amount = $order->getTotalMoney();
            if ($amount === null) {
                $amount = new Money();
                $amount->setAmount(0);
                $amount->setCurrency('CAD');
            }

            $deviceOptions = new DeviceCheckoutOptions($deviceId);
            $deviceOptions->setSkipReceiptScreen(false);
            $deviceOptions->setTipSettings(new \Square\Models\TipSettings());
            $deviceOptions->getTipSettings()->setAllowTipping($tipCents !== null);
            if ($tipCents !== null) {
                $deviceOptions->getTipSettings()->setCustomTipField(false);
            }

            $checkout = new TerminalCheckout($amount, $deviceOptions);
            $checkout->setOrderId($order->getId());
            $checkout->setReferenceId($order->getReferenceId());
            $checkout->setNote('FrontAccounting Invoice Payment');

            $request = new CreateTerminalCheckoutRequest($idempotencyKey, $checkout);

            $response = $this->client->getTerminalApi()->createTerminalCheckout($request);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'createTerminalCheckout',
                    'Failed to create terminal checkout',
                    $response->getErrors()
                );
            }

            return $response->getResult()->getCheckout();
        } catch (ApiException $e) {
            throw SquareException::apiError('createTerminalCheckout', $e->getMessage());
        }
    }

    public function getCheckoutStatus(string $checkoutId): TerminalCheckout
    {
        try {
            $response = $this->client->getTerminalApi()->getTerminalCheckout($checkoutId);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'getTerminalCheckout',
                    "Checkout {$checkoutId} not found",
                    $response->getErrors()
                );
            }

            return $response->getResult()->getCheckout();
        } catch (ApiException $e) {
            throw SquareException::apiError('getTerminalCheckout', $e->getMessage());
        }
    }

    public function cancelCheckout(string $checkoutId): void
    {
        try {
            $response = $this->client->getTerminalApi()->cancelTerminalCheckout($checkoutId);

            if (!$response->isSuccess()) {
                throw SquareException::apiError(
                    'cancelTerminalCheckout',
                    "Failed to cancel checkout {$checkoutId}",
                    $response->getErrors()
                );
            }
        } catch (ApiException $e) {
            throw SquareException::apiError('cancelTerminalCheckout', $e->getMessage());
        }
    }
}
