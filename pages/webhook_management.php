<?php
declare(strict_types=1);

/**
 * Webhook management controller
 * 
 * Handles webhook subscription management and event monitoring.
 * 
 * @UML Note: Controller diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookManagementController
{
    private \ksfraser\FrontAccounting\Square\Services\WebhookService $webhookService;
    private \ksfraser\FrontAccounting\Square\DAO\WebhookSubscriptionDAO $subscriptionDao;
    private \ksfraser\FrontAccounting\Square\DAO\WebhookEventDAO $eventDao;
    private string $tablePrefix;

    public function __construct()
    {
        $this->tablePrefix = get_company_pref('table_prefix');
        
        // Initialize services
        $client = \ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory::create(get_current_environment());
        $this->subscriptionDao = new \ksfraser\FrontAccounting\Square\DAO\WebhookSubscriptionDAO($this->tablePrefix);
        $this->eventDao = new \ksfraser\FrontAccounting\Square\DAO\WebhookEventDAO($this->tablePrefix);
        
        $this->webhookService = new \ksfraser\FrontAccounting\Square\Services\WebhookService(
            $client,
            $this->subscriptionDao,
            get_current_webhook_url()
        );
    }

    /**
     * Displays webhook management dashboard
     */
    public function index(): void
    {
        $this->ensureTablesExist();
        
        try {
            $subscriptions = $this->webhookService->listSubscriptions();
            $events = $this->eventDao->getAllEvents(50, false);
            $failedEvents = $this->eventDao->getFailedEvents(20);
            $statistics = $this->eventDao->getEventStatistics();
            
            display_webhook_dashboard($subscriptions, $events, $failedEvents, $statistics);
            
        } catch (\Exception $e) {
            display_error_message("Failed to load webhook data: " . $e->getMessage());
        }
    }

    /**
     * Displays webhook subscription creation form
     */
    public function create(): void
    {
        $this->ensureTablesExist();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $url = $_POST['url'] ?? '';
                $events = $_POST['events'] ?? [];
                
                if (empty($url) || empty($events)) {
                    throw new \Exception("URL and events are required");
                }
                
                // Validate URL format
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new \Exception("Invalid webhook URL format");
                }
                
                // Validate events
                $validEvents = ['payment.created', 'order.created', 'customer.created', 'customer.updated'];
                foreach ($events as $event) {
                    if (!in_array($event, $validEvents)) {
                        throw new \Exception("Invalid webhook event: " . $event);
                    }
                }
                
                // Create subscription
                $subscription = $this->webhookService->createSubscription($url, $events);
                
                display_success_message("Webhook subscription created successfully");
                display_webhook_subscription($subscription);
                
            } catch (\Exception $e) {
                display_error_message("Failed to create webhook subscription: " . $e->getMessage());
                display_webhook_create_form($_POST['url'] ?? '', $_POST['events'] ?? []);
            }
        } else {
            display_webhook_create_form();
        }
    }

    /**
     * Displays webhook subscription edit form
     */
    public function edit(string $id): void
    {
        $this->ensureTablesExist();
        
        try {
            // Get subscription details from our database
            $subscription = $this->subscriptionDao->getBySquareId($id);
            
            if (!$subscription) {
                throw new \Exception("Webhook subscription not found");
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $url = $_POST['url'] ?? $subscription['url'];
                $events = $_POST['events'] ?? json_decode($subscription['events'], true);
                $isActive = isset($_POST['is_active']);
                
                // Create updated subscription object
                $updatedSubscription = new \Square\Models\WebhookSubscription();
                $updatedSubscription->setId($id);
                $updatedSubscription->setNotificationUrl($url);
                $updatedSubscription->setEventTypes(array_map(function($event) {
                    return \Square\Models\WebhookEventType::from($event);
                }, $events));
                $updatedSubscription->setEnabled($isActive);
                
                // Update subscription
                $this->webhookService->updateSubscription($id, $updatedSubscription);
                
                display_success_message("Webhook subscription updated successfully");
                display_webhook_subscription($updatedSubscription);
                
            } else {
                display_webhook_edit_form($subscription);
            }
            
        } catch (\Exception $e) {
            display_error_message("Failed to edit webhook subscription: " . $e->getMessage());
        }
    }

    /**
     * Deletes a webhook subscription
     */
    public function delete(string $id): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new \Exception("POST method required");
            }
            
            $this->webhookService->deleteSubscription($id);
            
            display_success_message("Webhook subscription deleted successfully");
            redirect_to_webhook_management();
            
        } catch (\Exception $e) {
            display_error_message("Failed to delete webhook subscription: " . $e->getMessage());
        }
    }

    /**
     * Displays webhook event logs
     */
    public function events(): void
    {
        $this->ensureTablesExist();
        
        try {
            $events = $this->eventDao->getAllEvents(100, false);
            $failedEvents = $this->eventDao->getFailedEvents(50);
            $statistics = $this->eventDao->getEventStatistics();
            
            display_webhook_events($events, $failedEvents, $statistics);
            
        } catch (\Exception $e) {
            display_error_message("Failed to load webhook events: " . $e->getMessage());
        }
    }

    /**
     * Displays webhook event details
     */
    public function eventDetail(string $eventId): void
    {
        try {
            $event = $this->eventDao->getEventById($eventId);
            
            if (!$event) {
                throw new \Exception("Event not found");
            }
            
            display_webhook_event_detail($event);
            
        } catch (\Exception $e) {
            display_error_message("Failed to load event details: " . $e->getMessage());
        }
    }

    /**
     * Manually retries failed events
     */
    public function retryEvents(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \Exception("POST method required");
        }
        
        try {
            $eventIds = $_POST['event_ids'] ?? [];
            
            if (empty($eventIds)) {
                throw new \Exception("No events selected");
            }
            
            $results = [];
            foreach ($eventIds as $eventId) {
                try {
                    $event = $this->eventDao->getEventById($eventId);
                    if ($event) {
                        // Retry processing the event
                        $this->webhookService->handleWebhookEvent(
                            json_decode($event['event_data'], true),
                            'retry_signature' // In real implementation, use actual signature
                        );
                        
                        $results[] = ['success' => true, 'event_id' => $eventId];
                    }
                } catch (\Exception $e) {
                    $results[] = ['success' => false, 'event_id' => $eventId, 'error' => $e->getMessage()];
                }
            }
            
            display_retry_results($results);
            
        } catch (\Exception $e) {
            display_error_message("Failed to retry events: " . $e->getMessage());
        }
    }

    /**
     * Cleans up old webhook events
     */
    public function cleanup(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \Exception("POST method required");
        }
        
        try {
            $daysToKeep = (int)($_POST['days_to_keep'] ?? 90);
            $deletedCount = $this->eventDao->cleanupOldEvents($daysToKeep);
            
            display_success_message("Deleted {$deletedCount} old webhook events");
            
        } catch (\Exception $e) {
            display_error_message("Failed to cleanup events: " . $e->getMessage());
        }
    }

    /**
     * Ensures required database tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->subscriptionDao->ensureTableExists();
            $this->eventDao->ensureTableExists();
        } catch (\Exception $e) {
            throw new \Exception("Database setup failed: " . $e->getMessage());
        }
    }

    /**
     * Gets current webhook URL
     */
    private function getWebhookUrl(): string
    {
        $baseUrl = get_current_base_url();
        return $baseUrl . '/pages/webhook.php';
    }
}

// Utility functions for display
function display_webhook_dashboard(array $subscriptions, array $events, array $failedEvents, array $statistics): void
{
    $title = _("Webhook Management Dashboard");
    $page = _("Webhooks");
    
    start_form();
    
    display_heading($title);
    
    // Statistics cards
    display_stat_card(_("Total Subscriptions"), count($subscriptions));
    display_stat_card(_("Total Events"), $statistics['total_events']);
    display_stat_card(_("Failed Events"), $statistics['failed_events']);
    display_stat_card(_("Success Rate"), $statistics['success_rate'] . '%');
    
    // Subscription list
    display_subscriptions_list($subscriptions);
    
    // Recent events
    display_recent_events($events, $failedEvents);
    
    end_form();
}

function display_webhook_create_form(?string $url = '', array $events = []): void
{
    $title = _("Create Webhook Subscription");
    
    start_form();
    
    display_heading($title);
    
    display_field(_("Webhook URL:"), 'url', $url, "url", null, "Enter the URL where Square will send webhook events");
    
    display_checkbox(_("payment.created"), "events[]", in_array('payment.created', $events));
    display_checkbox(_("order.created"), "events[]", in_array('order.created', $events));
    display_checkbox(_("customer.created"), "events[]", in_array('customer.created', $events));
    display_checkbox(_("customer.updated"), "events[]", in_array('customer.updated', $events));
    
    display_submit(_("Create Subscription"));
    
    end_form();
}

function display_webhook_edit_form(array $subscription): void
{
    $title = _("Edit Webhook Subscription");
    
    start_form();
    
    display_heading($title);
    
    $events = json_decode($subscription['events'], true);
    
    display_field(_("Webhook URL:"), 'url', $subscription['url'], "url");
    
    display_checkbox(_("payment.created"), "events[]", in_array('payment.created', $events));
    display_checkbox(_("order.created"), "events[]", in_array('order.created', $events));
    display_checkbox(_("customer.created"), "events[]", in_array('customer.created', $events));
    display_checkbox(_("customer.updated"), "events[]", in_array('customer.updated', $events));
    
    display_checkbox(_("Active"), "is_active", $subscription['is_active']);
    
    display_submit(_("Update Subscription"));
    display_button(_("Delete"), "delete", "button", "onclick=\"confirmDelete()\"");
    
    end_form();
    
    add_script('
        function confirmDelete() {
            if (confirm("Are you sure you want to delete this subscription?")) {
                document.querySelector("form").action = "?action=delete&id=' . $subscription['square_id'] . '";
                document.querySelector("form").submit();
            }
        }
    ');
}

function display_webhook_events(array $events, array $failedEvents, array $statistics): void
{
    $title = _("Webhook Event Logs");
    
    start_form();
    
    display_heading($title);
    
    // Filter controls
    display_field(_("Filter by type:"), "event_type", "", "select", "", "", 
        ['' => 'All', 'payment.created' => 'Payment Created', 'order.created' => 'Order Created']);
    
    display_field(_("Days to keep:"), "days_to_keep", "90", "number");
    display_submit(_("Apply Filters"));
    
    // Statistics
    display_event_statistics($statistics);
    
    // Events list
    display_events_list($events, $failedEvents);
    
    end_form();
}

function display_retry_results(array $results): void
{
    $title = _("Event Retry Results");
    
    start_form();
    
    display_heading($title);
    
    $successCount = count(array_filter($results, fn($r) => $r['success']));
    $failedCount = count($results) - $successCount;
    
    display_info_message(_("Retry completed: ") . $successCount . _(" successful, ") . $failedCount . _(" failed"));
    
    if ($failedCount > 0) {
        display_error_message(_("Failed events may need manual intervention"));
    }
    
    end_form();
}

function display_subscriptions_list(array $subscriptions): void
{
    display_section_heading(_("Active Subscriptions"));
    
    if (empty($subscriptions)) {
        display_info_message(_("No active webhook subscriptions found"));
    } else {
        start_table();
        table_header(_("URL"), _("Events"), _("Status"), _("Actions"));
        
        foreach ($subscriptions as $subscription) {
            $events = implode(', ', array_map(function($event) {
                return \Square\Models\WebhookEventType::name($event);
            }, $subscription->getEventTypes()));
            
            row(
                $subscription->getNotificationUrl(),
                $events,
                $subscription->getEnabled() ? _("Active") : _("Inactive"),
                "<a href='?action=edit&id=" . $subscription->getId() . "'>" . _("Edit") . "</a>"
            );
        }
        
        end_table();
    }
}

function display_recent_events(array $events, array $failedEvents): void
{
    display_section_heading(_("Recent Events"));
    
    if (empty($events)) {
        display_info_message(_("No recent events"));
    } else {
        start_table();
        table_header(_("Event ID"), _("Type"), _("Processed"), _("Status"), _("Actions"));
        
        foreach (array_slice($events, 0, 10) as $event) {
            $status = $event['processed_successfully'] ? 
                "<span class='badge success'>" . _("Success") . "</span>" : 
                "<span class='badge error'>" . _("Failed") . "</span>";
            
            row(
                $event['event_id'],
                $event['event_type'],
                $event['processed_at'],
                $status,
                "<a href='?action=event&id=" . $event['event_id'] . "'>" . _("View") . "</a>"
            );
        }
        
        end_table();
    }
}

function display_events_list(array $events, array $failedEvents): void
{
    start_table();
    table_header(_("Event ID"), _("Type"), _("Processed"), _("Status"), _("Actions"));
    
    foreach ($events as $event) {
        $statusClass = $event['processed_successfully'] ? 'success' : 'error';
        $statusText = $event['processed_successfully'] ? 'Success' : 'Failed';
        
        if (!$event['processed_successfully']) {
            $statusText .= ' - ' . substr($event['error_message'], 0, 100) . '...';
        }
        
        row(
            $event['event_id'],
            $event['event_type'],
            $event['processed_at'],
            "<span class='badge {$statusClass}'>{$statusText}</span>",
            "<a href='?action=event&id=" . $event['event_id'] . "'>" . _("View") . "</a>"
        );
    }
    
    end_table();
}

function display_event_statistics(array $statistics): void
{
    display_section_heading(_("Event Statistics"));
    
    display_info_message(_("Total Events: ") . $statistics['total_events']);
    display_info_message(_("Successful Events: ") . $statistics['successful_events']);
    display_info_message(_("Failed Events: ") . $statistics['failed_events']);
    display_info_message(_("Success Rate: ") . $statistics['success_rate'] . '%');
    
    if (!empty($statistics['events_by_type'])) {
        display_section_heading(_("Events by Type"));
        foreach ($statistics['events_by_type'] as $type => $count) {
            display_info_message(_($type) . ": " . $count);
        }
    }
}

function display_webhook_subscription(\Square\Models\WebhookSubscription $subscription): void
{
    start_table();
    table_header(_("Property"), _("Value"));
    
    row(_("Subscription ID"), $subscription->getId());
    row(_("URL"), $subscription->getNotificationUrl());
    row(_("Events"), implode(', ', array_map(function($event) {
        return \Square\Models\WebhookEventType::name($event);
    }, $subscription->getEventTypes())));
    row(_("Status"), $subscription->getEnabled() ? _("Active") : _("Inactive"));
    row(_("Created"), $subscription->getCreatedAt());
    
    end_table();
}

// Route handling
$action = $_GET['action'] ?? 'index';
$id = $_GET['id'] ?? '';

$controller = new WebhookManagementController();

switch ($action) {
    case 'index':
        $controller->index();
        break;
    case 'create':
        $controller->create();
        break;
    case 'edit':
        $controller->edit($id);
        break;
    case 'delete':
        $controller->delete($id);
        break;
    case 'events':
        $controller->events();
        break;
    case 'event':
        $controller->eventDetail($id);
        break;
    case 'retry':
        $controller->retryEvents();
        break;
    case 'cleanup':
        $controller->cleanup();
        break;
    default:
        $controller->index();
        break;
}
?>