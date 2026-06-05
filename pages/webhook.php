<?php
declare(strict_types=1);

// Webhook endpoint handler for Square events
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Initialize required services
        $client = \Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory::create(get_current_environment());
        $subscriptionDao = new \Ksfraser\Frontaccounting\SquareUp\DAO\WebhookSubscriptionDAO(get_company_pref('table_prefix'));
        $eventDao = new \Ksfraser\Frontaccounting\SquareUp\DAO\WebhookEventDAO(get_company_pref('table_prefix'));
        
        $webhookService = new \Ksfraser\Frontaccounting\SquareUp\Services\WebhookService(
            $client,
            $subscriptionDao,
            get_current_webhook_url()
        );
        
        // Get webhook data
        $input = file_get_contents('php://input');
        $eventData = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON input");
        }
        
        // Extract signature from headers
        $signature = $_SERVER['HTTP_X_SQUARE_SIGNATURE'] ?? '';
        
        // Process webhook event
        $webhookService->handleWebhookEvent($eventData, $signature);
        
        // Send success response
        http_response_code(200);
        echo 'OK';
        
    } catch (\Ksfraser\Frontaccounting\SquareUp\Exceptions\WebhookValidationException $e) {
        // Validation error - bad request
        http_response_code(400);
        error_log("Webhook validation error: " . $e->getMessage());
        echo 'Validation Error: ' . $e->getMessage();
        
    } catch (\Ksfraser\Frontaccounting\SquareUp\Exceptions\WebhookCreationException $e) {
        // Processing error - internal server error
        http_response_code(500);
        error_log("Webhook processing error: " . $e->getMessage());
        echo 'Processing Error: ' . $e->getMessage();
        
    } catch (\Exception $e) {
        // Generic error
        http_response_code(500);
        error_log("Webhook error: " . $e->getMessage());
        echo 'Error: ' . $e->getMessage();
    }
} else {
    // Method not allowed
    http_response_code(405);
    echo 'Method Not Allowed';
}
?>