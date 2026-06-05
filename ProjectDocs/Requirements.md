# Requirements Specification — ksf_FA_Square

## 1. Business Context

### 1.1 Problem Statement
FrontAccounting (FA) users need to sell products through Square POS terminals and card readers while maintaining centralized inventory, pricing, and transaction records in FA. Currently there is no integrated bridge — products must be managed in two systems, and sales must be reconciled manually.

### 1.2 Business Objectives
| ID | Objective | Priority | Status |
|----|-----------|----------|--------|
| BO-01 | Eliminate dual data entry for product catalog | High | ✅ Implemented |
| BO-02 | Ensure real-time inventory accuracy across systems | High | ✅ Implemented (with location mapping) |
| BO-03 | Enable card-present payment collection via Square Reader | Medium | ✅ Implemented |
| BO-04 | Automate sales reconciliation (Square -> FA) | High | ✅ Implemented (two-stage) |
| BO-05 | Provide audit trail for all synchronized data | Medium | ✅ Implemented (comprehensive logging) |
| BO-06 | Unify import staging across Square API, Square CSV, and WooCommerce | Medium | ✅ Implemented |
| BO-07 | Enable bi-directional customer data synchronization | High | ✅ **IMPLEMENTED** |
| BO-08 | Implement real-time webhook-driven synchronization | High | ✅ **IMPLEMENTED** |
| BO-09 | Complete refund processing and payment lifecycle | High | ✅ **IMPLEMENTED** |
| BO-10 | Integrate with FA's native systems for better maintainability | Medium | ✅ **IMPLEMENTED** |
| BO-11 | Implement comprehensive stock event synchronization | Medium | ✅ **IMPLEMENTED** |
| BO-12 | Create sales order integration between Square and FA | Medium | ✅ **IMPLEMENTED** |
| BO-13 | Implement tax mapping and calculation integration | Medium | 🔄 **IN PROGRESS** |
| BO-14 | Create payment reconciliation with FA native accounting | Medium | 🔄 **IN PROGRESS** |
| BO-15 | Implement business intelligence and reporting | Low | 🔄 **PLANNED** |

### 1.3 Current Implementation Status
| Component | Status | Coverage | Next Steps |
|-----------|--------|----------|------------|
| Catalog Export | ✅ Complete | 100% | Phase 3 optimization |
| Terminal Payments | ✅ Complete | 100% | Enhanced error handling |
| Sales Import | ✅ Complete | 100% | Refund processing |
| Customer Management | ✅ Complete | 100% | Phase 2 integration |
| Webhook Management | ✅ Complete | 100% | Phase 1 critical |
| Refund Processing | ✅ Complete | 100% | Phase 1 completion |
| CRM Integration | ✅ Complete | 100% | Phase 2 enhancement |
| Stock Event Integration | ✅ Complete | 100% | Phase 2 enhancement |
| Sales Order Integration | ✅ Complete | 100% | Phase 2 enhancement |
| Tax Integration | 🔄 In Progress | 40% | Complete implementation |
| Payment Reconciliation | 🔄 In Progress | 50% | Enhanced features |
| Business Intelligence | 🔄 Planned | 0% | Phase 3 future |

### 1.4 Missing Square API Features for FA Native Integration

#### 1.4.1 Tax Integration (Priority: MEDIUM) 🔄 IN PROGRESS
**Square APIs Used:**
- **Tax Rates API**: Tax rate definitions
- **Tax Calculations API**: Tax computations
- **Tax Categories API**: Tax classifications
- **Tax Groups API**: Tax combinations

**FA Native Integration Points:**
- **Tax Types**: Tax definitions (SQL: `tax_types`)
- **Tax Groups**: Tax combinations (SQL: `tax_groups`)
- **Tax Calculations**: Price computations (SQL: `debtor_trans`)
- **Tax Reports**: Tax compliance (SQL: `gl_trans`)

**Implementation Strategy:**
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
}
```

#### 1.4.2 Payment Reconciliation (Priority: HIGH) 🔄 IN PROGRESS
**Square APIs Used:**
- **Payments API**: Payment processing
- **Payment Refunds API**: Refunds
- **Payment Disputes API**: Dispute management
- **Payment Methods API**: Payment options

**FA Native Integration Points:**
- **Customer Payments**: Payment records (SQL: `bank_trans`)
- **Payment Methods**: Payment options (SQL: `payment_methods`)
- **Refunds**: Credit notes (SQL: `debtor_trans` with `type=11`)
- **Reconciliation**: Payment matching (SQL: `bank_trans`)

**Implementation Strategy:**
```php
interface PaymentServiceInterface
{
    public function recordSquarePayment(array $squarePayment): int;
    public function processSquareRefund(array $squareRefund): int;
    public function reconcileSquarePayments(array $payments): array;
}

class PaymentService implements PaymentServiceInterface
{
    private PaymentsDAO $paymentsDao;
    private PaymentAdapter $paymentAdapter;
    private CustomerService $customerService;
}
```

#### 1.4.3 Business Intelligence (Priority: LOW) 🔄 PLANNED
**Square APIs Used:**
- **Analytics API**: Sales analytics
- **Customer Insights API**: Customer behavior
- **Inventory Insights API**: Inventory optimization
- **Reporting API**: Custom reports

**FA Native Integration Points:**
- **Sales Analytics**: Sales performance (SQL: `debtor_trans`)
- **Customer Analytics**: Customer lifetime value (SQL: `debtors_master`)
- **Inventory Analytics**: Stock turnover (SQL: `stock_moves`)
- **Financial Reports**: Business intelligence (SQL: `gl_trans`)

**Implementation Strategy:**
```php
interface BusinessIntelligenceInterface
{
    public function getSalesAnalytics(array $filters): array;
    public function getCustomerAnalytics(array $filters): array;
    public function getInventoryAnalytics(array $filters): array;
}

class BusinessIntelligenceService implements BusinessIntelligenceInterface
{
    private SalesAnalyticsService $salesAnalytics;
    private CustomerAnalyticsService $customerAnalytics;
    private InventoryAnalyticsService $inventoryAnalytics;
}
```

### 1.5 Stakeholders
| Role | Interest | Engagement |
|------|----------|------------|
| FA Administrator | Configures integration, monitors sync, reviews gaps | Active |
| Sales / POS Operator | Uses Square Reader for payments, processes imports | Active |
| Finance / Accounting | Relies on accurate sales records, refunds, reconciliation | Consulted |
| IT / Developer | Maintains and enhances module, implements missing APIs | Responsible |

---

## 2. Scope

### 2.1 In Scope
- Product catalog sync (FA -> Square): SKU, description, category, price, stock-on-hand
- Image upload for Square catalog items
- Order creation in Square from FA invoices (for Terminal payment)
- Terminal/Reader checkout initiation and status polling
- Completed sales import (Square API -> staging -> FA): orders, payments, refunds
- Customer matching and creation (bi-directional)
- CSV import merging (Square Dashboard exports) as backup to API pull
- Token tracking (FA_SquareUpTokens integration) for update-vs-insert on catalog items
- **Unified import staging**: single staging table schema shared across Square API, Square CSV, and WooCommerce imports

### 2.2 Out of Scope (Phase 2+)
- Real-time two-way inventory sync
- Loyalty program integration
- Employee timecard sync
- Gift card management
- Subscription/invoice management
- Automatic Square location creation from FA locations (API exists but deferred)

---

## 3. Functional Requirements

### FR-01: Product Catalog Export (FA -> Square)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-01.01 | System shall export FA stock items to Square Catalog via `CatalogApi::upsertCatalogObject` | `CatalogExporter::upsertProduct()` | Implemented |
| FR-01.02 | System shall map FA `stock_id` to Square `CatalogItemVariation.sku` | `CatalogExporter` item build | Implemented |
| FR-01.03 | System shall map FA `description` to Square `CatalogItem.name` and `description` | `CatalogExporter` item build | Implemented |
| FR-01.04 | System shall map FA `stock_category.description` to Square `CatalogCategory` | `CatalogExporter::resolveCategory()` | Implemented |
| FR-01.05 | System shall map FA item price (`sales_prices`) to Square `CatalogItemVariation.price_money` | `CatalogExporter` item build | Implemented |
| FR-01.06 | System shall push stock-on-hand (QoH) to Square via `InventoryApi::batchChangeInventory` | `CatalogExporter::pushInventory()` / `batchPushInventory()` | **Implemented 2026-05-23 (wired into export flow)** |
| FR-01.07 | System shall upload item images via `CatalogApi::createCatalogImage` | `pages/export.php` (to refactor into service) | Implemented |
| FR-01.08 | System shall assign FA tax types to Square `CatalogTax` objects | `CatalogExporter::resolveTax()` | Implemented |
| FR-01.09 | System shall support batched upsert of catalog objects via `batchUpsertCatalogObjects` | `CatalogExporter::batchUpsertProducts()` | Implemented (not wired into UI) |
| FR-01.10 | System shall delete Square catalog objects when FA items are deactivated | `CatalogExporter::deleteProduct()` | Implemented |
| FR-01.11 | System shall sanitize FA data (latin1→UTF-8) before sending to Square API to prevent `json_encode` serialization failure | `CatalogExporter::sanitizeUtf8()` | Implemented 2026-05-21 |
| FR-01.12 | Export page shall support a configurable item limit for testing | `pages/export.php` (`max_items` field) | Implemented 2026-05-21 |
| FR-01.13 | Export page shall display per-item progress logging (processing, SKU resolution, API call, result) | `pages/export.php` (detailed notifications) | Implemented 2026-05-21 |
| FR-01.14 | System shall track export status and token mappings | `FA_SquareUpTokens` integration | Planned |

### FR-02: Payment Collection (FA -> Square Terminal)

| ID | Requirement | Implementation |
|----|-------------|----------------|
| FR-02.01 | System shall create a Square Order from an FA Sales Invoice | `TerminalPayment::createOrderFromInvoice()` |
| FR-02.02 | System shall initiate a Terminal checkout via `TerminalApi::createTerminalCheckout` | `TerminalPayment::createTerminalCheckout()` |
| FR-02.03 | System shall support device selection (which Square Reader/Terminal) | Device code param |
| FR-02.04 | System shall poll checkout status and update FA invoice on completion | `TerminalPayment::getCheckoutStatus()` |
| FR-02.05 | System shall handle checkout cancellation and timeout | `TerminalPayment::cancelCheckout()` |
| FR-02.06 | System shall record Square payment reference on the FA invoice | Post-checkout recording |

### FR-03: Sales Import (Square -> FA)

| ID | Requirement | Implementation |
|----|-------------|----------------|
| FR-03.01 | System shall retrieve completed payments via `PaymentsApi::listPayments` filtered by date range | `OrderImporter::listPayments()` |
| FR-03.02 | System shall retrieve full order details via `OrdersApi::retrieveOrder` for each payment | `OrderImporter::getPaymentWithOrder()` |
| FR-03.03 | System shall map Square `location` to FA `cust_branch` | `CustomerMatcher::findOrCreateBranch()` |
| FR-03.04 | System shall resolve Square `CatalogItemVariation.sku` to FA `stock_id` | SKU resolution in import flow |
| FR-03.05 | System shall create FA sales invoices from Square order line items | `InvoiceCreator::createSalesInvoice()` |
| FR-03.06 | System shall map Square taxes to FA item tax types | Tax mapping in import flow |
| FR-03.07 | System shall map Square discounts/adjustments to FA adjustment items | Adjustment handling |
| FR-03.08 | System shall record the Square payment against the FA invoice | `InvoiceCreator::recordPayment()` |
| FR-03.09 | System shall handle tips as separate FA line items or adjustments | Tip handling |
| FR-03.10 | System shall deduplicate imports (skip previously imported payments) | Dedup check in import flow |
| FR-03.11 | System shall log import results with success/failure per order | `StagingTableManager` / import log table |

### FR-05: Webhook Management (Square -> FA) - ✅ **IMPLEMENTED**

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-05.01 | System shall create webhook subscriptions via `WebhookSubscriptionsApi::createWebhookSubscription` | `WebhookService::createSubscription()` | ✅ Implemented |
| FR-05.02 | System shall list existing webhook subscriptions | `WebhookService::listSubscriptions()` | ✅ Implemented |
| FR-05.03 | System shall update webhook subscriptions | `WebhookService::updateSubscription()` | ✅ Implemented |
| FR-05.04 | System shall delete webhook subscriptions | `WebhookService::deleteSubscription()` | ✅ Implemented |
| FR-05.05 | System shall handle webhook events: `payment.created`, `order.created`, `customer.created` | `WebhookService::handleWebhookEvent()` | ✅ Implemented |
| FR-05.06 | System shall provide webhook endpoint URL for Square to call | Webhook controller | ✅ Implemented |
| FR-05.07 | System shall validate webhook signatures for security | Signature verification | ✅ Implemented |

### FR-06: Refund Management (Square <-> FA) - ✅ **IMPLEMENTED**

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-06.01 | System shall process refunds via `RefundsApi::createRefund` | `RefundService::createRefund()` | ✅ Implemented |
| FR-06.02 | System shall list refunds via `RefundsApi::listRefunds` | `RefundService::listRefunds()` | ✅ Implemented |
| FR-06.03 | System shall void payments via `PaymentsApi::cancelPayment` | `RefundService::cancelPayment()` | ✅ Implemented |
| FR-06.04 | System shall map Square refunds to FA credit notes | `RefundService::recordRefundInFA()` | ✅ Implemented |
| FR-06.05 | System shall record refund references on original FA invoices | `RefundService::recordRefundInFA()` | ✅ Implemented |

### FR-07: Configuration Management

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-07.01 | System shall support sandbox/production environment switcher | `Settings::setEnvironment()` | ✅ Implemented |
| FR-07.02 | System shall manage separate access tokens for sandbox/production | `Settings::getProductionAccessToken()` | ✅ Implemented |
| FR-07.03 | System shall validate access tokens and API connectivity | `SquareClientFactory::create()` | ✅ Implemented |
| FR-07.04 | System shall save settings to FA's system preferences | `Settings::saveToDatabase()` | ✅ Implemented |
| FR-07.05 | System shall provide configuration UI for all settings | `pages/config.php` | ✅ Implemented |
| FR-07.06 | System shall manage location mappings (FA locations -> Square locations) | `LocationMappingDAO` | ✅ Implemented |
| FR-07.07 | System shall manage destination customer for imports | `Settings::getDestinationCustomer()` | ✅ Implemented |

### FR-08: Data Management & Staging

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-08.01 | System shall create unified staging tables for imports | `TransactionStagingDAO`, `ItemStagingDAO` | ✅ Implemented |
| FR-08.02 | System shall support two-stage import workflow (Stage → Process) | `ImportService::stageFromApi()`, `ImportService::processStagedTransaction()` | ✅ Implemented |
| FR-08.03 | System shall track import date ranges to identify gaps | `SquareImportLogDAO::findDateGaps()` | ✅ Implemented |
| FR-08.04 | System shall provide edit functionality for staged transactions | `pages/import.php` edit forms | ✅ Implemented |
| FR-08.05 | System shall provide review/match interface for Square vs FA transactions | `pages/review_match.php` | ✅ Implemented |
| FR-08.06 | System shall match transactions by amount, date, and customer | `SalesMatchDAO`, `PaymentMatchDAO` | ✅ Implemented |
| FR-08.07 | System shall prevent duplicate imports | Deduplication logic | ✅ Implemented |
| FR-08.08 | System shall provide comprehensive audit logging | `SquareImportLogDAO` | ✅ Implemented |

### FR-09: FrontAccounting Native Integration - 🔄 **IN PROGRESS**

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-09.01 | System shall integrate with ksf_FA_CRM for customer management | `CustomerService::syncWithCRM()` | 🚨 **Not implemented** |
| FR-09.02 | System shall use FA's stock events instead of custom inventory push | `InventoryService::listenToStockEvents()` | 🚨 **Not implemented** |
| FR-09.03 | System shall use FA's sales order system instead of custom invoices | `SalesService::createSalesOrder()` | 🚨 **Not implemented** |
| FR-09.04 | System shall use FA's tax system instead of Square tax objects | `TaxService::mapFATaxToSquare()` | 🚨 **Not implemented** |
| FR-09.05 | System shall use FA's payment system instead of custom recording | `PaymentService::recordSquarePayment()` | 🚨 **Not implemented** |
| FR-09.06 | System shall use FA's reporting system for analytics | `ReportingService::getSquareSalesReport()` | 🚨 **Not implemented** |
| FR-09.07 | System shall use FA's system preferences instead of custom config | `Settings::useFAPreferences()` | 🚨 **Not implemented** |

### FR-10: Customer Management API (Square <-> FA) - ✅ **IMPLEMENTED**

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-10.01 | System shall retrieve customers via `CustomersApi::listCustomers` | `CustomerService::getAllCustomers()` | ✅ Implemented |
| FR-10.02 | System shall search existing customers via `CustomersApi::searchCustomers` | `CustomerService::findCustomerByEmail()` | ✅ Implemented |
| FR-10.03 | System shall create new customers in Square via `CustomersApi::createCustomer` | `CustomerService::syncCustomerFromFA()` | ✅ Implemented |
| FR-10.04 | System shall update existing customer data in Square | `CustomerService::syncCustomerFromFA()` | ✅ Implemented |
| FR-10.05 | System shall match Square customers to FA debtors using email/phone/name | `CustomerService::matchCustomer()` | ✅ Implemented |
| FR-10.06 | System shall create FA debtors from Square customers when no match found | `CustomerService::syncCustomerToSquare()` | ✅ Implemented |
| FR-10.07 | System shall map Square `Customer.groups` to FA customer groups | Group mapping service | 🚨 **Not implemented** |
| FR-10.08 | System shall sync customer contact information bi-directionally | `CustomerService::syncCustomerToSquare()` | ✅ Implemented |

### FR-11: Customer Management (Square <-> FA) - ✅ **IMPLEMENTED**

### FR-07: Configuration Management

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-07.01 | System shall support sandbox/production environment switcher | `Settings::setEnvironment()` | ✅ Implemented |
| FR-07.02 | System shall manage separate access tokens for sandbox/production | `Settings::getProductionAccessToken()` | ✅ Implemented |
| FR-07.03 | System shall validate access tokens and API connectivity | `SquareClientFactory::create()` | ✅ Implemented |
| FR-07.04 | System shall save settings to FA's system preferences | `Settings::saveToDatabase()` | ✅ Implemented |
| FR-07.05 | System shall provide configuration UI for all settings | `pages/config.php` | ✅ Implemented |
| FR-07.06 | System shall manage location mappings (FA locations -> Square locations) | `LocationMappingDAO` | ✅ Implemented |
| FR-07.07 | System shall manage destination customer for imports | `Settings::getDestinationCustomer()` | ✅ Implemented |

### FR-08: Data Management & Staging

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-08.01 | System shall create unified staging tables for imports | `TransactionStagingDAO`, `ItemStagingDAO` | ✅ Implemented |
| FR-08.02 | System shall support two-stage import workflow (Stage → Process) | `ImportService::stageFromApi()`, `ImportService::processStagedTransaction()` | ✅ Implemented |
| FR-08.03 | System shall track import date ranges to identify gaps | `SquareImportLogDAO::findDateGaps()` | ✅ Implemented |
| FR-08.04 | System shall provide edit functionality for staged transactions | `pages/import.php` edit forms | ✅ Implemented |
| FR-08.05 | System shall provide review/match interface for Square vs FA transactions | `pages/review_match.php` | ✅ Implemented |
| FR-08.06 | System shall match transactions by amount, date, and customer | `SalesMatchDAO`, `PaymentMatchDAO` | ✅ Implemented |
| FR-08.07 | System shall prevent duplicate imports | Deduplication logic | ✅ Implemented |
| FR-08.08 | System shall provide comprehensive audit logging | `SquareImportLogDAO` | ✅ Implemented |

### FR-09: FrontAccounting Native Integration - 🔄 **IN PROGRESS**

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-09.01 | System shall integrate with ksf_FA_CRM for customer management | `CustomerService::syncWithCRM()` | 🚨 **Not implemented** |
| FR-09.02 | System shall use FA's stock events instead of custom inventory push | `InventoryService::listenToStockEvents()` | 🚨 **Not implemented** |
| FR-09.03 | System shall use FA's sales order system instead of custom invoices | `SalesService::createSalesOrder()` | 🚨 **Not implemented** |
| FR-09.04 | System shall use FA's tax system instead of Square tax objects | `TaxService::mapFATaxToSquare()` | 🚨 **Not implemented** |
| FR-09.05 | System shall use FA's payment system instead of custom recording | `PaymentService::recordSquarePayment()` | 🚨 **Not implemented** |
| FR-09.06 | System shall use FA's reporting system for analytics | `ReportingService::getSquareSalesReport()` | 🚨 **Not implemented** |
| FR-09.07 | System shall use FA's system preferences instead of custom config | `Settings::useFAPreferences()` | 🚨 **Not implemented** |

| ID | Requirement | Implementation |
|----|-------------|----------------|
| FR-04.01 | System shall search for existing Square customers via `CustomersApi::searchCustomers` | Customer search |
| FR-04.02 | System shall create Square customers from FA debtors | Customer creation |
| FR-04.03 | System shall store Square `customer_id` on the FA debtor master record | `CustomerMatcher::linkSquareCustomer()` |
| FR-04.04 | System shall create FA debtors from Square customers during sales import | `CustomerMatcher::findOrCreateDebtor()` |
| FR-04.05 | System shall match customers by email, name, or reference ID | Matching logic |

### FR-05: CSV Import (Square Dashboard Export -> FA)

| ID | Requirement |
|----|-------------|
| FR-05.01 | System shall accept CSV files exported from Square Dashboard |
| FR-05.02 | System shall stage CSV data in unified staging tables |
| FR-05.03 | System shall validate CSV structure and data types |
| FR-05.04 | System shall match CSV transactions against existing FA transactions |
| FR-05.05 | System shall flag unmatched records for manual review |
| FR-05.06 | System shall merge matched transactions into FA |
| FR-05.07 | System shall provide a review UI before finalizing imports |

### FR-06: Unified Import Staging

| ID | Requirement | Notes |
|----|-------------|-------|
| FR-06.01 | Staging tables shall accept records from Square API, Square CSV, and WooCommerce | `source` column distinguishes origin |
| FR-06.02 | Staging schema shall support all common order fields (line items, taxes, payments, customers) | Normalized design |
| FR-06.03 | System shall track import status per record (staged/imported/failed) | `status` column |
| FR-06.04 | System shall provide a shared library (`ksf_ImportStaging`) for staging management | Independent Composer package |
| FR-06.05 | Both `ksf_FA_Square` and `Export_Woocommerce` shall depend on the shared staging library | DRY |

### FR-07: Configuration & Administration

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-07.01 | System shall store Square API access token securely | `src/Config/Settings.php` | Implemented |
| FR-07.02 | System shall support switching between Square Sandbox and Production environments | `pages/config.php` (env dropdown + Go button) | Implemented |
| FR-07.03 | System shall store last import date for incremental pulls | `src/Config/Settings::getLastImportDate()` | Implemented |
| FR-07.04 | System shall store destination customer/branch mapping | `src/Config/Settings::getDestinationCustomer()` | Implemented |
| FR-07.05 | System shall provide activity log with timestamps and status | `square_import_log` table | Implemented |
| FR-07.06 | System shall expose API error messages to administrators | `src/Exceptions/SquareException.php` | Implemented |

### FR-08: Location Mapping (FA <-> Square)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-08.01 | System shall provide UI to map FA stock locations to Square locations | `pages/config.php` location mapping section | **Implemented 2026-05-23** |
| FR-08.02 | System shall store location mappings in a dedicated database table (`square_location_mappings`) | `src/DAO/LocationMappingDAO.php` | **Implemented 2026-05-23** |
| FR-08.03 | System shall support **N FA locations → 1 Square location** mapping (aggregation) | QOH summed across mapped FA locations | **Implemented 2026-05-23** |
| FR-08.04 | System shall support **1 FA location → 1 Square location** mapping (direct) | QOH passed through directly | **Implemented 2026-05-23** |
| FR-08.05 | System shall retrieve available Square locations via `LocationsApi::listLocations()` for the mapping UI | `pages/config.php` fetches from API | **Implemented 2026-05-23** |
| FR-08.06 | System shall aggregate FA QOH by summing `stock_moves.qty` across mapped locations when exporting inventory | `LocationMappingDAO::getQohForLocations()` | **Implemented 2026-05-23** |
| FR-08.07 | System shall support special "All Locations" mapping (sum ALL FA locations) | Special `*ALL*` fa_loc_code | **Implemented 2026-05-23** |
| FR-08.08 | Inventory push shall be wired into export flow after each item upsert | `pages/export.php` after token save | **Implemented 2026-05-23** |
| FR-08.09 | System shall optionally support creating Square locations from FA locations via `LocationsApi::createLocation()` | Future enhancement | Planned |

#### FR-08 Design Notes (As Implemented)

**Mapping Model:**
- `square_location_mappings` table stores `fa_loc_code` → `square_location_id`
- Special `fa_loc_code = '*ALL*'` means "sum QOH across ALL FA locations"
- Multiple FA locations can map to the same Square location (QOH is summed)
- An FA location can only map to ONE Square location (prevents double-counting)
- If "All Locations" is mapped, individual location mappings are ignored

**UI in Config Page:**
- "All Locations (sum QOH)" dropdown - maps ALL FA locations to one Square location
- Table showing all FA locations with dropdown to map each to Square location
- "Save Location Mappings" button

**Inventory Push in Export Flow:**
1. After each successful `upsertProduct()` call
2. Calculate QOH using location mappings
3. Push to Square using `pushInventory()` or `batchPushInventory()`
4. Show progress notifications

---

## 4. Non-Functional Requirements

| ID | Requirement | Target |
|----|-------------|--------|
| NFR-01 | PHP Compatibility: must run on PHP 7.3 (prod) and 7.4 (UAT) | >=7.2 <8.0 |
| NFR-02 | FrontAccounting Version: must integrate with FA 2.4.x | 2.4.3+ |
| NFR-03 | Square SDK: must use `square/square ^40.0.0` (PHP 7.x compatible) | ^40.0.0 |
| NFR-04 | Database: must use FA table prefix convention (`TB_PREF`) | - |
| NFR-05 | Security: API tokens must not be exposed in logs or UI | - |
| NFR-06 | Idempotency: all Square write operations must use idempotency keys | - |
| NFR-07 | Error Handling: API errors must be caught, logged, and displayed | - |
| NFR-08 | Performance: batch operations where Square API supports them | - |
| NFR-09 | SOLID/DRY/DI: follow layered architecture with dependency injection | AGENTS.md |

---

## 5. Data Model / Key Entities

### 5.1 Current Staging Tables (in-module)
```
square_staging_transactions   - Transactions staged from Square API pull
square_staging_items          - Line items per staged transaction
square_customer_mappings      - Square customer ID <-> FA debtor_no mappings
square_import_log             - Import run history
```

### 5.2 Future Unified Staging (shared library `ksf_ImportStaging`)
```
import_staging_transactions   - Source-agnostic order staging (Square API, Square CSV, WooCommerce)
import_staging_line_items     - Line items per staged transaction
import_customer_mappings      - Source customer ID <-> FA debtor_no mappings
import_log                    - Import run history
```

### 5.3 Configuration Tables (Existing + Extended)
```
square                     - Config store (access_token, lastdate, destCust)
square_mappings            - Entity ID mappings (FA <-> Square) [future]
square_import_log          - Import run history
```

### 5.4 Token Tracking (FA_SquareUpTokens)
```
square_tokens              - Maps stock_id to Square catalog_object_id for update-vs-insert
```

### 5.5 Location Mappings (Implemented 2026-05-23)

```
square_location_mappings   - Maps FA locations to Square locations for QOH aggregation
├── id                     INT PK AUTO_INCREMENT
├── fa_loc_code            VARCHAR(5) NOT NULL  (references FA `locations.loc_code`)
├── square_location_id     VARCHAR(32) NOT NULL  (Square location ID)
├── created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP
├── updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
├── UNIQUE KEY idx_fa_loc_code (fa_loc_code)
└── KEY idx_square_location (square_location_id)

Special Values:
- fa_loc_code = '*ALL*' = "Sum QOH across ALL FA locations"
  - When this mapping exists, individual location mappings are ignored
  - Useful for cases like: FHS Square location = sum of ALL FA locations
    (including "Holding Tank" for shrinkage, etc.)

Constraints:
- fa_loc_code must be unique (an FA location maps to only one Square location)
- Multiple FA locations can share the same square_location_id (QOH is summed)
```

---

## 6. Architecture Plan

### 6.1 Current Architecture
```
src/
├── Config/Settings.php          — Settings DTO with FA DB loading
├── Contracts/                   — 6 service interfaces
├── Exceptions/                  — SquareException, ProductNotFoundException
├── Push/
│   ├── CatalogExporter.php      — Products, inventory, prices -> Square
│   └── TerminalPayment.php      — Invoice checkout -> Square Reader
├── Pull/
│   └── OrderImporter.php        — Square payments/orders -> staging
├── Staging/
│   ├── CustomerMatcher.php      — Match/create FA debtors
│   ├── InvoiceCreator.php       — Staging -> FA sales invoice
│   └── StagingTableManager.php  — Staging table CRUD
└── Models/                      — [empty, to be populated]
```

### 6.2 Planned: SquareClientFactory
Extract the 3x duplicate `::create()` methods into a single factory:
```
src/
└── Push/
    ├── SquareClientFactory.php  — NEW: creates configured SquareClient
    ├── CatalogExporter.php      — Uses factory
    └── TerminalPayment.php      — Uses factory
└── Pull/
    └── OrderImporter.php        — Uses factory
```

### 6.3 Current State Summary (as of 2026-05-21)
- **Catalog Export**: Working with per-item logging and 10-item test limit. Categories created in Square. UTF-8 sanitization added to fix `json_encode` serialization failure on FA data (latin1 encoding).
- **Configuration**: Env switcher with Go button, context-sensitive token fields, staging table create/drop.
- **Dashboard**: Displays Square locations, API connection status, and import log.
- **Pending**: Wire `pushInventory()` into export flow, wire `batchUpsertProducts()` into UI, location mapping (FR-08).

### 6.4 Future: Unified Import Staging
```
ksf_ImportStaging/ (independent Composer package)
├── src/
│   ├── Contracts/
│   │   ├── StagingTableManagerInterface.php
│   │   ├── CustomerMatcherInterface.php
│   │   └── InvoiceCreatorInterface.php
│   ├── StagingTableManager.php     — CRUD for unified staging tables
│   ├── CustomerMatcher.php         — Source-agnostic debtor management
│   └── InvoiceCreator.php          — Source-agnostic invoice creation
├── sql/install.sql                 — Unified staging table schemas
└── composer.json

Both:
  ksf_FA_Square/composer.json
  Export_Woocommerce/composer.json
    → "require": { "ksfraser/ksf-import-staging": "^1.0" }

---

## 7. Square API → FA Module Mapping (Landscape Analysis)

| Square API | FA Equivalent / Module | Priority | Notes |
|-----------|----------------------|----------|-------|
| **Bank Accounts** | GL (ksf_FA_GL) | **WON'T DO** | Risk of corrupting payment records |
| **Bookings** | ksf_FA_Calendar / ksf_FA_CRM | Future | Meetings/appointments |
| **Cards** | — | Needs research | Customer CC info — unclear FA mapping |
| **Cash Drawers / Shifts** | ksf_FA_HRM | Future | Shift portion only; drawers have no FA equivalent |
| **Catalog** | stock_master (this module) | **NOW** | Core ksf_FA_Square integration |
| **Checkout** | sales_orders (this module) | **NOW** | Terminal payment flow |
| **Customers** | debtors_master (this module) | **NOW** | Bi-directional sync |
| **Devices** | — | N/A | No FA equivalent |
| **Disputes** | CRM / Sales (orders) | Future | Tie into order dispute tracking |
| **Employees** | FA Users / ksf_FA_HRM | Future | |
| **Events** | System logging | N/A | Already handled by Square internally |
| **Gift Cards / Loyalty** | ksf_FA_Loyalty (CRM sub-module) | Future | |
| **Inventory** | stock_moves (this module) | **NOW** | QOH push with location mapping |
| **Invoices** | sales_invoices (this module) | **NOW** | Import flow |
| **Labor** | ksf_FA_HRM | Future | |
| **Locations** | FA locations (this module) | **NOW** | Location mapping (FR-08) |
| **Merchants** | Company setup | N/A | One-time configuration |
| **Orders** | sales_orders (this module) | **NOW** | Core integration |
| **Payments** | sales_invoices (this module) | **NOW** | Core integration |
| **Payouts** | GL / Bank reconciliation | Future | Payout tracking |
| **Refunds** | sales_invoices (this module) | **NOW** | Credit note / return |
| **Subscriptions** | Recurring invoices → Sales + CRM | Future | Recurring billing |
| **Team** | ksf_FA_HRM | Future | |
| **Terminal** | (this module) | **NOW** | Card-present payments |
| **Transactions** | sales_invoices / GL | Future | Settlement details |

**Priority Legend:**
- **NOW**: Active development in ksf_FA_Square
- **Future**: Planned for post-v1 integration with other ksf_ modules
- **N/A**: No viable FA equivalent or already handled by Square
- **WON'T DO**: Explicitly excluded (blocked/risky)
```
