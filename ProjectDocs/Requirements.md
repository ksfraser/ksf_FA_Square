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
- Terminal/Reader checkout initiation
- Completed sales import (Square -> FA): orders, payments, refunds
- Customer matching and creation
- CSV import merging (existing partial module)

### 2.2 Out of Scope (Phase 2+)
- Real-time two-way inventory sync
- Loyalty program integration
- Employee timecard sync
- Gift card management
- Subscription/invoice management

---

## 3. Functional Requirements

### FR-01: Product Catalog Export (FA -> Square)

| ID | Requirement | Source Object |
|----|-------------|---------------|
| FR-01.01 | System shall export FA stock items to Square Catalog via `CatalogApi::upsertCatalogObject` | `CatalogItem` |
| FR-01.02 | System shall map FA `stock_id` to Square `CatalogItemVariation.sku` | `CatalogItemVariation` |
| FR-01.03 | System shall map FA `description` to Square `CatalogItem.name` and `description` | `CatalogItem` |
| FR-01.04 | System shall map FA `stock_category.description` to Square `CatalogCategory` | `CatalogCategory` |
| FR-01.05 | System shall map FA item price (`sales_prices`) to Square `CatalogItemVariation.price_money` | `Money` |
| FR-01.06 | System shall push stock-on-hand (QoH) to Square via `InventoryApi::batchChangeInventory` | `BatchChangeInventoryRequest` |
| FR-01.07 | System shall upload item images via `CatalogApi::createCatalogImage` | `CatalogImage` |
| FR-01.08 | System shall assign FA tax types to Square `CatalogTax` objects | `CatalogTax` |
| FR-01.09 | System shall support batched upsert of catalog objects via `batchUpsertCatalogObjects` | - |
| FR-01.10 | System shall delete Square catalog objects when FA items are deactivated | `deleteCatalogObject` |
| FR-01.11 | System shall track which FA items have been exported (export status/flag) | - |

### FR-02: Payment Collection (FA -> Square Terminal)

| ID | Requirement | Source Object |
|----|-------------|---------------|
| FR-02.01 | System shall create a Square Order from an FA Sales Invoice via `OrdersApi::createOrder` | `CreateOrderRequest` |
| FR-02.02 | System shall initiate a Terminal checkout via `TerminalApi::createTerminalCheckout` | `CreateTerminalCheckoutRequest` |
| FR-02.03 | System shall support device selection (which Square Reader/Terminal) | `DeviceCode` |
| FR-02.04 | System shall poll checkout status and update FA invoice on completion | - |
| FR-02.05 | System shall handle checkout cancellation and timeout | - |
| FR-02.06 | System shall record Square payment reference on the FA invoice | - |

### FR-03: Sales Import (Square -> FA)

| ID | Requirement | Source Object |
|----|-------------|---------------|
| FR-03.01 | System shall retrieve completed payments via `PaymentsApi::listPayments` filtered by date range | `Payment` |
| FR-03.02 | System shall retrieve full order details via `OrdersApi::retrieveOrder` for each payment | `Order` |
| FR-03.03 | System shall map Square `location` to FA `cust_branch` | - |
| FR-03.04 | System shall resolve Square `CatalogItemVariation.sku` to FA `stock_id` | - |
| FR-03.05 | System shall create FA sales invoices from Square order line items | - |
| FR-03.06 | System shall map Square taxes to FA item tax types | - |
| FR-03.07 | System shall map Square discounts/adjustments to FA adjustment items | - |
| FR-03.08 | System shall record the Square payment against the FA invoice | - |
| FR-03.09 | System shall handle tips as separate FA line items or adjustments | - |
| FR-03.10 | System shall deduplicate imports (skip previously imported payments) | - |
| FR-03.11 | System shall log import results with success/failure per order | - |

### FR-04: Customer Management (Square <-> FA)

| ID | Requirement | Source Object |
|----|-------------|---------------|
| FR-04.01 | System shall search for existing Square customers via `CustomersApi::searchCustomers` by reference ID | - |
| FR-04.02 | System shall create Square customers from FA debtors via `CustomersApi::createCustomer` | `CreateCustomerRequest` |
| FR-04.03 | System shall store Square `customer_id` on the FA debtor master record | - |
| FR-04.04 | System shall create FA debtors from Square customers during sales import | - |
| FR-04.05 | System shall match customers by email, name, or reference ID | - |

### FR-05: CSV Import (Square Dashboard Export -> FA)

| ID | Requirement |
|----|-------------|
| FR-05.01 | System shall accept CSV files exported from Square Dashboard |
| FR-05.02 | System shall stage CSV data in temporary/staging tables |
| FR-05.03 | System shall validate CSV structure and data types |
| FR-05.04 | System shall match CSV transactions against existing FA transactions |
| FR-05.05 | System shall flag unmatched records for manual review |
| FR-05.06 | System shall merge matched transactions into FA |
| FR-05.07 | System shall provide a review UI before finalizing imports |

### FR-06: Configuration & Administration

| ID | Requirement |
|----|-------------|
| FR-06.01 | System shall store Square API access token securely |
| FR-06.02 | System shall support switching between Square Sandbox and Production environments |
| FR-06.03 | System shall store last import date for incremental pulls |
| FR-06.04 | System shall store destination customer/branch mapping |
| FR-06.05 | System shall provide activity log with timestamps and status |
| FR-06.06 | System shall expose API error messages to administrators |

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

---

## 5. Data Model / Key Entities

### 5.1 Staging Tables (New)
```
square_staging_orders     - Raw order data from Square API
square_staging_customers  - Raw customer data from Square API  
square_staging_csv        - Raw CSV import data
```

### 5.2 Configuration Tables (Existing + Extended)
```
square                     - Config store (access_token, lastdate, destCust)
   => EXTEND: type column, longer value column, environment flag
square_mappings            - Entity ID mappings (FA <-> Square)
square_import_log          - Import run history
```
