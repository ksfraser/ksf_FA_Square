<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use Square\SquareClient;
use ksfraser\FrontAccounting\Square\DAO\WebhookSubscriptionDAO;
use ksfraser\FrontAccounting\Square\Contracts\WebhookServiceInterface;
use ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException;
use ksfraser\FrontAccounting\Square\Exceptions\WebhookValidationException;
use Square\Models\CreateWebhookSubscriptionRequest;
use Square\Models\WebhookSubscription;
use Square\Models\WebhookEventType;
use Square\Exceptions\ApiException;

/**
 * Service for managing Square webhook subscriptions and event handling.
 * 
 * Enables real-time synchronization by replacing polling with webhook-driven updates.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookService implements WebhookServiceInterface
{
    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var WebhookSubscriptionDAO
     */
    private $subscriptionDao;

    /**
     * @var string
     */
    private $webhookUrl;

    /**
     * @var string
     */
    private $webhookSignatureKey;

    public function __construct(SquareClient $client, WebhookSubscriptionDAO $subscriptionDao, string $webhookUrl, string $webhookSignatureKey = 'test_secret')
    {
        $this->client = $client;
        $this->subscriptionDao = $subscriptionDao;
        $this->webhookUrl = $webhookUrl;
        $this->webhookSignatureKey = $webhookSignatureKey;
    }

    /**
     * Gets the current Square environment from module settings.
     *
     * @return string 'sandbox' or 'production'
     *
     * @since 2.4.4
     */
    public static function getCurrentEnvironment(): string
    {
        $tablePrefix = defined('TB_PREF') ? TB_PREF : '0_';
        $settings = \ksfraser\FrontAccounting\Square\Config\Settings::fromFADatabase($tablePrefix);
        return $settings->getEnvironment();
    }

    /**
     * Builds the webhook endpoint URL for this install.
     *
     * @return string Absolute URL to pages/webhook.php
     *
     * @since 2.4.4
     */
    public static function getCurrentWebhookUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($basePath === '.' || $basePath === '') {
            $basePath = '';
        }
        return $scheme . '://' . $host . $basePath . '/webhook.php';
    }

    /**
     * Creates a new webhook subscription in Square.
     *
     * @param string $url Webhook endpoint URL
     * @param array $events List of event types to subscribe to
     * @return WebhookSubscription Created subscription object
     * @throws WebhookCreationException If subscription creation fails
     */
    public function createSubscription(string $url, array $events): WebhookSubscription
    {
        $this->validateUrl($url);
        $this->validateEvents($events);

        try {
            $api = $this->client->getWebhookSubscriptionsApi();
            
            $eventTypes = array_map(function ($event) {
                return WebhookEventType::from($event);
            }, $events);

            $subscriptionModel = new WebhookSubscription();
            $subscriptionModel->setNotificationUrl($url);
            $subscriptionModel->setEventTypes($eventTypes);
            $subscriptionModel->setEnabled(true);

            $request = new CreateWebhookSubscriptionRequest($subscriptionModel);

            $result = $api->createWebhookSubscription($request);
            
            if (!$result->isSuccess()) {
                throw new WebhookCreationException(
                    "Failed to create webhook subscription: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            $subscription = $result->getResult()->getSubscription();
            
            // Store subscription in our database
            $this->subscriptionDao->insertSubscription([
                'square_id' => $subscription->getId(),
                'url' => $subscription->getNotificationUrl(),
                'events' => json_encode($events),
                'signature_key' => $subscription->getSignatureKey(),
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $subscription;
        } catch (ApiException $e) {
            throw new WebhookCreationException(
                "Square API error creating webhook subscription: " . $e->getMessage()
            );
        }
    }

    /**
     * Lists all webhook subscriptions from Square.
     *
     * @return array Array of WebhookSubscription objects
     * @throws WebhookCreationException If listing fails
     */
    public function listSubscriptions(): array
    {
        try {
            $api = $this->client->getWebhookSubscriptionsApi();
            $result = $api->listWebhookSubscriptions();
            
            if (!$result->isSuccess()) {
                throw new WebhookCreationException(
                    "Failed to list webhook subscriptions: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            return $result->getResult()->getSubscriptions() ?? [];
        } catch (ApiException $e) {
            throw new WebhookCreationException(
                "Square API error listing webhook subscriptions: " . $e->getMessage()
            );
        }
    }

    /**
     * Updates an existing webhook subscription.
     *
     * @param string $id Subscription ID
     * @param WebhookSubscription $subscription Updated subscription data
     * @return WebhookSubscription Updated subscription object
     * @throws WebhookCreationException If update fails
     */
    public function updateSubscription(string $id, WebhookSubscription $subscription): WebhookSubscription
    {
        try {
            $api = $this->client->getWebhookSubscriptionsApi();
            $result = $api->updateWebhookSubscription($id, $subscription);
            
            if (!$result->isSuccess()) {
                throw new WebhookCreationException(
                    "Failed to update webhook subscription: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            // Update our database record
            $this->subscriptionDao->updateSubscription($id, [
                'url' => $subscription->getNotificationUrl(),
                'events' => json_encode($subscription->getEventTypes()),
                'is_active' => $subscription->getEnabled(),
            ]);

            return $result->getResult()->getSubscription();
        } catch (ApiException $e) {
            throw new WebhookCreationException(
                "Square API error updating webhook subscription: " . $e->getMessage()
            );
        }
    }

    /**
     * Deletes a webhook subscription.
     *
     * @param string $id Subscription ID
     * @return bool True if deletion was successful
     * @throws WebhookCreationException If deletion fails
     */
    public function deleteSubscription(string $id): bool
    {
        try {
            $api = $this->client->getWebhookSubscriptionsApi();
            $result = $api->deleteWebhookSubscription($id);
            
            if (!$result->isSuccess()) {
                throw new WebhookCreationException(
                    "Failed to delete webhook subscription: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            // Delete from our database
            $this->subscriptionDao->deleteSubscription($id);

            return true;
        } catch (ApiException $e) {
            throw new WebhookCreationException(
                "Square API error deleting webhook subscription: " . $e->getMessage()
            );
        }
    }

    /**
     * Processes an incoming webhook event payload.
     *
     * Higher-level wrapper that validates and handles a webhook payload,
     * returning a processing summary.
     *
     * @param array $eventData Raw webhook event data
     * @return array Processing summary
     */
    public function processWebhook(array $eventData): array
    {
        $signature = $eventData['signature'] ?? '';
        $handled = $this->handleWebhookEvent($eventData, $signature);

        return [
            'success' => $handled,
            'events_processed' => $handled ? 1 : 0
        ];
    }

    /**
     * Handles an incoming webhook event from Square.
     *
     * @param array $eventData Raw webhook event data
     * @param string $signature Webhook signature for validation
     * @return bool True if event was handled successfully
     * @throws WebhookValidationException If validation fails
     */
    public function handleWebhookEvent(array $eventData, string $signature): bool
    {
        // Validate signature
        if (!$this->validateWebhookSignature($eventData, $signature)) {
            throw new WebhookValidationException("Invalid webhook signature");
        }

        // Validate event structure
        $eventType = $eventData['type'] ?? null;
        $eventId = $eventData['event_id'] ?? null;
        $createdAt = $eventData['created_at'] ?? null;

        if (!$eventType || !$eventId || !$createdAt) {
            throw new WebhookValidationException("Invalid webhook event structure");
        }

        // Process the event based on type
        switch ($eventType) {
            case 'payment.created':
                $this->handlePaymentCreated($eventData);
                break;
            case 'order.created':
                $this->handleOrderCreated($eventData);
                break;
            case 'customer.created':
                $this->handleCustomerCreated($eventData);
                break;
            case 'customer.updated':
                $this->handleCustomerUpdated($eventData);
                break;
            default:
                // Log unknown event type but don't fail
                error_log("Unknown webhook event type: " . $eventType);
                break;
        }

        // Log the event processing
        $this->subscriptionDao->logEvent([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_data' => json_encode($eventData),
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Validates webhook URL format.
     *
     * @param string $url URL to validate
     * @throws WebhookValidationException If URL is invalid
     */
    private function validateUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new WebhookValidationException("Invalid webhook URL format");
        }

        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            throw new WebhookValidationException("Webhook URL must use HTTP or HTTPS");
        }
    }

    /**
     * Validates webhook event types.
     *
     * @param array $events Event types to validate
     * @throws WebhookValidationException If events are invalid
     */
    private function validateEvents(array $events): void
    {
        $validEvents = [
            'payment.created',
            'payment.updated',
            'order.created',
            'order.updated',
            'customer.created',
            'customer.updated',
            'item.created',
            'item.updated',
        ];

        foreach ($events as $event) {
            if (!in_array($event, $validEvents)) {
                throw new WebhookValidationException("Invalid webhook event type: " . $event);
            }
        }
    }

    /**
     * Validates webhook signature for security.
     *
     * @param array $eventData Event data to validate
     * @param string $signature Signature to verify
     * @return bool True if signature is valid
     */
    private function validateWebhookSignature(array $eventData, string $signature): bool
    {
        // In a real implementation, this would use Square's signature validation
        // For now, we'll implement a basic HMAC validation
        $webhookKey = $this->getWebhookKey();
        $payload = json_encode($eventData);
        
        $expectedSignature = hash_hmac('sha256', $payload, $webhookKey);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Gets client location IDs for subscription.
     *
     * @return array Location IDs
     */
    private function getClientLocationIds(): array
    {
        try {
            $api = $this->client->getLocationsApi();
            $result = $api->listLocations();
            
            if ($result->isSuccess()) {
                $locations = $result->getResult()->getLocations();
                return array_map(function ($location) {
                    return $location->getId();
                }, $locations);
            }
        } catch (ApiException $e) {
            // If we can't get locations, return empty array
            error_log("Error getting locations for webhook: " . $e->getMessage());
        }
        
        return [];
    }

    /**
     * Handles payment.created webhook event.
     *
     * @param array $eventData Event data
     */
    private function handlePaymentCreated(array $eventData): void
    {
        // Extract payment ID and process
        $paymentId = $eventData['data']['payment']['id'] ?? null;
        
        if ($paymentId) {
            // This would trigger the import service to process the new payment
            // For now, we'll just log it
            error_log("Payment created event received: " . $paymentId);
        }
    }

    /**
     * Handles order.created webhook event.
     *
     * @param array $eventData Event data
     */
    private function handleOrderCreated(array $eventData): void
    {
        $orderId = $eventData['data']['order']['id'] ?? null;
        
        if ($orderId) {
            error_log("Order created event received: " . $orderId);
        }
    }

    /**
     * Handles customer.created webhook event.
     *
     * @param array $eventData Event data
     */
    private function handleCustomerCreated(array $eventData): void
    {
        $customerId = $eventData['data']['customer']['id'] ?? null;
        
        if ($customerId) {
            error_log("Customer created event received: " . $customerId);
        }
    }

    /**
     * Handles customer.updated webhook event.
     *
     * @param array $eventData Event data
     */
    private function handleCustomerUpdated(array $eventData): void
    {
        $customerId = $eventData['data']['customer']['id'] ?? null;
        
        if ($customerId) {
            error_log("Customer updated event received: " . $customerId);
        }
    }

    /**
     * Gets webhook secret key for signature validation.
     *
     * In production this would come from secure storage (e.g. the stored
     * subscription signature key). The constructor-injected default keeps the
     * service runnable until secure storage is wired in.
     *
     * @return string Webhook key
     */
    private function getWebhookKey(): string
    {
        return $this->webhookSignatureKey;
    }

    /**
     * Extracts error message from API response.
     *
     * @param array $errors API errors
     * @return string Error message
     */
    private function getApiErrorMessage(array $errors): string
    {
        $messages = array_map(function ($error) {
            return $error->getDetail() ?? $error->getCode() ?? 'Unknown error';
        }, $errors);
        
        return implode('; ', $messages);
    }
}