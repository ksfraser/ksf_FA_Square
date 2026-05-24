# Phase 2: FrontAccounting Native Integration (90 days)

## Overview
Replace custom implementations with FA's native system integration to achieve true bi-directional sync and eliminate manual data mapping.

## 2.1 CRM Integration (Priority: HIGH)

### 2.1.1 CRM Service Interface
```php
interface CRMIntegrationInterface
{
    public function syncCustomerWithCRM(Debtor $debtor, SquareCustomer $squareCustomer): void;
    public function getCRMContactHistory(int $debtorNo): array;
    public function trackCustomerCommunication(array $communication): void;
}

class CRMIntegrationService implements CRMIntegrationInterface
{
    private DebtorsMasterDAO $debtorDao;
    private SquareCustomerDAO $squareCustomerDao;
    private CRMAdapter $crmAdapter;
    
    public function syncCustomerWithCRM(Debtor $debtor, SquareCustomer $squareCustomer): void
    {
        // Sync FA debtor to CRM
        $this->crmAdapter->updateContact([
            'contact_id' => $debtor['debtor_no'],
            'name' => $debtor['name'],
            'email' => $debtor['email'],
            'phone' => $debtor['phone'],
            'source' => 'square_integration'
        ]);
        
        // Update sync timestamp
        $this->squareCustomerDao->updateMappingBySquareId(
            $squareCustomer->getId(),
            ['crm_sync_at' => date('Y-m-d H:i:s')]
        );
    }
}
```

### 2.1.2 CRM Event Listener
```php
class CRMEventListener
{
    private CRMIntegrationService $crmService;
    
    public function __construct(CRMIntegrationService $crmService)
    {
        $this->crmService = $crmService;
    }
    
    public function listenToCustomerEvents(): void
    {
        // Hook into FA's customer save event
        add_action('debtor_after_save', function($debtor) {
            $squareCustomer = $this->getSquareCustomerFromDebtor($debtor);
            if ($squareCustomer) {
                $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
            }
        });
    }
}
```

### 2.1.3 TDD Test Cases
```php
/**
 * @test
 */
public function crmIntegrationUpdatesContact(): void
{
    // Arrange
    $debtor = [
        'debtor_no' => 123,
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ];
    
    $squareCustomer = $this->createMockCustomer('cus_123');
    
    $this->mockCrmAdapter->expects($this->once())
        ->method('updateContact')
        ->with($this->equalTo([
            'contact_id' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'source' => 'square_integration'
        ]));
    
    // Act
    $this->crmService->syncCustomerWithCRM($debtor, $squareCustomer);
    
    // Assert
    $this->assertTrue($this->crmService->isContactSynced(123));
}
```

## 2.2 Stock Event Integration (Priority: MEDIUM)

### 2.2.1 Stock Event Listener
```php
interface StockEventServiceInterface
{
    public function listenToStockEvents(): void;
    public function syncStockMovements(string $squareLocationId, array $stockMoves): void;
}

class StockEventService implements StockEventServiceInterface
{
    private SquareClient $squareClient;
    private LocationMappingDAO $locationMappingDao;
    private StockMovementAdapter $stockAdapter;
    
    public function listenToStockEvents(): void
    {
        // Hook into FA's stock movement events
        add_action('stock_after_move', function($move) {
            $this->handleStockMove($move);
        });
        
        add_action('stock_after_adjustment', function($adjustment) {
            $this->handleStockAdjustment($adjustment);
        });
    }
    
    private function handleStockMove(array $move): void
    {
        $squareLocationId = $this->locationMappingDao->getSquareLocationId($move['stock_id']);
        
        $stockMove = [
            'type' => 'STOCK_MOVE',
            'from_location' => $move['from_location'],
            'to_location' => $move['to_location'],
            'item_id' => $this->getSquareItemId($move['stock_id']),
            'quantity' => $move['quantity'],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->stockAdapter->sendStockMove($squareLocationId, $stockMove);
    }
}
```

### 2.2.2 Stock Movement Adapter
```php
class StockMovementAdapter
{
    private SquareClient $squareClient;
    
    public function sendStockMove(string $locationId, array $stockMove): void
    {
        $api = $this->squareClient->getInventoryApi();
        
        $request = new ChangeInventoryRequest([
            'type' => 'CHANGE',
            'location_id' => $locationId,
            'quantity' => $stockMove['quantity'],
            'catalog_object_id' => $stockMove['item_id'],
            'reason' => $stockMove['type'] . ' from FrontAccounting'
        ]);
        
        $api->changeInventory($request);
    }
}
```

## 2.3 Sales Service Integration (Priority: MEDIUM)

### 2.3.1 Sales Order Service
```php
interface SalesServiceInterface
{
    public function createSalesOrderFromSquare(array $squareData): SalesOrder;
    public function updateSalesOrder(int $orderId, array $updates): void;
    public function createSalesCreditNote(int $originalOrderId, string $reason): CreditNote;
}

class SalesService implements SalesServiceInterface
{
    private SalesOrdersDAO $salesOrdersDao;
    private SquareOrderAdapter $orderAdapter;
    private TaxService $taxService;
    
    public function createSalesOrderFromSquare(array $squareData): SalesOrder
    {
        // Map Square order to FA sales order
        $orderData = $this->orderAdapter->mapSquareOrderToFA($squareData);
        
        // Apply tax calculations
        $orderData['tax_details'] = $this->taxService->calculateSquareTaxes($squareData);
        
        // Create sales order using FA's native service
        $orderId = $this->salesOrdersDao->createSalesOrder($orderData);
        
        return $this->salesOrdersDao->getSalesOrder($orderId);
    }
}
```

### 2.3.2 Sales Event Listener
```php
class SalesEventListener
{
    private SalesService $salesService;
    private SquareOrderDAO $squareOrderDao;
    
    public function __construct(SalesService $salesService, SquareOrderDAO $squareOrderDao)
    {
        $this->salesService = $salesService;
        $this->squareOrderDao = $squareOrderDao;
    }
    
    public function listenToSalesEvents(): void
    {
        // Hook into FA's sales order events
        add_action('sales_order_after_create', function($order) {
            $this->handleSalesOrderCreated($order);
        });
        
        add_action('sales_order_after_update', function($order) {
            $this->handleSalesOrderUpdated($order);
        });
    }
    
    private function handleSalesOrderCreated(array $order): void
    {
        // Send to Square if it's a Square-mapped order
        if ($this->isSquareMappedOrder($order)) {
            $this->salesService->updateSquareOrder($order['order_id'], $order);
        }
    }
}
```

## 2.4 Tax Service Integration (Priority: MEDIUM)

### 2.4.1 Tax Calculation Service
```php
interface TaxServiceInterface
{
    public function calculateSquareTaxes(array $squareData): array;
    public function mapFATaxToSquare(array $faTaxData): array;
    public function mapSquareTaxToFA(array $squareTaxData): array;
}

class TaxService implements TaxServiceInterface
{
    private TaxRatesDAO $taxRatesDao;
    private TaxMappingDAO $taxMappingDao;
    
    public function calculateSquareTaxes(array $squareData): array
    {
        $taxes = [];
        
        foreach ($squareData['line_items'] as $item) {
            $itemTaxes = $this->calculateItemTaxes($item);
            $taxes = $this->mergeTaxes($taxes, $itemTaxes);
        }
        
        return $taxes;
    }
    
    private function calculateItemTaxes(array $item): array
    {
        $taxes = [];
        
        foreach ($item['taxes'] as $tax) {
            $faTaxRate = $this->taxMappingDao->getFATaxRate($tax->getTaxRateId());
            
            if ($faTaxRate) {
                $taxes[] = [
                    'rate_id' => $tax->getTaxRateId(),
                    'name' => $tax->getName(),
                    'percentage' => $faTaxRate['rate'],
                    'amount' => $this->calculateTaxAmount($item, $faTaxRate)
                ];
            }
        }
        
        return $taxes;
    }
}
```

## 2.5 Payment Service Integration (Priority: MEDIUM)

### 2.5.1 Payment Recording Service
```php
interface PaymentServiceInterface
{
    public function recordSquarePayment(array $squarePayment): int;
    public void processSquareRefund(array $squareRefund): int;
    public function reconcileSquarePayments(array $payments): array;
}

class PaymentService implements PaymentServiceInterface
{
    private PaymentsDAO $paymentsDao;
    private PaymentAdapter $paymentAdapter;
    private CustomerService $customerService;
    
    public function recordSquarePayment(array $squarePayment): int
    {
        // Match or create customer
        $customer = $this->customerService->matchCustomer(
            $squarePayment['customer_email'],
            $squarePayment['customer_phone']
        );
        
        if (!$customer) {
            $customer = $this->customerService->syncCustomerToSquare(
                $squarePayment['customer_data']
            );
        }
        
        // Map payment to FA format
        $paymentData = $this->paymentAdapter->mapSquarePaymentToFA($squarePayment, $customer);
        
        // Record payment using FA's native service
        return $this->paymentsDao->recordPayment($paymentData);
    }
}
```

## 2.6 Reporting Service Integration (Priority: LOW)

### 2.6.1 Business Intelligence Integration
```php
interface ReportingServiceInterface
{
    public function getSquareSalesReport(array $filters): array;
    public function getCustomerAnalytics(int $debtorNo): array;
    public function getInventoryPerformanceReport(): array;
}

class ReportingService implements ReportingServiceInterface
{
    private SalesAnalytics $salesAnalytics;
    private CustomerAnalytics $customerAnalytics;
    private InventoryAnalytics $inventoryAnalytics;
    
    public function getSquareSalesReport(array $filters): array
    {
        $report = [
            'total_sales' => $this->salesAnalytics->calculateTotalSales($filters),
            'transaction_count' => $this->salesAnalytics->countTransactions($filters),
            'average_order_value' => $this->salesAnalytics->calculateAOV($filters),
            'top_customers' => $this->customerAnalytics->getTopCustomers($filters),
            'product_performance' => $this->inventoryAnalytics->getProductPerformance($filters)
        ];
        
        return $report;
    }
}
```

## Phase 2 Implementation Timeline

### Month 1: CRM and Stock Integration
- **Week 1-2**: CRM integration service and event listeners
- **Week 3-4**: Stock event listener and movement sync

### Month 2: Sales and Tax Integration
- **Week 5-6**: Sales order service and event handling
- **Week 7-8**: Tax calculation and mapping services

### Month 3: Payment and Reporting Integration
- **Week 9-10**: Payment recording and reconciliation
- **Week 11-12**: Business intelligence and reporting

## Phase 2 Deliverables

### Code Deliverables
- CRM integration service with event listeners
- Stock event listener with movement sync
- Sales order service with FA integration
- Tax calculation and mapping services
- Payment recording and reconciliation
- Business intelligence reporting
- Complete test suite with 90%+ coverage

### Documentation Deliverables
- Updated API coverage: 85% (from 72%)
- FA integration architecture documentation
- Event-driven sync implementation guide
- Performance optimization guidelines

### Business Value
- Eliminates manual data entry (100% reduction)
- Real-time bi-directional sync
- Native FA accounting integration
- Enhanced reporting and analytics
- Improved data accuracy (99.9%+)

## Risk Mitigation

### Technical Risks
- **FA Version Compatibility**: Use adapter pattern for version-specific implementations
- **Event System Conflicts**: Implement priority-based event handling
- **Data Sync Conflicts**: Use conflict resolution with timestamps

### Business Risks
- **Data Migration**: Implement phased migration with rollback capability
- **User Training**: Create comprehensive training materials
- **Performance Impact**: Monitor and optimize sync performance