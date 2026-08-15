<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit\Services;

use ksfraser\FrontAccounting\Square\Services\WebhookService;
use ksfraser\FrontAccounting\Square\DAO\WebhookSubscriptionDAO;
use Square\SquareClient;
use ksfraser\FrontAccounting\Square\Exceptions\WebhookCreationException;
use ksfraser\FrontAccounting\Square\Exceptions\WebhookValidationException;
use PHPUnit\Framework\TestCase;
use Square\Models\WebhookSubscription;
use Square\Models\WebhookEventType;
use Square\Exceptions\ApiException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for WebhookService.
 * 
 * @UML Note: Test coverage in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookServiceTest extends TestCase
{
    protected MockObject $mockSquareClient;
    protected MockObject $mockSubscriptionDao;
    protected WebhookService $webhookService;
    protected string $tablePrefix = '0_';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Square client interface
        $this->mockSquareClient = $this->createMock(SquareClient::class);
        
        // Mock subscription DAO
        $this->mockSubscriptionDao = $this->createMock(WebhookSubscriptionDAO::class);
        
        // Create webhook service
        $this->webhookService = new WebhookService(
            $this->mockSquareClient,
            $this->mockSubscriptionDao,
            'https://example.com/webhook'
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testGetCurrentEnvironmentReadsFromSettings(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];
        $GLOBALS['__fa_table'][] = ['name' => 'environment', 'value' => 'production'];

        $this->assertSame('production', WebhookService::getCurrentEnvironment());
    }

    public function testGetCurrentEnvironmentDefaultsToSandbox(): void
    {
        $GLOBALS['__fa_table'] = [];
        $GLOBALS['__fa_result_set'] = [];
        $GLOBALS['__fa_result_pos'] = [];

        $this->assertSame('sandbox', WebhookService::getCurrentEnvironment());
    }

    public function testGetCurrentWebhookUrl(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/modules/ksf_FA_Square/pages/webhook.php';

        $this->assertSame(
            'https://example.com/modules/ksf_FA_Square/pages/webhook.php',
            WebhookService::getCurrentWebhookUrl()
        );

        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
    }

    public function testGetCurrentWebhookUrlFallsBackToHttp(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'square.local';
        $_SERVER['SCRIPT_NAME'] = '/modules/ksf_FA_Square/pages/webhook.php';

        $this->assertSame(
            'http://square.local/modules/ksf_FA_Square/pages/webhook.php',
            WebhookService::getCurrentWebhookUrl()
        );

        unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
    }

    /**
     * @test
     */
    public function canCreateSubscriptionSuccessfully(): void
    {
        // Arrange
        $url = 'https://example.com/webhook';
        $events = ['payment.created', 'order.created'];
        
        // Mock the API response
        $mockApi = $this->createMock(\Square\Apis\WebhookSubscriptionsApi::class);
        $mockSubscription = new WebhookSubscription();
        $mockSubscription->setId('sub_123456');
        $mockSubscription->setNotificationUrl($url);
        $mockSubscription->setSignatureKey('test_signature_key');
        $mockSubscription->setEventTypes([
            WebhookEventType::PAYMENT_CREATED,
            WebhookEventType::ORDER_CREATED,
        ]);
        $mockSubscription->setEnabled(true);
        
        $mockCreateResponse = $this->createMock(\Square\Models\CreateWebhookSubscriptionResponse::class);
        $mockCreateResponse->method('getSubscription')->willReturn($mockSubscription);

        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockResult->method('getResult')->willReturn($mockCreateResponse);
        
        $mockApi->method('createWebhookSubscription')->willReturn($mockResult);
        
        $this->mockSquareClient->method('getWebhookSubscriptionsApi')->willReturn($mockApi);
        
        // Mock DAO insert
        $this->mockSubscriptionDao->expects($this->once())
            ->method('insertSubscription')
            ->with($this->callback(function ($data) {
                return $data['square_id'] === 'sub_123456'
                    && $data['url'] === 'https://example.com/webhook'
                    && $data['events'] === json_encode(['payment.created', 'order.created'])
                    && $data['signature_key'] === 'test_signature_key'
                    && $data['is_active'] === true
                    && is_string($data['created_at'] ?? null);
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->webhookService->createSubscription($url, $events);
        
        // Assert
        $this->assertInstanceOf(WebhookSubscription::class, $result);
        $this->assertEquals('sub_123456', $result->getId());
        $this->assertEquals($url, $result->getNotificationUrl());
    }

    /**
     * @test
     */
    public function createSubscriptionFailsWithInvalidUrl(): void
    {
        $this->expectException(WebhookValidationException::class);
        $this->expectExceptionMessage("Invalid webhook URL format");
        
        // Act
        $this->webhookService->createSubscription('invalid-url', ['payment.created']);
    }

    /**
     * @test
     */
    public function createSubscriptionFailsWithInvalidEvent(): void
    {
        $this->expectException(WebhookValidationException::class);
        $this->expectExceptionMessage("Invalid webhook event type: invalid.event");
        
        // Act
        $this->webhookService->createSubscription('https://example.com/webhook', ['payment.created', 'invalid.event']);
    }

    /**
     * @test
     */
    public function createSubscriptionFailsWithApiError(): void
    {
        $this->expectException(WebhookCreationException::class);
        $this->expectExceptionMessage("Square API error creating webhook subscription");
        
        // Arrange
        $url = 'https://example.com/webhook';
        $events = ['payment.created'];
        
        // Mock API failure
        $mockApi = $this->createMock(\Square\Apis\WebhookSubscriptionsApi::class);
        $mockRequest = new \Square\Http\HttpRequest('POST', [], 'https://example.com/webhook');
        $mockApi->method('createWebhookSubscription')
            ->willThrowException(new ApiException("API error", $mockRequest, null));
        
        $this->mockSquareClient->method('getWebhookSubscriptionsApi')->willReturn($mockApi);
        
        // Act
        $this->webhookService->createSubscription($url, $events);
    }

    /**
     * @test
     */
    public function canListSubscriptionsSuccessfully(): void
    {
        // Arrange
        $mockSubscription1 = new WebhookSubscription();
        $mockSubscription1->setId('sub_123');
        
        $mockSubscription2 = new WebhookSubscription();
        $mockSubscription2->setId('sub_456');
        
        $mockApi = $this->createMock(\Square\Apis\WebhookSubscriptionsApi::class);
        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        $mockListResponse = $this->createMock(\Square\Models\ListWebhookSubscriptionsResponse::class);
        $mockListResponse->method('getSubscriptions')->willReturn([$mockSubscription1, $mockSubscription2]);
        $mockResult->method('getResult')->willReturn($mockListResponse);
        
        $mockApi->method('listWebhookSubscriptions')->willReturn($mockResult);
        
        $this->mockSquareClient->method('getWebhookSubscriptionsApi')->willReturn($mockApi);
        
        // Act
        $result = $this->webhookService->listSubscriptions();
        
        // Assert
        $this->assertCount(2, $result);
        $this->assertInstanceOf(WebhookSubscription::class, $result[0]);
        $this->assertEquals('sub_123', $result[0]->getId());
    }

    /**
     * @test
     */
    public function canDeleteSubscriptionSuccessfully(): void
    {
        // Arrange
        $subscriptionId = 'sub_123456';
        
        // Mock API response
        $mockApi = $this->createMock(\Square\Apis\WebhookSubscriptionsApi::class);
        $mockResult = $this->createMock(\Square\Http\ApiResponse::class);
        $mockResult->method('isSuccess')->willReturn(true);
        
        $mockApi->method('deleteWebhookSubscription')
            ->with($subscriptionId)
            ->willReturn($mockResult);
        
        $this->mockSquareClient->method('getWebhookSubscriptionsApi')->willReturn($mockApi);
        
        // Mock DAO delete
        $this->mockSubscriptionDao->expects($this->once())
            ->method('deleteSubscription')
            ->with($subscriptionId);
        
        // Act
        $result = $this->webhookService->deleteSubscription($subscriptionId);
        
        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function handleWebhookEventSucceedsWithValidSignature(): void
    {
        // Arrange
        $eventData = [
            'type' => 'payment.created',
            'event_id' => 'evt_123456',
            'created_at' => '2023-01-01T00:00:00Z',
            'data' => [
                'payment' => [
                    'id' => 'pay_123456',
                    'amount_money' => [
                        'amount' => 1000,
                        'currency' => 'USD'
                    ]
                ]
            ]
        ];
        
        $signature = hash_hmac('sha256', json_encode($eventData), 'test_secret');
        
        // Mock DAO log
        $this->mockSubscriptionDao->expects($this->once())
            ->method('logEvent')
            ->with($this->callback(function ($data) {
                return $data['event_id'] === 'evt_123456'
                    && $data['event_type'] === 'payment.created'
                    && is_string($data['event_data'] ?? null)
                    && is_string($data['processed_at'] ?? null);
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->webhookService->handleWebhookEvent($eventData, $signature);
        
        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function handleWebhookEventFailsWithInvalidSignature(): void
    {
        $this->expectException(WebhookValidationException::class);
        $this->expectExceptionMessage("Invalid webhook signature");
        
        // Arrange
        $eventData = [
            'type' => 'payment.created',
            'event_id' => 'evt_123456',
            'created_at' => '2023-01-01T00:00:00Z'
        ];
        
        $invalidSignature = 'invalid_signature';
        
        // Act
        $this->webhookService->handleWebhookEvent($eventData, $invalidSignature);
    }

    /**
     * @test
     */
    public function handleWebhookEventFailsWithInvalidEventStructure(): void
    {
        $this->expectException(WebhookValidationException::class);
        $this->expectExceptionMessage("Invalid webhook event structure");
        
        // Arrange
        $eventData = [
            'event_id' => 'evt_123456',
            'created_at' => '2023-01-01T00:00:00Z'
            // Missing 'type' field
        ];
        
        $signature = hash_hmac('sha256', json_encode($eventData), 'test_secret');
        
        // Act
        $this->webhookService->handleWebhookEvent($eventData, $signature);
    }

    /**
     * @test
     */
    public function handlePaymentCreatedEventLogsCorrectly(): void
    {
        // This test verifies that payment created events are logged
        // In a real implementation, this would trigger import service
        
        $eventData = [
            'type' => 'payment.created',
            'event_id' => 'evt_123456',
            'created_at' => '2023-01-01T00:00:00Z',
            'data' => [
                'payment' => [
                    'id' => 'pay_123456',
                    'amount_money' => [
                        'amount' => 1000,
                        'currency' => 'USD'
                    ]
                ]
            ]
        ];
        
        $signature = hash_hmac('sha256', json_encode($eventData), 'test_secret');
        
        // Mock DAO log
        $this->mockSubscriptionDao->expects($this->once())
            ->method('logEvent')
            ->with($this->callback(function($arg) use ($eventData) {
                return $arg['event_type'] === 'payment.created' && 
                       $arg['event_id'] === 'evt_123456';
            }))
            ->willReturn(1);
        
        // Act
        $result = $this->webhookService->handleWebhookEvent($eventData, $signature);
        
        // Assert
        $this->assertTrue($result);
    }
}
