# Requirements Specification — ksf_FA_Square

## 1. Business Context

### 1.1 Problem Statement
FrontAccounting (FA) users need to sell products through Square POS terminals and card readers while maintaining centralized inventory, pricing, and transaction records in FA. Currently there is no integrated bridge — products must be managed in two systems, and sales must be reconciled manually.

### 1.2 Business Objectives
| ID | Objective | Priority |
|----|-----------|----------|
| BO-01 | Eliminate dual data entry for product catalog | High |
| BO-02 | Ensure real-time inventory accuracy across systems | High |
| BO-03 | Enable card-present payment collection via Square Reader | Medium |
| BO-04 | Automate sales reconciliation (Square -> FA) | High |
| BO-05 | Provide audit trail for all synchronized data | Medium |
| BO-06 | Unify import staging across Square API, Square CSV, and WooCommerce | Medium |

### 1.3 Stakeholders
| Role | Interest | Engagement |
|------|----------|------------|
| FA Administrator | Configures integration, monitors sync | Active |
| Sales / POS Operator | Uses Square Reader for payments | Active |
| Finance / Accounting | Relies on accurate sales records in FA | Consulted |
| IT / Developer | Maintains and enhances module | Responsible |

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

---

## 3. Functional Requirements

### FR-01: Product Catalog Export (FA -> Square)

| ID | Requirement | Implementation |
|----|-------------|----------------|
| FR-01.01 | System shall export FA stock items to Square Catalog via `CatalogApi::upsertCatalogObject` | `CatalogExporter::upsertProduct()` |
| FR-01.02 | System shall map FA `stock_id` to Square `CatalogItemVariation.sku` | `CatalogExporter` item build |
| FR-01.03 | System shall map FA `description` to Square `CatalogItem.name` and `description` | `CatalogExporter` item build |
| FR-01.04 | System shall map FA `stock_category.description` to Square `CatalogCategory` | `CatalogExporter::resolveCategory()` |
| FR-01.05 | System shall map FA item price (`sales_prices`) to Square `CatalogItemVariation.price_money` | `CatalogExporter` item build |
| FR-01.06 | System shall push stock-on-hand (QoH) to Square via `InventoryApi::batchChangeInventory` | `CatalogExporter::pushInventory()` / `batchPushInventory()` |
| FR-01.07 | System shall upload item images via `CatalogApi::createCatalogImage` | Image upload (to refactor into service) |
| FR-01.08 | System shall assign FA tax types to Square `CatalogTax` objects | `CatalogExporter::resolveTax()` |
| FR-01.09 | System shall support batched upsert of catalog objects via `batchUpsertCatalogObjects` | `CatalogExporter::batchUpsertProducts()` |
| FR-01.10 | System shall delete Square catalog objects when FA items are deactivated | `CatalogExporter::deleteProduct()` |
| FR-01.11 | System shall track export status and token mappings | `FA_SquareUpTokens` integration |

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

### FR-04: Customer Management (Square <-> FA)

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

| ID | Requirement |
|----|-------------|
| FR-07.01 | System shall store Square API access token securely |
| FR-07.02 | System shall support switching between Square Sandbox and Production environments |
| FR-07.03 | System shall store last import date for incremental pulls |
| FR-07.04 | System shall store destination customer/branch mapping |
| FR-07.05 | System shall provide activity log with timestamps and status |
| FR-07.06 | System shall expose API error messages to administrators |

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

### 6.3 Future: Unified Import Staging
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
```
