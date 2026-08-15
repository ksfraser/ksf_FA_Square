# Development Plan — ksf_FA_Square v2.0

## Executive Summary

This development plan outlines the implementation strategy for completing the ksf_FA_Square module, addressing missing Square API endpoints, and enhancing integration with FrontAccounting's native systems. The plan follows TDD principles, SOLID/DRY/DI architecture patterns, and prioritizes critical business requirements.

---

## Current Status & Gap Analysis

### ✅ **Completed Features (42% API Coverage)**
- Catalog Export (100% coverage)
- Terminal Payments (100% coverage) 
- Sales Import (90% coverage)
- Location Mapping (100% coverage)
- Two-Stage Import Workflow (100% coverage)
- Date Range Tracking (100% coverage)
- Review/Match Interface (100% coverage)
- Edit Functionality (90% coverage)

### 🚨 **Critical Missing Features (58% Gap)**

| Category | Impact | Priority | Implementation Phase |
|----------|--------|----------|----------------------|
| **Webhook Management** | Real-time synchronization foundation | **HIGH** | Phase 1 (Critical) |
| **Customer Management API** | Bi-directional customer sync | **HIGH** | Phase 1 (Critical) |
| **Refund Processing** | Complete payment lifecycle | **HIGH** | Phase 1 (Critical) |
| **FA Native Integration** | Maintainability and consistency | **MEDIUM** | Phase 2 (Enhancement) |
| **Advanced Order Features** | Enhanced order management | **MEDIUM** | Phase 3 (Future) |
| **Reporting & Analytics** | Business intelligence | **LOW** | Phase 4 (Future) |

---

## Phase 1: Critical Missing APIs (Next 30 days)

### 🎯 **Goal**: Achieve 75% API coverage, enable real-time synchronization

#### 1.1 Webhook Management Service (Priority: CRITICAL)

**Objective**: Replace polling with webhook-driven real-time synchronization

**TDD Implementation Plan**:

```php
// Test-first approach
class WebhookServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testCanCreateWebhookSubscription(): void
    {
        // Arrange
        $mockClient = $this->createMock(\Square\Api\WebhookSubscriptionsApi::class);
        $service = new WebhookService($mockClient);
        
        // Act
        $subscription = $service->createSubscription(
            'https://example.com/webhook',
            ['payment.created', 'order.created']
        );
        
        // Assert
        $this->assertInstanceOf(WebhookSubscription::class, $subscription);
        $this->assertEquals('https://example.com/webhook', $subscription->getUrl());
    }
}
```

**Service Architecture**:
```php
// Contract
interface WebhookServiceInterface
{
    public function createSubscription(string $url, array $events): WebhookSubscription;
    public function listSubscriptions(): array;
    public function updateSubscription(string $id, WebhookSubscription $subscription): WebhookSubscription;
    public function deleteSubscription(string $id): bool;
    public function handleWebhookEvent(WebhookEvent $event): void;
}

// Implementation
class WebhookService implements WebhookServiceInterface
{
    private SquareClient $client;
    private WebhookEventValidator $validator;
    
    public function __construct(SquareClient $client, WebhookEventValidator $validator)
    {
        $this->client = $client;
        $this->validator = $validator;
    }
    
    public function createSubscription(string $url, array $events): WebhookSubscription
    {
        // Implementation with proper error handling and retry logic
    }
}
```

**Database Schema Enhancement**:
```sql
ALTER TABLE square_import_log ADD COLUMN webhook_event_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE square_import_log ADD COLUMN webhook_received_at TIMESTAMP DEFAULT NULL;
CREATE TABLE square_webhook_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(255) NOT NULL,
    events JSON NOT NULL,
    signature_key VARCHAR(64) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### 1.2 Customer Management API (Priority: HIGH)

**Objective**: Complete bi-directional customer synchronization

**TDD Implementation Plan**:
```php
class CustomerServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testCanCreateCustomerFromFA(): void
    {
        // Arrange
        $mockSquareClient = $this->createMock(\Square\Api\CustomersApi::class);
        $mockFaDebtor = $this->createMock(\ksfraser\FrontAccounting\Square\Models\Debtor::class);
        
        $service = new CustomerService($mockSquareClient, $mockFaDebtor);
        
        // Act
        $squareCustomer = $service->syncCustomerFromFA($mockFaDebtor);
        
        // Assert
        $this->assertInstanceOf(\Square\Models\Customer::class, $squareCustomer);
        $this->assertEquals($mockFaDebtor->getEmail(), $squareCustomer->getEmailAddress());
    }
}
```

**Service Architecture**:
```php
interface CustomerServiceInterface
{
    public function syncCustomerFromFA(Debtor $debtor): SquareCustomer;
    public function syncCustomerToSquare(SquareCustomer $customer): Debtor;
    public function findCustomerByEmail(string $email): ?SquareCustomer;
    public function matchCustomer(string $email, string $phone): ?Debtor;
}

class CustomerService implements CustomerServiceInterface
{
    private SquareClient $client;
    private DebtorsMasterDAO $debtorDao;
    private SquareCustomerDAO $squareCustomerDao;
    
    public function __construct(SquareClient $client, DebtorsMasterDAO $debtorDao, SquareCustomerDAO $squareCustomerDao)
    {
        $this->client = $client;
        $this->debtorDao = $debtorDao;
        $this->squareCustomerDao = $squareCustomerDao;
    }
}
```

#### 1.3 Refund Processing (Priority: HIGH)

**Objective**: Complete payment lifecycle with refund and void capabilities

**TDD Implementation Plan**:
```php
class RefundServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testCanCreateRefund(): void
    {
        // Arrange
        $mockRefundApi = $this->createMock(\Square\Api\RefundsApi::class);
        $mockPayment = $this->createMock(\Square\Models\Payment::class);
        
        $service = new RefundService($mockRefundApi);
        
        // Act
        $refund = $service->createRefund($mockPayment, 1000, 'Customer request');
        
        // Assert
        $this->assertInstanceOf(\Square\Models\Refund::class, $refund);
        $this->assertEquals(1000, $refund->getAmountMoney()->getAmount());
    }
}
```

**Service Architecture**:
```php
interface RefundServiceInterface
{
    public function createRefund(Payment $payment, int $amountInCents, string $reason): Refund;
    public function listRefunds(?string $beginTime, ?string $endTime): array;
    public function cancelPayment(string $paymentId): bool;
    public void recordRefundInFA(Refund $refund, int $invoiceId): int;
}

class RefundService implements RefundServiceInterface
{
    private SquareClient $client;
    private InvoiceCreator $invoiceCreator;
    
    // Implementation with proper error handling and retry logic
}
```

---

## Phase 2: FrontAccounting Native Integration (Next 90 days)

### 🎯 **Goal**: Replace custom implementations with FA system integration for better maintainability

#### 2.1 Customer Service Integration with ksf_FA_CRM

**Objective**: Replace basic customer staging with full CRM integration

**Implementation**:
```php
interface CRMIntegrationInterface
{
    public function syncCustomerWithCRM(Debtor $debtor, SquareCustomer $squareCustomer): void;
    public function getCRMContactHistory(int $debtorNo): array;
}

class CRMIntegrationService implements CRMIntegrationInterface
{
    // Integration with ksf_FA_CRM module
}
```

#### 2.2 Inventory Service Enhancement

**Objective**: Enhanced stock event integration with FA's native inventory system

**Implementation**:
```php
interface StockEventServiceInterface
{
    public function listenToStockEvents(): void;
    public function syncStockMovements(string $squareLocationId, array $stockMoves): void;
    public function getReorderPoints(int $stockId): array;
}

class StockEventService implements StockEventServiceInterface
{
    // Integration with FA's stock move events
}
```

#### 2.3 Sales Service Integration

**Objective**: Replace custom invoice creation with FA's sales order system

**Implementation**:
```php
interface SalesServiceInterface
{
    public function createSalesOrderFromSquare(array $squareData): SalesOrder;
    public void updateSalesOrder(int $orderId, array $updates): void;
    public void createSalesCreditNote(int $originalOrderId, string $reason): CreditNote;
}

class SalesService implements SalesServiceInterface
{
    // Integration with FA's sales order system
}
```

---

## Phase 3: Enhanced Features (Next 6 months)

### 🎯 **Goal**: Add advanced order management and business intelligence

#### 3.1 Advanced Order Features

**Objective**: Enhanced order management capabilities

**Features**:
- Order calculation and preview
- Order cloning and duplication
- Custom attributes support
- Order status synchronization

#### 3.2 Reporting & Analytics

**Objective**: Business intelligence integration

**Features**:
- Square-specific reports in FA
- Sales trend analysis
- Customer analytics
- Inventory performance reports

---

## Architecture & Best Practices

### 🏗️ **SOLID Architecture Implementation**

#### Single Responsibility Principle (SRP)
```php
// Each service has one responsibility
class WebhookService implements WebhookServiceInterface
{
    // Only handles webhook operations
    public function createSubscription(string $url, array $events): WebhookSubscription
    public function handleWebhookEvent(WebhookEvent $event): void
}

class CustomerService implements CustomerServiceInterface  
{
    // Only handles customer operations
    public function syncCustomerFromFA(Debtor $debtor): SquareCustomer
    public function syncCustomerToSquare(SquareCustomer $customer): Debtor
}
```

#### Open/Closed Principle (OCP)
```php
// Open for extension, closed for modification
interface PaymentProcessorInterface
{
    public function process(Payment $payment): PaymentResult;
}

class SquarePaymentProcessor implements PaymentProcessorInterface
{
    public function process(Payment $payment): PaymentResult
    {
        // Square-specific implementation
    }
}

class PayPalPaymentProcessor implements PaymentProcessorInterface
{
    public function process(Payment $payment): PaymentResult
    {
        // PayPal-specific implementation
    }
}
```

#### Dependency Inversion Principle (DIP)
```php
// High-level modules don't depend on low-level modules
class ImportService
{
    private PaymentProcessorInterface $paymentProcessor;
    
    public function __construct(PaymentProcessorInterface $paymentProcessor)
    {
        $this->paymentProcessor = $paymentProcessor;
    }
}
```

### 🧪 **TDD Implementation Strategy**

#### Test-Driven Development Cycle
1. **RED**: Write failing test for new functionality
2. **GREEN**: Write minimal implementation to pass test
3. **REFACTOR**: Improve code while keeping tests green

#### Test Structure Examples
```php
// Unit test for service
class CustomerServiceTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        $this->mockSquareClient = $this->createMock(\Square\Api\CustomersApi::class);
        $this->mockDebtorDao = $this->createMock(DebtorsMasterDAO::class);
        $this->service = new CustomerService($this->mockSquareClient, $this->mockDebtorDao);
    }
    
    public function testCanCreateCustomerFromFA(): void
    {
        // Test implementation
    }
}

// Integration test for API calls
class WebhookIntegrationTest extends \PHPUnit\Framework\TestCase
{
    use \ksfraser\FrontAccounting\Square\Tests\Traits\SquareApiTestTrait;
    
    public function testCanCreateWebhookSubscription(): void
    {
        // Integration test with real API (sandbox)
    }
}
```

### 📝 **Documentation Standards**

#### PHPDoc Standards
```php
/**
 * Short description
 * 
 * Long description with business context and implementation details.
 * 
 * @UML Note: Related class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 * 
 * @param string $url Webhook endpoint URL
 * @param array $events List of event types to subscribe to
 * @return WebhookSubscription Created subscription object
 * @throws WebhookCreationException If subscription fails
 */
public function createSubscription(string $url, array $events): WebhookSubscription
{
    // Implementation
}
```

#### API Documentation
```php
/**
 * Webhook Service API
 * 
 * Handles real-time synchronization between Square and FrontAccounting
 * via webhook subscriptions and event processing.
 * 
 * Endpoints:
 * - POST /webhook/handle-square-event (internal)
 * - GET /webhook/subscriptions (admin)
 * - POST /webhook/subscriptions (admin)
 * 
 * Events Handled:
 * - payment.created: New payment received
 * - order.created: New order created
 * - customer.created: New customer created
 * - customer.updated: Customer information updated
 */
```

---

## Testing Strategy

### 🧪 **Test Coverage Goals**

| Component | Coverage Goal | Testing Type |
|-----------|---------------|--------------|
| Services | 95% | Unit tests + integration tests |
| DAOs | 100% | Unit tests + database tests |
| API Endpoints | 90% | Integration tests + API tests |
| UI Pages | 80% | Functional tests + browser tests |
| Webhook Handling | 100% | Unit + integration + security tests |

### 📊 **Test Categories**

#### Unit Tests
- Test individual service methods in isolation
- Mock external dependencies (Square API, FA database)
- Focus on business logic and edge cases

#### Integration Tests
- Test service interactions
- Test database operations
- Test API connectivity and error handling

#### Security Tests
- Webhook signature validation
- Input sanitization
- Authentication and authorization

#### Performance Tests
- API rate limiting handling
- Batch operation performance
- Database query optimization

---

## Implementation Schedule

### 📅 **Phase 1 Timeline (30 days)**

| Week | Tasks | Deliverables |
|------|-------|--------------|
| Week 1 | Webhook Service implementation | WebhookService class, tests, database schema |
| Week 2 | Customer Management API implementation | CustomerService class, tests, integration |
| Week 3 | Refund Processing implementation | RefundService class, tests, FA integration |
| Week 4 | Phase 1 testing and documentation | Full test coverage, documentation updates |

### 📅 **Phase 2 Timeline (90 days)**

| Month | Tasks | Deliverables |
|-------|-------|--------------|
| Month 1 | FA CRM integration, Stock events | CRMIntegrationService, StockEventService |
| Month 2 | Sales service integration, Tax system | SalesService, TaxService |
| Month 3 | Configuration management, User settings | Preference integration, enhanced config UI |

### 📅 **Phase 3 Timeline (6 months)**

| Quarter | Tasks | Deliverables |
|---------|-------|--------------|
| Q1 | Advanced order features | Order calculation, cloning, attributes |
| Q2 | Reporting & analytics | Business intelligence integration |
| Q3 | Gift cards & loyalty | Future enhancement research |
| Q4 | Performance optimization | Scaling improvements, caching |

---

## Risk Management

### 🚨 **Critical Risks**

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Square API breaking changes | Medium | High | Version pinning, adapter pattern |
| Webhook security breaches | Low | Critical | Signature validation, rate limiting |
| Data synchronization conflicts | Medium | High | Idempotency keys, conflict resolution |
| Performance degradation | Medium | Medium | Batch operations, caching |

### 🛡️ **Risk Mitigation Strategies**

#### API Change Management
- Implement adapter pattern for Square API
- Use semantic versioning for client compatibility
- Maintain comprehensive test suite for regression detection

#### Data Integrity
- Implement idempotency keys for all write operations
- Use database transactions for critical operations
- Implement conflict resolution strategies

#### Security
- Webhook signature validation
- Input sanitization and validation
- Rate limiting and DDoS protection

---

## Success Metrics

### 📈 **Key Performance Indicators**

| Metric | Current | Target | Measurement |
|--------|---------|--------|-------------|
| API Coverage | 42% | 85% | Feature count analysis |
| Real-time Sync | Polling only | Webhook-driven | Sync latency measurement |
| Customer Sync | One-directional | Bi-directional | Customer data consistency |
| Error Rate | 2% | <0.5% | Error logging analysis |
| Test Coverage | 70% | 95% | Code coverage reports |

### 🎯 **Business Value Metrics**

| Metric | Impact | Measurement |
|--------|--------|-------------|
| Manual reconciliation time | 30 minutes/day | User time studies |
| Data accuracy | 95% → 99% | Data validation reports |
| User satisfaction | 70% → 90% | User feedback surveys |
| System reliability | 99% → 99.9% | Uptime monitoring |

---

## Conclusion

This development plan provides a comprehensive roadmap for completing the ksf_FA_Square module, addressing critical gaps in API coverage, and enhancing integration with FrontAccounting's native systems. By following TDD principles, SOLID architecture patterns, and prioritizing business-critical features, we will deliver a robust, maintainable, and feature-complete integration solution.

The phased approach ensures immediate value delivery (Phase 1) while laying the foundation for long-term enhancement (Phase 2-3), positioning the module for future growth and expansion.