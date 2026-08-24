# Requirements Specification — ksf_FA_Square

> **Module Code**: SQ
> **Version**: 2.4.4-0
> **Platform**: PHP 7.3 / FrontAccounting 2.4.19

---

## 1. Business Context

FrontAccounting (FA) users need to sell products through Square POS terminals and card readers while maintaining centralized inventory, pricing, and transaction records in FA. Currently there is no integrated bridge — products must be managed in two systems, and sales must be reconciled manually.

### 1.1 Business Objectives

| ID | Objective | Priority | Status |
|----|-----------|----------|--------|
| BO-01 | Eliminate dual data entry for product catalog | High | ✅ Implemented |
| BO-02 | Ensure real-time inventory accuracy across systems | High | ✅ Implemented (location mapping) |
| BO-03 | Enable card-present payment collection via Square Reader | Medium | ✅ Implemented |
| BO-04 | Automate sales reconciliation (Square → FA) | High | ✅ Implemented (two-stage) |
| BO-05 | Provide audit trail for all synchronized data | Medium | ✅ Implemented |
| BO-06 | Enable remote payment via Square Invoices | High | ✅ Implemented |
| BO-07 | Bi-directional customer synchronization | High | ✅ Implemented |
| BO-08 | Webhook-driven real-time events | High | ✅ Implemented |
| BO-09 | Refund and dispute lifecycle management | High | ✅ Implemented |
| BO-10 | Unified import staging across Square + WooCommerce | Medium | ✅ Implemented |
| BO-11 | Tax mapping integration | Medium | 🔄 In Progress |
| BO-12 | Business intelligence and reporting | Low | 📋 Planned |

### 1.2 Stakeholders

| Role | Interest | Engagement |
|------|----------|------------|
| FA Administrator | Configures integration, monitors sync | Active |
| Sales / POS Operator | Uses Square Reader, processes imports | Active |
| Finance / Accounting | Reconciles sales, refunds, disputes | Consulted |
| IT / Developer | Maintains module, implements features | Responsible |

---

## 2. Scope

### 2.1 In Scope
- Product catalog sync (FA → Square): SKU, description, category, price, QOH, images
- Terminal/Reader checkout initiation and status polling
- Completed sales import (Square API → staging → FA)
- Square-Invoice payment destination (email, manual, card-on-file)
- Customer matching and creation (bi-directional)
- Webhook subscription management
- Refund processing (create, list, void)
- Dispute tracking
- Card-on-file management
- CSV import merging (Square Dashboard exports)
- Location mapping (FA ↔ Square)
- Import logging and gap detection

### 2.2 Out of Scope
- Loyalty program integration
- Employee timecard sync
- Gift card management
- Subscription/invoice recurring billing
- Automatic Square location creation from FA locations

---

## 3. Functional Requirements

### FR-SQ-001: Product Catalog Export (FA → Square)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-001-001 | Export FA stock items to Square Catalog via `CatalogApi::upsertCatalogObject` | `CatalogExporter::upsertProduct()` | ✅ |
| FR-SQ-001-002 | Map FA `stock_id` to Square `CatalogItemVariation.sku` | CatalogExporter | ✅ |
| FR-SQ-001-003 | Map FA `description` to Square `CatalogItem.name` | CatalogExporter | ✅ |
| FR-SQ-001-004 | Map FA stock category to Square `CatalogCategory` | `CatalogExporter::resolveCategory()` | ✅ |
| FR-SQ-001-005 | Map FA item price to Square `price_money` | CatalogExporter | ✅ |
| FR-SQ-001-006 | Push QOH to Square via `InventoryApi::batchChangeInventory` | `CatalogExporter::pushInventory()` | ✅ |
| FR-SQ-001-007 | Upload item images via `CatalogApi::createCatalogImage` | `pages/export.php` | ✅ |
| FR-SQ-001-008 | Assign FA tax types to Square `CatalogTax` objects | `CatalogExporter::resolveTax()` | ✅ |
| FR-SQ-001-009 | Batch upsert via `batchUpsertCatalogObjects` | `CatalogExporter::batchUpsertProducts()` | ✅ |
| FR-SQ-001-010 | Delete Square catalog objects when FA items deactivated | `CatalogExporter::deleteProduct()` | ✅ |
| FR-SQ-001-011 | Sanitize FA data (latin1→UTF-8) before API calls | `CatalogExporter::sanitizeUtf8()` | ✅ |
| FR-SQ-001-012 | Configurable item limit for testing | `pages/export.php` | ✅ |
| FR-SQ-001-013 | Per-item progress logging | `pages/export.php` | ✅ |

---

### FR-SQ-002: Terminal Payment Collection (FA → Square)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-002-001 | Create Square Order from FA Sales Invoice | `TerminalPayment::createOrderFromInvoice()` | ✅ |
| FR-SQ-002-002 | Initiate Terminal checkout via `TerminalApi::createTerminalCheckout` | `TerminalPayment::createTerminalCheckout()` | ✅ |
| FR-SQ-002-003 | Support device selection (which Terminal/Reader) | Device code param | ✅ |
| FR-SQ-002-004 | Poll checkout status and update FA invoice | `TerminalPayment::getCheckoutStatus()` | ✅ |
| FR-SQ-002-005 | Handle checkout cancellation and timeout | `TerminalPayment::cancelCheckout()` | ✅ |
| FR-SQ-002-006 | Record Square payment reference on FA invoice | Post-checkout recording | ✅ |

---

### FR-SQ-003: Sales Import (Square → FA)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-003-001 | Retrieve payments via `PaymentsApi::listPayments` (date-filtered) | `OrderImporter::listPayments()` | ✅ |
| FR-SQ-003-002 | Retrieve order details via `OrdersApi::retrieveOrder` | `OrderImporter::getPaymentWithOrder()` | ✅ |
| FR-SQ-003-003 | Map Square location to FA `cust_branch` | `CustomerMatcher::findOrCreateBranch()` | ✅ |
| FR-SQ-003-004 | Resolve Square SKU to FA `stock_id` | SKU resolution | ✅ |
| FR-SQ-003-005 | Create FA sales invoices from Square order line items | `InvoiceCreator::createSalesInvoice()` | ✅ |
| FR-SQ-003-006 | Map Square taxes to FA item tax types | Tax mapping | ✅ |
| FR-SQ-003-007 | Map Square discounts/adjustments to FA adjustment items | Adjustment handling | ✅ |
| FR-SQ-003-008 | Record Square payment against FA invoice | `InvoiceCreator::recordPayment()` | ✅ |
| FR-SQ-003-009 | Handle tips as separate FA line items | Tip handling | ✅ |
| FR-SQ-003-010 | Deduplicate imports (skip previously imported payments) | Dedup check | ✅ |
| FR-SQ-003-011 | Log import results per order | `StagingTableManager` | ✅ |

---

### FR-SQ-004: Customer Management (Square ↔ FA)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-004-001 | Retrieve customers via `CustomersApi::listCustomers` | `CustomerService::getAllCustomers()` | ✅ |
| FR-SQ-004-002 | Search customers via `CustomersApi::searchCustomers` | `CustomerService::findCustomerByEmail()` | ✅ |
| FR-SQ-004-003 | Create new Square customers via `CustomersApi::createCustomer` | `CustomerService::syncCustomerFromFA()` | ✅ |
| FR-SQ-004-004 | Update existing Square customer data | `CustomerService::syncCustomerFromFA()` | ✅ |
| FR-SQ-004-005 | Match Square customers to FA debtors by email/phone/name | `CustomerService::matchCustomer()` | ✅ |
| FR-SQ-004-006 | Create FA debtors from Square customers when no match found | `CustomerService::syncCustomerToSquare()` | ✅ |
| FR-SQ-004-007 | Sync customer contact information bi-directionally | `CustomerService` | ✅ |
| FR-SQ-004-008 | Map Square `Customer.groups` to FA customer groups | Group mapping service | 📋 |

---

### FR-SQ-005: Webhook Management (Square → FA)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-005-001 | Create webhook subscriptions via `WebhookSubscriptionsApi` | `WebhookService::createSubscription()` | ✅ |
| FR-SQ-005-002 | List existing webhook subscriptions | `WebhookService::listSubscriptions()` | ✅ |
| FR-SQ-005-003 | Update webhook subscriptions | `WebhookService::updateSubscription()` | ✅ |
| FR-SQ-005-004 | Delete webhook subscriptions | `WebhookService::deleteSubscription()` | ✅ |
| FR-SQ-005-005 | Handle events: `payment.created`, `order.created`, `customer.created/updated` | `WebhookService::handleWebhookEvent()` | ✅ |
| FR-SQ-005-006 | Provide webhook endpoint URL for Square callbacks | `pages/webhook.php` | ✅ |
| FR-SQ-005-007 | Validate HMAC-SHA256 webhook signatures | Signature verification | ✅ |
| FR-SQ-005-008 | Admin UI for webhook subscription management | `pages/webhook_management.php` | ✅ |
| FR-SQ-005-009 | Event logging with failed-event retry | WebhookService | ✅ |

---

### FR-SQ-006: Refund Management (Square ↔ FA)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-006-001 | Create refunds via `RefundsApi::createRefund` | `RefundService::createRefund()` | ✅ |
| FR-SQ-006-002 | List refunds via `RefundsApi::listRefunds` | `RefundService::listRefunds()` | ✅ |
| FR-SQ-006-003 | Void payments via `PaymentsApi::cancelPayment` | `RefundService::cancelPayment()` | ✅ |
| FR-SQ-006-004 | Map Square refunds to FA credit notes | `RefundService::recordRefundInFA()` | ✅ |
| FR-SQ-006-005 | Record refund references on original FA invoices | `RefundService::recordRefundInFA()` | ✅ |
| FR-SQ-006-006 | Batch refund processing | `RefundService::batchRefundProcessing()` | ✅ |
| FR-SQ-006-007 | Refund statistics | `RefundService::getRefundStatistics()` | ✅ |

---

### FR-SQ-007: Square-Invoice Payment Destination

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-007-001 | Detect `square_invoice*` payment terms in `db_prewrite` | `hooks.php` | ✅ |
| FR-SQ-007-002 | Suppress FA auto-payment (`cash_sale=0`) for Square-Invoice terms | `hooks.php` | ✅ |
| FR-SQ-007-003 | Create Square Order from FA cart line items | `SquareInvoiceService` | ✅ |
| FR-SQ-007-004 | Auto-create Square Customer from FA debtor if not mapped | `SquareInvoiceService` | ✅ |
| FR-SQ-007-005 | Create Square Invoice (DRAFT) with accepted payment methods | `SquareInvoiceService` | ✅ |
| FR-SQ-007-006 | Publish Square Invoice (UNPAID) with public payment URL | `SquareInvoiceService` | ✅ |
| FR-SQ-007-007 | Store FA↔Square Invoice mapping in `0_square_invoice_map` | `SquareInvoiceMapDAO` | ✅ |
| FR-SQ-007-008 | Store FA↔Square Customer mapping in `0_square_customer_mappings` | `SquareInvoiceService` | ✅ |
| FR-SQ-007-009 | Support delivery methods: SHARE_MANUALLY, EMAIL, SMS | `SquareInvoiceService` | ✅ |
| FR-SQ-007-010 | Support card-on-file auto-charge for `square_invoice_card` | `SquareInvoiceService` | ✅ |
| FR-SQ-007-011 | Idempotent: return existing mapping if already mapped | `SquareInvoiceService` | ✅ |
| FR-SQ-007-012 | Display notification with payment URL after creation | `hooks.php` | ✅ |
| FR-SQ-007-013 | Coordinate with FA_PaymentDestinations (non-Square terms) | Hook execution order | ✅ |

---

### FR-SQ-008: Configuration Management

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-008-001 | Sandbox/production environment switcher | `Settings::setEnvironment()` | ✅ |
| FR-SQ-008-002 | Separate access tokens per environment | `Settings` | ✅ |
| FR-SQ-008-003 | Validate access tokens and API connectivity | `SquareClientFactory::create()` | ✅ |
| FR-SQ-008-004 | Save settings to FA database | `Settings::saveToDatabase()` | ✅ |
| FR-SQ-008-005 | Configuration UI for all settings | `pages/config.php` | ✅ |
| FR-SQ-008-006 | Location mapping (FA locations ↔ Square locations) | `LocationMappingDAO` | ✅ |
| FR-SQ-008-007 | Default destination customer for imports | `Settings::getDestinationCustomer()` | ✅ |
| FR-SQ-008-008 | Default tax group for export | `Settings::getDefaultTaxGroup()` | ✅ |

---

### FR-SQ-009: Import Staging & Data Management

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-009-001 | Unified staging tables for imports | `StagingTableManager` | ✅ |
| FR-SQ-009-002 | Two-stage import workflow (Stage → Process) | `ImportService` | ✅ |
| FR-SQ-009-003 | Track import date ranges for gap detection | `SquareImportLogDAO::findDateGaps()` | ✅ |
| FR-SQ-009-004 | Edit staged transactions | `pages/import.php` | ✅ |
| FR-SQ-009-005 | Review/match interface (Square vs FA) | `pages/review_match.php` | ✅ |
| FR-SQ-009-006 | Match by amount, date, and customer | `SalesMatchDAO` | ✅ |
| FR-SQ-009-007 | Prevent duplicate imports | Deduplication logic | ✅ |
| FR-SQ-009-008 | Comprehensive audit logging | `SquareImportLogDAO` | ✅ |
| FR-SQ-009-009 | Transaction correction and history | `TransactionCorrection` | ✅ |

---

### FR-SQ-016: ISU Config Absorption

**Description**: Square owns all source-specific configuration previously
held by ISU. GL accounts, bank accounts, payment methods, and
Square-specific behavior flags live in Square's `Settings` class and
config UI, NOT in ISU.

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-016-001 | Store `square_gl` (GL for card transactions) in Square Settings | `Settings::getSquareGl()` | Planned |
| FR-SQ-016-002 | Store `cash_gl` (GL for cash transactions) in Square Settings | `Settings::getCashGl()` | Planned |
| FR-SQ-016-003 | Store `xfer_to_gl` (transfer destination GL) in Square Settings | `Settings::getXferToGl()` | Planned |
| FR-SQ-016-004 | Store `square_bank` (bank for deposits) in Square Settings | `Settings::getSquareBank()` | Planned |
| FR-SQ-016-005 | Store `xfer_to_bank` (transfer dest bank) in Square Settings | `Settings::getXferToBank()` | Planned |
| FR-SQ-016-006 | Store `cash_bank` (cash bank) in Square Settings | `Settings::getCashBank()` | Planned |
| FR-SQ-016-007 | Store `useCardAsBranch` (PAN-suffix as customer branch) in Settings | `Settings::getUseCardAsBranch()` | Planned |
| FR-SQ-016-008 | Store `allowSkuChange` (allow SKU editing on staged items) in Settings | `Settings::getAllowSkuChange()` | Planned |
| FR-SQ-016-009 | Store `default_pay_card` (card payment method) in Settings | `Settings::getDefaultPayCard()` | Planned |
| FR-SQ-016-010 | Store `default_pay_cash` (cash payment method) in Settings | `Settings::getDefaultPayCash()` | Planned |
| FR-SQ-016-011 | Config UI section for all import settings (GL, bank, payment, booleans) | `pages/config.php` | Planned |
| FR-SQ-016-012 | Pass source config to ISU processing methods via `$sourceConfig` array | `ImportService` | Planned |

---

### FR-SQ-017: Staging Ownership

**Description**: Square fully owns its staging flow. Orders are staged
via ISU staging tables using Square's own DAOs and the hook_invoke
interface. The dormant dual-write pattern (local `square_staging_*`
tables) is removed.

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-017-001 | All imports stage through ISU tables (`ksf_import_square_*`) | `ImportService` | ✅ |
| FR-SQ-017-002 | Remove references to `square_staging_*` local tables | `StagingTableManager` | Planned |
| FR-SQ-017-003 | Standardize on `ksf_import_square_*` as the canonical staging tables | — | ✅ |
| FR-SQ-017-004 | ImportService passes Square config (GL/bank) to ISU at processing time | `ImportService` | Planned |

---

### FR-SQ-018: Config Absorbed from ISU

**Description**: Eleven configuration fields previously in ISU's
`config_values` are now owned by Square. These fields are source-specific
to Square and do not belong in a generic staging module.

| ID | Requirement | Config Key | Status |
|----|-------------|-----------|--------|
| FR-SQ-018-001 | GL account for card transactions | `square_gl` | Planned |
| FR-SQ-018-002 | GL account for cash transactions | `cash_gl` | Planned |
| FR-SQ-018-003 | Transfer destination GL account | `xfer_to_gl` | Planned |
| FR-SQ-018-004 | Bank account for card deposits | `square_bank` | Planned |
| FR-SQ-018-005 | Transfer destination bank account | `xfer_to_bank` | Planned |
| FR-SQ-018-006 | Cash bank account | `cash_bank` | Planned |
| FR-SQ-018-007 | Use PAN suffix as customer branch | `useCardAsBranch` | Planned |
| FR-SQ-018-008 | Allow SKU editing on staged items | `allowSkuChange` | Planned |
| FR-SQ-018-009 | Default card payment method | `default_pay_card` | Planned |
| FR-SQ-018-010 | Default cash payment method | `default_pay_cash` | Planned |
| FR-SQ-018-011 | Default pricebook (dead, to be cleaned up) | `default_pricebook` | Planned |

---

### FR-SQ-010: Location Mapping (FA ↔ Square)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-010-001 | UI to map FA stock locations to Square locations | `pages/config.php` | ✅ |
| FR-SQ-010-002 | Store mappings in `square_location_mappings` | `LocationMappingDAO` | ✅ |
| FR-SQ-010-003 | N FA locations → 1 Square location (QOH aggregation) | `LocationMappingDAO` | ✅ |
| FR-SQ-010-004 | 1 FA location → 1 Square location (direct) | `LocationMappingDAO` | ✅ |
| FR-SQ-010-005 | Retrieve Square locations via `LocationsApi::listLocations()` | `pages/config.php` | ✅ |
| FR-SQ-010-006 | Aggregate QOH by summing across mapped locations | `LocationMappingDAO::getQohForLocations()` | ✅ |
| FR-SQ-010-007 | Special `*ALL*` mapping (sum all FA locations) | `LocationMappingDAO` | ✅ |
| FR-SQ-010-008 | Wire inventory push into export flow | `pages/export.php` | ✅ |

---

### FR-SQ-011: Dispute Tracking

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-011-001 | Retrieve disputes via Square Disputes API | DisputeService | ✅ |
| FR-SQ-011-002 | Link disputes to original FA invoices | DisputeService | ✅ |
| FR-SQ-011-003 | Track dispute status (PENDING, WON, LOST) | DisputeService | ✅ |
| FR-SQ-011-004 | Display dispute information in CRM module | CRM integration | ✅ |

---

### FR-SQ-012: Card-on-File Management

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-012-001 | List customer cards via `CustomersApi` | CardService | ✅ |
| FR-SQ-012-002 | Store card references for Square-Invoice auto-charge | `SquareInvoiceService` | ✅ |
| FR-SQ-012-003 | Support card refunds with processing fee awareness | RefundService | ✅ |

---

### FR-SQ-013: CSV Import (Square Dashboard → FA)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-013-001 | Accept CSV files exported from Square Dashboard | `pages/import.php` | ✅ |
| FR-SQ-013-002 | Stage CSV data in unified staging tables | `ImportService` | ✅ |
| FR-SQ-013-003 | Validate CSV structure and data types | CSV parsers | ✅ |
| FR-SQ-013-004 | Match CSV transactions against existing FA transactions | Matching logic | ✅ |
| FR-SQ-013-005 | Flag unmatched records for manual review | Review UI | ✅ |

---

### FR-SQ-014: Inter-Module Communication

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-014-001 | Implement 4 standard hook methods for module discovery | `hooks.php` | ✅ |
| FR-SQ-014-002 | Advertise `export`, `import`, `payments`, `config` capabilities | `getModuleCapabilities()` | ✅ |
| FR-SQ-014-003 | Support `hook_invoke()` queries from other modules | `respondToCapabilityRequest()` | ✅ |

---

### FR-SQ-015: FA Native Integration (Future)

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQ-015-001 | Integrate with ksf_FA_CRM for customer management | — | 📋 |
| FR-SQ-015-002 | Use FA stock events instead of custom inventory push | — | 📋 |
| FR-SQ-015-003 | Use FA sales order system instead of custom invoices | — | 📋 |
| FR-SQ-015-004 | Use FA tax system instead of Square tax objects | — | 📋 |
| FR-SQ-015-005 | Use FA payment system instead of custom recording | — | 📋 |

---

## 4. Non-Functional Requirements

| ID | Requirement | Target |
|----|-------------|--------|
| NFR-SQ-001 | PHP Compatibility | ≥7.3 <8.0 |
| NFR-SQ-002 | FrontAccounting Version | 2.4.x |
| NFR-SQ-003 | Square SDK | `square/square ^40.0.0` |
| NFR-SQ-004 | Database convention | FA `0_` table prefix |
| NFR-SQ-005 | Security: tokens not exposed in logs/UI | — |
| NFR-SQ-006 | Idempotency: all Square writes use idempotency keys | — |
| NFR-SQ-007 | Error Handling: API errors caught, logged, displayed | — |
| NFR-SQ-008 | Performance: batch operations where supported | — |
| NFR-SQ-009 | Architecture: SOLID/DRY/DI, PSR-4, layered design | AGENTS.md |

---

## 5. Data Model

### 5.1 Module Tables

| Table | Purpose |
|-------|---------|
| `square` | Config store (tokens, env, last import date, defaults) |
| `square_tokens` | FA stock_id ↔ Square catalog_object/variation ID mapping |
| `square_customer_mappings` | FA debtor_no ↔ Square customer_id |
| `square_location_mappings` | FA loc_code ↔ Square location_id |
| `square_import_log` | Import run history with date ranges |
| `square_invoice_map` | FA invoice_no ↔ Square invoice/order, status, URL |
| `square_webhook_subscriptions` | Webhook subscription mirror |
| `square_webhook_events` | Webhook event log |

### 5.2 Shared Staging Tables (via ISU)

| Table | Purpose |
|-------|---------|
| `ksf_import_square_transactions` | Unified staging (source-agnostic) |
| `ksf_import_square_items` | Line items staging |
| `ksf_import_square_payments` | Payment ↔ FA transaction xref |
| `ksf_import_square_sales` | Transaction ↔ FA invoice xref |

---

## 6. Architecture

```
src/
├── Config/Settings.php              — Settings DTO
├── Contracts/                       — Service interfaces
├── Exceptions/                      — Custom exceptions
├── Push/
│   ├── CatalogExporter.php          — Products, inventory → Square
│   ├── TerminalPayment.php          — Invoice → Square Terminal
│   └── SquareClientFactory.php      — SDK client factory
├── Pull/
│   └── OrderImporter.php           — Square payments → staging
├── Services/
│   ├── CustomerService.php         — Bi-directional customer sync
│   ├── RefundService.php           — Refund lifecycle
│   ├── WebhookService.php          — Webhook CRUD + events
│   ├── SquareInvoiceService.php    — Square-Invoice creation
│   ├── ImportService.php           — Import orchestration
│   └── ExportService.php           — Export orchestration
├── Staging/
│   ├── CustomerMatcher.php         — Match/create FA debtors
│   ├── InvoiceCreator.php          — Staging → FA invoice
│   └── StagingTableManager.php     — Staging CRUD
├── DAO/                            — Data access objects
├── BusinessIntelligence/           — Analytics services
└── Platform/                       — Cache, encryption, email, etc.
```

---

## 7. SDK v40 Compatibility Notes

| Issue | Fix |
|-------|-----|
| `OrderLineItem` requires `$quantity` | `new OrderLineItem($qty)` |
| `Order` requires `$locationId` | `new Order($this->locationId)` |
| `Money` setters return void | Separate assignment calls |
| `CreateOrderRequest` no constructor | `$req = new CreateOrderRequest(); $req->setOrder($order)` |
| `acceptedPaymentMethods` required | Must set `InvoiceAcceptedPaymentMethods` |
| `primaryRecipient.customer_id` required | Auto-create via CustomersApi |
| `UpdateCustomerRequest` no constructor | `$req = new UpdateCustomerRequest()` |
| `SearchCustomersRequest` filter types | Use `CustomerTextFilter` objects |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-05-20 | KSFraser | Initial requirements |
| 2.0 | 2026-08-20 | KSFraser | Consolidated: removed duplicates, added FR-SQ-011 to FR-SQ-015 |
| 2.1 | 2026-08-20 | KSFraser | Added FR-SQ-016 (ISU config absorption), FR-SQ-017 (staging ownership), FR-SQ-018 (absorbed fields) |
| 2.2 | 2026-08-23 | KSFraser | Added FR-SQ-019 (ISU Repository Adapter Layer), FR-SQUARE-ISU-001 through 005, NFR-ISU-001 through 004 |

---

### FR-SQ-019: ISU Repository Adapter Layer

**Description**: Square provides adapter classes that implement ISU's repository
interfaces, enabling ISU's StagingService to process Square data through the
standard contract. Each adapter bridges Square's proprietary data format to ISU's
normalized models.

| ID | Requirement | Implementation | Status |
|----|-------------|----------------|--------|
| FR-SQUARE-ISU-001 | TransactionRepositoryAdapter implements TransactionRepositoryInterface | `src/Staging/TransactionRepositoryAdapter.php` | ✅ |
| FR-SQUARE-ISU-002 | CustomerRepositoryAdapter implements CustomerRepositoryInterface | `src/Staging/CustomerRepositoryAdapter.php` | ✅ |
| FR-SQUARE-ISU-003 | PaymentRepositoryAdapter implements PaymentRepositoryInterface | `src/Staging/PaymentRepositoryAdapter.php` | ✅ |
| FR-SQUARE-ISU-004 | LineItemRepositoryAdapter implements LineItemRepositoryInterface | `src/Staging/LineItemRepositoryAdapter.php` | ✅ |
| FR-SQUARE-ISU-005 | AuditLogRepositoryAdapter implements AuditLogRepositoryInterface | `src/Staging/AuditLogRepositoryAdapter.php` | ✅ |

### Non-Functional Requirements for ISU Adapters

| ID | Requirement | Rationale |
|----|-------------|-----------|
| NFR-ISU-001 | Backward compatible with existing Square staging data | Production data in ksf_import_square_transactions |
| NFR-ISU-002 | PHP 7.3 compatible (no PHP 8+ features) | FA 2.4.x target platform |
| NFR-ISU-003 | Use FA's db_* functions, not PDO | FA's db_query() doesn't support prepared statements |
| NFR-ISU-004 | Square-specific fields preserved in raw_json/attributes | ISU models don't know Square fields |
