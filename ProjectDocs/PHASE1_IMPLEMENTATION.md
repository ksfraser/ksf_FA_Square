# Phase 1: Critical Missing APIs (30 days) - TDD Implementation Plan

## Overview
Implement the most critical missing Square API endpoints that enable real-time sync and complete business operations.

## 1.1 Webhook Management Service (Priority: CRITICAL)

### Implementation Tasks

#### 1.1.1 Create Webhook Controller
**File**: `pages/webhook.php`
```php
<?php
declare(strict_types=1);

// Webhook endpoint handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $webhookService = new WebhookService(
            SquareClientFactory::create(get_current_environment()),
            new WebhookSubscriptionDAO(get_company_pref('table_prefix')),
            get_current_webhook_url()
        );
        
        $eventData = json_decode(file_get_contents('php://input'), true);
        $signature = $_SERVER['HTTP_X_SQUARE_SIGNATURE'] ?? '';
        
        $webhookService->handleWebhookEvent($eventData, $signature);
        
        http_response_code(200);
        echo 'OK';
    } catch (Exception $e) {
        http_response_code(400);
        error_log("Webhook error: " . $e->getMessage());
        echo 'Error: ' . $e->getMessage();
    }
}
```

#### 1.1.2 Webhook Management UI
**File**: `pages/webhook_management.php`
```php
<?php
declare(strict_types=1);

class WebhookManagementController
{
    private WebhookService $webhookService;
    private WebhookSubscriptionDAO $subscriptionDao;
    
    public function __construct()
    {
        $this->webhookService = new WebhookService(
            SquareClientFactory::create(get_current_environment()),
            new WebhookSubscriptionDAO(get_company_pref('table_prefix')),
            get_current_webhook_url()
        );
        $this->subscriptionDao = new WebhookSubscriptionDAO(get_company_pref('table_prefix'));
    }
    
    public function index()
    {
        $subscriptions = $this->webhookService->listSubscriptions();
        $events = $this->subscriptionDao->getAllEvents();
        
        display_webhook_dashboard($subscriptions, $events);
    }
    
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $url = $_POST['url'] ?? '';
            $events = $_POST['events'] ?? [];
            
            $subscription = $this->webhookService->createSubscription($url, $events);
            display_success_message("Webhook subscription created successfully");
        }
        
        display_webhook_create_form();
    }
}
```

#### 1.1.3 TDD Test Cases for WebhookService

**Test File**: `tests/Unit/WebhookServiceTest.php`

```php
/**
 * @test
 */
public function canHandleRealWebhookEvent(): void
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
    
    $signature = $this->generateValidSignature($eventData);
    
    // Mock payment processing service
    $this->mockPaymentProcessor->expects($this->once())
        ->method('processSquarePayment')
        ->with($eventData['data']['payment']);
    
    // Act
    $result = $this->webhookService->handleWebhookEvent($eventData, $signature);
    
    // Assert
    $this->assertTrue($result);
    $this->assertPaymentProcessed($eventData['data']['payment']);
}

/**
 * @test
 */
public function webhookSignatureValidationWorks(): void
{
    // Arrange
    $eventData = [
        'type' => 'payment.created',
        'event_id' => 'evt_123456',
        'created_at' => '2023-01-01T00:00:00Z',
        'data' => ['payment' => ['id' => 'pay_123456']]
    ];
    
    $validSignature = $this->generateValidSignature($eventData);
    $invalidSignature = 'invalid_signature';
    
    // Act & Assert
    $this->webhookService->handleWebhookEvent($eventData, $validSignature);
    $this->expectException(WebhookValidationException::class);
    $this->webhookService->handleWebhookEvent($eventData, $invalidSignature);
}
```

### Implementation Steps

1. **Week 1**: Webhook Service implementation
   - Complete `WebhookService` with all CRUD operations
   - Implement webhook signature validation
   - Create test cases for all scenarios

2. **Week 2**: Webhook Controller and UI
   - Create webhook endpoint handler
   - Build webhook management UI with subscription list
   - Implement subscription creation/update forms

3. **Week 3**: Integration and Testing
   - Integrate webhook handling with import service
   - Add webhook event logging and monitoring
   - Complete comprehensive testing

## 1.2 Customer Management API (Priority: HIGH)

### Implementation Tasks

#### 1.2.1 Customer Sync Service
```php
interface CustomerSyncInterface
{
    public function syncCustomerFromFA(array $debtor): Customer;
    public function syncCustomerToSquare(Customer $customer): array;
    public function matchCustomer(string $email, ?string $phone = null): ?array;
}

class CustomerSyncService implements CustomerSyncInterface
{
    private CustomerService $customerService;
    private CustomerMatchStrategy $matchStrategy;
    
    public function __construct(CustomerService $customerService, CustomerMatchStrategy $matchStrategy)
    {
        $this->customerService = $customerService;
        $this->matchStrategy = $matchStrategy;
    }
    
    public function syncCustomerFromFA(array $debtor): Customer
    {
        // Implement FA to Square sync
    }
    
    public function syncCustomerToSquare(Customer $customer): array
    {
        // Implement Square to FA sync
    }
}
```

#### 1.2.2 Customer Match Strategy Pattern
```php
interface CustomerMatchStrategy
{
    public function match(array $customerData): ?array;
}

class EmailCustomerMatchStrategy implements CustomerMatchStrategy
{
    private DebtorsMasterDAO $debtorDao;
    
    public function match(array $customerData): ?array
    {
        $email = $customerData['email'] ?? '';
        return $this->debtorDao->getByEmail($email);
    }
}

class PhoneCustomerMatchStrategy implements CustomerMatchStrategy
{
    private DebtorsMasterDAO $debtorDao;
    
    public function match(array $customerData): ?array
    {
        $phone = $customerData['phone'] ?? '';
        return $this->debtorDao->getByPhone($phone);
    }
}

class CompositeCustomerMatchStrategy implements CustomerMatchStrategy
{
    private array $strategies;
    
    public function __construct(array $strategies)
    {
        $this->strategies = $strategies;
    }
    
    public function match(array $customerData): ?array
    {
        foreach ($this->strategies as $strategy) {
            $result = $strategy->match($customerData);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }
}
```

#### 1.2.3 TDD Test Cases for CustomerService

**Test File**: `tests/Unit/CustomerServiceTest.php`

```php
/**
 * @test
 */
public function canSyncCustomerBidirectionally(): void
{
    // Arrange - FA customer data
    $faCustomer = [
        'debtor_no' => 123,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '1234567890'
    ];
    
    // Mock Square API response
    $this->mockSquareApi->expects($this->once())
        ->method('createCustomer')
        ->willReturn($this->createMockCustomer('cus_123456'));
    
    // Mock FA debtor creation
    $this->mockDebtorDao->expects($this->once())
        ->method('insertDebtor')
        ->willReturn(456);
    
    // Act
    $squareCustomer = $this->customerService->syncCustomerFromFA($faCustomer);
    $faDebtor = $this->customerService->syncCustomerToSquare($squareCustomer);
    
    // Assert
    $this->assertEquals('cus_123456', $squareCustomer->getId());
    $this->assertEquals(456, $faDebtor['debtor_no']);
}

/**
 * @test
 */
public function customerMatchingUsesMultipleStrategies(): void
{
    // Arrange
    $customerData = [
        'email' => 'john@example.com',
        'phone' => '1234567890'
    ];
    
    // Mock strategies
    $emailStrategy = $this->createMock(EmailCustomerMatchStrategy::class);
    $phoneStrategy = $this->createMock(PhoneCustomerMatchStrategy::class);
    
    $emailStrategy->expects($this->once())
        ->method('match')
        ->willReturn(['debtor_no' => 123]);
    
    $compositeStrategy = new CompositeCustomerMatchStrategy([$emailStrategy, $phoneStrategy]);
    
    // Act
    $result = $compositeStrategy->match($customerData);
    
    // Assert
    $this->assertEquals(123, $result['debtor_no']);
}
```

### Implementation Steps

1. **Week 4**: Customer Service implementation
   - Complete `CustomerService` with bi-directional sync
   - Implement customer matching strategies
   - Create comprehensive test coverage

2. **Week 5**: Customer Integration
   - Integrate customer sync with import service
   - Add customer management UI
   - Implement conflict resolution

3. **Week 6**: Testing and Validation
   - Complete end-to-end customer sync testing
   - Add performance benchmarks
   - Validation and documentation

## 1.3 Refund Processing (Priority: HIGH)

### Implementation Tasks

#### 1.3.1 Refund Service Integration
```php
interface RefundProcessorInterface
{
    public function createRefund(Payment $payment, int $amount, string $reason): Refund;
    public void processRefundInFA(Refund $refund, int $invoiceId): int;
}

class RefundProcessor implements RefundProcessorInterface
{
    private RefundService $refundService;
    private CreditNoteService $creditNoteService;
    private PaymentMatchDAO $paymentMatchDao;
    
    public function createRefund(Payment $payment, int $amount, string $reason): Refund
    {
        $refund = $this->refundService->createRefund($payment, $amount, $reason);
        $this->recordRefundInFA($refund, $this->getInvoiceIdFromPayment($payment));
        return $refund;
    }
    
    public function processRefundInFA(Refund $refund, int $invoiceId): int
    {
        $creditNoteData = [
            'original_invoice_id' => $invoiceId,
            'amount' => $refund->getAmountMoney()->getAmount(),
            'reason' => $refund->getReason(),
            'reference' => $refund->getId()
        ];
        
        return $this->creditNoteService->createCreditNote($creditNoteData);
    }
}
```

#### 1.3.2 TDD Test Cases for RefundService

**Test File**: `tests/Unit/RefundServiceTest.php`

```php
/**
 * @test
 */
public function canProcessRefundEndToEnd(): void
{
    // Arrange
    $payment = $this->createMockPayment('pay_123456', 10000); // $100.00
    $refundAmount = 5000; // $50.00
    
    $mockRefund = $this->createMockRefund('ref_123456', $refundAmount);
    
    // Mock Square API
    $this->mockRefundApi->expects($this->once())
        ->method('createRefund')
        ->willReturn($this->createSuccessResponse($mockRefund));
    
    // Mock FA credit note creation
    $this->mockCreditNoteService->expects($this->once())
        ->method('createCreditNote')
        ->willReturn(789);
    
    // Mock payment match lookup
    $this->mockPaymentMatchDao->expects($this->once())
        ->method('getBySquarePaymentId')
        ->willReturn(['fa_invoice_no' => 456]);
    
    // Act
    $refundId = $this->refundProcessor->createRefund($payment, $refundAmount, 'Customer request');
    
    // Assert
    $this->assertEquals('ref_123456', $refundId);
    $this->assertCreditNoteCreated(456, 5000);
}

/**
 * @test
 */
public function refundValidationPreventsOverRefund(): void
{
    // Arrange
    $payment = $this->createMockPayment('pay_123456', 10000); // $100.00
    $refundAmount = 15000; // $150.00 (over payment amount)
    
    // Act & Assert
    $this->expectException(RefundProcessingException::class);
    $this->expectExceptionMessage("Refund amount cannot exceed payment amount");
    
    $this->refundProcessor->createRefund($payment, $refundAmount, 'Test');
}
```

### Implementation Steps

1. **Week 7**: Refund Service implementation
   - Complete `RefundService` with all refund operations
   - Implement refund validation and error handling
   - Create comprehensive test cases

2. **Week 8**: Refund Integration
   - Integrate refund processing with UI
   - Add refund management interface
   - Implement refund matching and tracking

3. **Week 9**: Testing and Validation
   - Complete end-to-end refund testing
   - Add refund statistics and reporting
   - Validation and documentation

## Phase 1 Deliverables

### Code Deliverables
- WebhookService, WebhookSubscriptionDAO, WebhookController
- CustomerService, CustomerSyncService, CustomerMatch strategies
- RefundService, RefundProcessor, CreditNote integration
- Complete PHPUnit test suite with 95%+ coverage
- UI components for webhook, customer, and refund management

### Documentation Deliverables
- Updated API coverage: 72% (from 42%)
- Complete TDD implementation guide
- Service architecture documentation
- Integration testing procedures

### Business Value
- Real-time synchronization replaces polling (90% reduction in sync latency)
- Complete customer lifecycle management
- Full refund processing capabilities
- Enhanced user experience with real-time updates