<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

/**
 * Contract for webhook management services.
 * 
 * Defines the interface for handling webhook subscriptions and events.
 * 
 * @UML Note: Interface definition in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
interface WebhookServiceInterface
{
    /**
     * Creates a new webhook subscription in Square.
     *
     * @param string $url Webhook endpoint URL
     * @param array $events List of event types to subscribe to
     * @return \Square\Models\WebhookSubscription Created subscription object
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException
     */
    public function createSubscription(string $url, array $events): \Square\Models\WebhookSubscription;

    /**
     * Lists all webhook subscriptions from Square.
     *
     * @return array Array of \Square\Models\WebhookSubscription objects
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException
     */
    public function listSubscriptions(): array;

    /**
     * Updates an existing webhook subscription.
     *
     * @param string $id Subscription ID
     * @param \Square\Models\WebhookSubscription $subscription Updated subscription data
     * @return \Square\Models\WebhookSubscription Updated subscription object
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException
     */
    public function updateSubscription(string $id, \Square\Models\WebhookSubscription $subscription): \Square\Models\WebhookSubscription;

    /**
     * Deletes a webhook subscription.
     *
     * @param string $id Subscription ID
     * @return bool True if deletion was successful
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException
     */
    public function deleteSubscription(string $id): bool;

    /**
     * Handles an incoming webhook event from Square.
     *
     * @param array $eventData Raw webhook event data
     * @param string $signature Webhook signature for validation
     * @return bool True if event was handled successfully
     * @throws \ksfraser\FrontAccounting\Square\Exceptions\WebhookValidationException
     */
    public function handleWebhookEvent(array $eventData, string $signature): bool;
}