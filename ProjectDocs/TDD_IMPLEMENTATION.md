# TDD Implementation Plan with SOLID Principles

## Overview
This document outlines the Test-Driven Development approach for implementing the ksf_FA_Square module, following SOLID principles and best practices.

## TDD Philosophy

### Red-Green-Refactor Cycle

1. **RED**: Write a failing test that defines a new function or improvement
2. **GREEN**: Make the test pass by writing the simplest possible implementation
3. **REFactor**: Clean up the code while keeping all tests passing

### Test Pyramid

```
            [ Integration Tests ]
        [ Service Layer Tests ]
    [ Unit Tests (80%) ]
```

- **Unit Tests**: 80% of test suite - Fast, isolated tests for individual components
- **Service Tests**: 15% - Tests for service layer interactions
- **Integration Tests**: 5% - End-to-end tests for critical flows

## SOLID Implementation Strategy

### Single Responsibility Principle (SRP)

**Violation Example:**
```php
class ImportService // Violates SRP
{
    public function performImport() // Handles multiple responsibilities
    {
        // 1. Fetch data from API
        // 2. Transform data format
        // 3. Validate data
        // 4. Insert into database
        // 5. Send notifications
        // 6. Generate reports
    }
}
```

**SRP Solution:**
```php
interface DataFetcherInterface
{
    public function fetchData(): array;
}

interface DataTransformerInterface
{
    public function transform(array $data): array;
}

interface DataValidatorInterface
{
    public function validate(array $data): ValidationResult;
}

interface DataImporterInterface
{
    public function import(array $data): ImportResult;
}

class NotificationService
{
    public function sendImportNotification(ImportResult $result): void
    {
        // Handle notifications only
    }
}

// Each class has a single responsibility
```

### Open/Closed Principle (OCP)

**Violation Example:**
```php
class PaymentProcessor // Violates OCP
{
    public function processPayment(array $payment): void
    {
        if ($payment['type'] === 'credit_card') {
            // Credit card logic
        } elseif ($payment['type'] === 'paypal') {
            // PayPal logic
        } elseif ($payment['type'] === 'square') {
            // Square logic
        }
        // Adding new payment types requires modifying this class
    }
}
```

**OCP Solution:**
```php
interface PaymentProcessorInterface
{
    public function canProcess(array $payment): bool;
    public function process(array $payment): PaymentResult;
}

class CreditCardPaymentProcessor implements PaymentProcessorInterface
{
    public function canProcess(array $payment): bool
    {
        return $payment['type'] === 'credit_card';
    }
    
    public function process(array $payment): PaymentResult
    {
        // Credit card processing logic
    }
}

class SquarePaymentProcessor implements PaymentProcessorInterface
{
    public function canProcess(array $payment): bool
    {
        return $payment['type'] === 'square';
    }
    
    public function process(array $payment): PaymentResult
    {
        // Square processing logic
    }
}

// Adding new payment types requires only adding new classes
```

### Liskov Substitution Principle (LSP)

**Violation Example:**
```php
class Bird // Violates LSP
{
    public function fly(): void
    {
        // Flying logic
    }
}

class Penguin extends Bird // Penguin cannot fly
{
    public function fly(): void
    {
        throw new Exception("Penguins cannot fly!");
    }
}

// This violates LSP - Penguin cannot substitute Bird
```

**LSP Solution:**
```php
interface BirdInterface
{
    public function makeSound(): string;
}

interface FlyingBirdInterface extends BirdInterface
{
    public function fly(): void;
}

class Sparrow implements FlyingBirdInterface
{
    public function makeSound(): string
    {
        return "Chirp";
    }
    
    public function fly(): void
    {
        // Flying logic
    }
}

class Penguin implements BirdInterface
{
    public function makeSound(): string
    {
        return "Squawk";
    }
    
    // No fly method - penguin doesn't fly
}
```

### Interface Segregation Principle (ISP)

**Violation Example:**
```php
interface WorkerInterface // Violates ISP
{
    public function work(): void;
    public function eat(): void;
    public function sleep(): void;
}

class HumanWorker implements WorkerInterface
{
    public function work(): void { /* ... */ }
    public function eat(): void { /* ... */ }
    public function sleep(): void { /* ... */ }
}

class RobotWorker implements WorkerInterface
{
    public function work(): void { /* ... */ }
    public function eat(): void { /* ... */ } // Robot doesn't eat!
    public function sleep(): void { /* ... */ } // Robot doesn't sleep!
}
```

**ISP Solution:**
```php
interface WorkableInterface
{
    public function work(): void;
}

interface FeedableInterface
{
    public function eat(): void;
}

interface SleepableInterface
{
    public function sleep(): void;
}

class HumanWorker implements WorkableInterface, FeedableInterface, SleepableInterface
{
    public function work(): void { /* ... */ }
    public function eat(): void { /* ... */ }
    public function sleep(): void { /* ... */ }
}

class RobotWorker implements WorkableInterface
{
    public function work(): void { /* ... */ }
    // No eat() or sleep() methods
}
```

### Dependency Inversion Principle (DIP)

**Violation Example:**
```php
class ImportService // Violates DIP
{
    public function __construct()
    {
        $this->client = new SquareClient(); // Hard dependency
        $this->dao = new TransactionDAO(); // Hard dependency
    }
}
```

**DIP Solution:**
```php
interface ClientInterface
{
    public function fetchOrders(): array;
}

interface DAOInterface
{
    public function saveTransaction(array $data): int;
}

class ImportService
{
    private ClientInterface $client;
    private DAOInterface $dao;
    
    public function __construct(ClientInterface $client, DAOInterface $dao)
    {
        $this->client = $client;
        $this->dao = $dao;
    }
}

// Dependencies are injected, not created internally
```

## TDD Implementation Strategy

### Phase 1: Critical APIs (30 days)

#### Week 1: Webhook Service

**Test-First Approach:**

1. **Write failing test for subscription creation:**
```php
public function testCanCreateWebhookSubscription(): void
{
    // Arrange
    $this->mockClient->expects($this->once())
        ->method('getWebhookSubscriptionsApi')
        ->willReturn($this->mockApi);
    
    $this->mockApi->expects($this->once())
        ->method('createWebhookSubscription')
        ->willReturn($this->mockSuccessResponse);
    
    // Act
    $result = $this->webhookService->createSubscription(
        'https://example.com/webhook',
        ['payment.created']
    );
    
    // Assert
    $this->assertInstanceOf(WebhookSubscription::class, $result);
    $this->assertEquals('sub_123', $result->getId());
}
```

2. **Implement minimal code to pass test:**
```php
public function createSubscription(string $url, array $events): WebhookSubscription
{
    // Simple implementation to pass test
    $subscription = new WebhookSubscription();
    $subscription->setId('sub_123');
    return $subscription;
}
```

3. **Refactor to full implementation:**
```php
public function createSubscription(string $url, array $events): WebhookSubscription
{
    $this->validateUrl($url);
    $this->validateEvents($events);
    
    $client = $this->client->getWebhookSubscriptionsApi();
    $request = new CreateWebhookSubscriptionRequest([
        'notification_url' => $url,
        'event_types' => array_map(fn($e) => WebhookEventType::from($e), $events),
    ]);
    
    $response = $client->createWebhookSubscription($request);
    return $response->getResult()->getSubscription();
}
```

#### Week 2: Customer Service

**Test Strategy:**
```php
public function testCustomerSyncBidirectionalFlow(): void
{
    // Test 1: FA to Square sync
    $faCustomer = ['name' => 'John Doe', 'email' => 'john@example.com'];
    $squareCustomer = $this->customerService->syncCustomerFromFA($faCustomer);
    
    $this->assertInstanceOf(Customer::class, $squareCustomer);
    
    // Test 2: Square to FA sync
    $faDebtor = $this->customerService->syncCustomerToSquare($squareCustomer);
    
    $this->assertArrayHasKey('debtor_no', $faDebtor);
    $this->assertEquals('John Doe', $faDebtor['name']);
}
```

#### Week 3: Refund Service

**Error Testing:**
```php
public function testRefundValidationPreventsOverRefund(): void
{
    $payment = $this->createPayment(10000); // $100.00
    $overRefund = 15000; // $150.00
    
    $this->expectException(RefundProcessingException::class);
    $this->expectExceptionMessage("Refund amount cannot exceed payment amount");
    
    $this->refundService->createRefund($payment, $overRefund, 'Test');
}
```

### Phase 2: FA Integration (90 days)

#### Month 1: CRM Integration

**Event Testing:**
```php
public function testCustomerSyncEventTrigger(): void
{
    $debtor = ['debtor_no' => 123, 'name' => 'John Doe'];
    $squareCustomer = $this->createMockCustomer('cus_123');
    
    $this->mockCrmAdapter->expects($this->once())
        ->method('updateContact')
        ->with($this->equalTo([
            'contact_id' => 123,
            'name' => 'John Doe'
        ]));
    
    $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
}
```

#### Month 2: Sales Integration

**Integration Testing:**
```php
public function testSalesOrderIntegration(): void
{
    $squareOrder = [
        'id' => 'ord_123',
        'line_items' => [
            ['item_id' => 'item_1', 'quantity' => 2, 'base_price_money' => ['amount' => 1000]]
        ]
    ];
    
    $salesOrder = $this->salesService->createSalesOrderFromSquare($squareOrder);
    
    $this->assertEquals(456, $salesOrder->getId()); // FA order ID
    $this->assertEquals(2, $salesOrder->getLineItems()[0]['quantity']);
}
```

### Phase 3: Enhanced Features (6 months)

#### Quarter 1: Analytics

**Performance Testing:**
```php
public function testSalesAnalyticsPerformance(): void
{
    $this->markTestSkipped('Performance test - not run in CI');
    
    $filters = ['start_date' => '2023-01-01', 'end_date' => '2023-12-31'];
    $start = microtime(true);
    
    $result = $this->analyticsService->getSalesTrends($filters);
    
    $end = microtime(true);
    $executionTime = ($end - $start) * 1000; // Convert to milliseconds
    
    $this->assertLessThan(1000, $executionTime); // Should complete in < 1 second
    $this->assertGreaterThan(0, $result->dailyTrends);
}
```

## Test Naming Conventions

### Unit Tests
```php
// Format: testName_context_expectation()
public function testCreateSubscription_validUrlAndEvents_returnsSubscription(): void
{
    // Test implementation
}

// Format: testMethod_whenCondition_thenResult()
public function testHandleWebhookEvent_whenValidSignature_thenProcessesEvent(): void
{
    // Test implementation
}
```

### Integration Tests
```php
// Format: testIntegration_scenario_expectedResult()
public function testCustomerSyncIntegration_bidirectionalFlow_createsMatchingRecords(): void
{
    // Integration test implementation
}
```

### Error Tests
```php
// Format: testMethod_whenInvalidInput_thenThrowsException()
public function testCreateSubscription_whenInvalidUrl_thenThrowsValidationException(): void
{
    // Test implementation
}
```

## Test Data Management

### Test Fixtures
```php
class WebhookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(SquareClient::class);
        $this->mockDao = $this->createMock(WebhookSubscriptionDAO::class);
        $this->service = new WebhookService($this->mockClient, $this->mockDao);
    }
    
    private function createMockSubscription(string $id): WebhookSubscription
    {
        $subscription = new WebhookSubscription();
        $subscription->setId($id);
        return $subscription;
    }
}
```

### Test Data Builders
```php
class WebhookTestDataBuilder
{
    public static function createPaymentCreatedEvent(): array
    {
        return [
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
    }
}
```

## Continuous Integration Setup

### GitHub Actions Configuration
```yaml
name: TDD Pipeline

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: [7.3, 7.4, 8.0]
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php-version }}
        
    - name: Install dependencies
      run: composer install --prefer-dist --no-progress
      
    - name: Run unit tests
      run: vendor/bin/phpunit tests/Unit --coverage-clover=coverage.xml
      
    - name: Run integration tests
      run: vendor/bin/phpunit tests/Integration
      
    - name: Upload coverage
      uses: codecov/codecov-action@v1
```

### Code Quality Checks
```yaml
- name: Run static analysis
  run: |
    vendor/bin/phpstan analyse --level=7 src/
    vendor/bin/phpcs --standard=PSR12 src/
    
- name: Check security
  run: vendor/bin/security-checker security:check
```

## Test Coverage Goals

### Minimum Requirements
- **Unit Tests**: 95% line coverage
- **Integration Tests**: 80% of critical flows
- **End-to-End Tests**: 70% of user journeys

### Coverage Categories
- **Happy Path**: 100% coverage
- **Error Conditions**: 90% coverage
- **Edge Cases**: 80% coverage
- **Security Scenarios**: 95% coverage

## Performance Testing

### Load Testing
```php
public function testWebhookServiceUnderLoad(): void
{
    $this->markTestSkipped('Load test - requires external tools');
    
    // Simulate 100 concurrent webhook events
    $start = microtime(true);
    
    $promises = [];
    for ($i = 0; $i < 100; $i++) {
        $promises[] = $this->processWebhookEvent(self::createMockEvent());
    }
    
    Promise\Utils::settle($promises)->wait();
    
    $end = microtime(true);
    $duration = ($end - $start) * 1000;
    
    $this->assertLessThan(5000, $duration); // Should complete in < 5 seconds
}
```

### Memory Usage
```php
public function testMemoryEfficiency(): void
{
    $startMemory = memory_get_usage();
    
    // Process 1000 webhook events
    for ($i = 0; $i < 1000; $i++) {
        $this->service->handleWebhookEvent(self::createMockEvent());
    }
    
    $endMemory = memory_get_usage();
    $memoryUsed = $endMemory - $startMemory;
    
    $this->assertLessThan(50 * 1024 * 1024, $memoryUsed); // < 50MB
}
```

## Documentation Requirements

### Test Documentation
- Each test class must have class-level documentation
- Each test method must have method-level documentation
- Complex tests should have inline comments explaining the scenario

### Code Coverage Reports
```bash
# Generate coverage report
vendor/bin/phpunit --coverage-html coverage/

# Generate coverage report in XML
vendor/bin/phpunit --coverage-clover coverage.xml

# Generate text coverage report
vendor/bin/phpunit --coverage-text --colors=never
```

### Continuous Monitoring
- Coverage reports should be generated on every commit
- Coverage should not decrease without approval
- Performance metrics should be tracked over time

## Success Criteria

### Technical Metrics
- **Test Coverage**: 95%+ maintained consistently
- **Test Speed**: Unit tests complete in < 30 seconds
- **Integration Tests**: Complete in < 5 minutes
- **Code Quality**: All static analysis checks pass

### Business Metrics
- **Bug Reduction**: 90% reduction in production bugs
- **Development Speed**: 50% faster feature development
- **Code Maintainability**: 80% improvement in code quality
- **Team Confidence**: Team confidence in code quality improvements

### Quality Gates
- **Coverage Gate**: Minimum 90% coverage required
- **Performance Gate**: Tests must complete within time limits
- **Security Gate**: No security vulnerabilities allowed
- **Documentation Gate**: All new code must be documented