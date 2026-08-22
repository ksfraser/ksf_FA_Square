# Use Cases — ksf_FA_Square

> **Module Code**: SQ

---

## UC-SQ-001: Export FA Catalog to Square

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-001 |
| **Name** | Export FA Stock Items to Square Catalog |
| **Trigger** | Admin clicks "Export to Square" or triggers export |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Square API credentials configured; FA stock items exist |
| **Postconditions** | Products created/updated in Square; token mapping stored |

### Basic Flow
1. Admin navigates to Export to Square page
2. System displays export form (location, category, stock pattern, max items, filters)
3. Admin configures filters and clicks "Export"
4. System iterates through FA stock items matching filters
5. For each item: `CatalogExporter::upsertProduct()` creates/updates Square CatalogItem + Variation
6. System resolves or creates Square Category (`resolveCategory()`)
7. System resolves or creates Square Tax (`resolveTax()`)
8. System uploads item images if enabled (`createCatalogImage`)
9. System pushes QOH via `InventoryApi::batchChangeInventory` with location mapping
10. System stores token mapping in `square_tokens` (for update-vs-insert)
11. System displays per-item progress notifications

### Alternative Flows
- **5a. SKU duplicate**: System logs warning, skips item
- **5b. API rate limit**: System retries with backoff
- **5c. VERSION_MISMATCH**: System retries up to 5 times
- **9a. No location mapping**: QOH pushed for default location only
- **9b. `*ALL*` mapping**: QOH summed across all FA locations

### Related Requirements
FR-SQ-001

---

## UC-SQ-002: Collect Payment via Square Terminal

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-002 |
| **Name** | Process Card Payment via Square Terminal |
| **Trigger** | User initiates Terminal payment from FA invoice |
| **Primary Actor** | Sales Operator |
| **Preconditions** | Square Terminal/Reader connected; FA invoice ready |
| **Postconditions** | Payment collected, FA invoice marked as paid |

### Basic Flow
1. User creates FA sales invoice for a customer
2. User selects "Square Terminal" payment action
3. System calls `TerminalPayment::createOrderFromInvoice()` to build Square Order
4. System calls `TerminalPayment::createTerminalCheckout()` to initiate checkout on device
5. Customer taps/inserts card on Square Reader
6. System polls `getTerminalCheckout()` for status
7. On COMPLETED: System records Square payment reference on FA invoice
8. User sees confirmation

### Alternative Flows
- **4a. Device busy**: System notifies user, suggests alternate device
- **6a. Checkout timed out**: System cancels checkout, notifies user
- **6b. Checkout cancelled**: System handles cancellation gracefully
- **7a. Payment failed**: System logs error, invoice remains unpaid

### Related Requirements
FR-SQ-002

---

## UC-SQ-003: Import Square Sales to FA

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-003 |
| **Name** | Import Completed Square Payments into FA |
| **Trigger** | Admin clicks "Import Square Orders" |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Square API credentials; completed payments exist |
| **Postconditions** | Transactions staged, reviewed, processed into FA invoices + payments |

### Basic Flow
1. Admin navigates to Import Square Orders page
2. Admin selects mode: Direct Import or Stage-First
3. Admin sets date range, customer, location filters
4. System calls `PaymentsApi::listPayments()` (paginated, date-filtered)
5. For each payment: System calls `OrdersApi::retrieveOrder()` for line items
6. System resolves SKU → FA stock_id, maps location → branch
7. **Stage-first mode**: Data inserted into staging tables; admin reviews/processes later
8. **Direct mode**: System creates `Cart(ST_SALESINVOICE)` with line items
9. System records payment against debtor (idempotent by Square payment_id)
10. System logs import results

### Alternative Flows
- **4a. `listPayments` broken in sandbox**: Pass null locationId, filter locally
- **5a. Payment has no order**: Skip (log)
- **5b. Refunded payment**: Skip (log)
- **7a. Duplicate detected**: Skip, notify
- **8a. Customer not found**: Use configured destination customer

### Related Requirements
FR-SQ-003

---

## UC-SQ-004: Send Square Invoice for Remote Payment

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-004 |
| **Name** | Create Square Invoice from FA Sales Invoice |
| **Trigger** | User posts sales invoice with `square_invoice*` payment term |
| **Primary Actor** | Sales Operator |
| **Preconditions** | Square API configured; payment term mapped in PaymentDestinations |
| **Postconditions** | Square Invoice published, URL displayed, mapping stored |

### Basic Flow
1. User creates sales invoice with Square-Invoice payment term
2. FA calls `db_prewrite` hooks
3. ksf_FA_Square detects `square_invoice*` destination
4. Sets `cash_sale=0` (suppress auto-payment), stores cart data
5. FA commits transaction, calls `db_postwrite`
6. `SquareInvoiceService::createInvoiceFromFA()`:
   a. Check existing mapping (idempotent)
   b. Create Square Order from line items
   c. Resolve/create Square Customer from FA debtor
   d. Create Invoice DRAFT with accepted payment methods
   e. Publish Invoice (status → UNPAID)
   f. Store mapping in `0_square_invoice_map`
7. Display notification with public payment URL

### Alternative Flows
- **6c. Customer not mapped**: Auto-create from FA debtor
- **6d. `square_invoice_email`**: Deliver via email
- **6e. `square_invoice_card`**: Auto-charge card on file

### Related Requirements
FR-SQ-007

---

## UC-SQ-005: Manage Webhook Subscriptions

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-005 |
| **Name** | Configure Square Webhook Subscriptions |
| **Trigger** | Admin manages webhooks via admin page |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Square API credentials configured |
| **Postconditions** | Webhook subscriptions active, events logged |

### Basic Flow
1. Admin opens Webhook Management page
2. System lists existing webhook subscriptions
3. Admin can create new subscription (URL, event types)
4. System calls `WebhookSubscriptionsApi::createWebhookSubscription()`
5. Square sends test event; system validates HMAC-SHA256 signature
6. On subsequent events, system logs in `square_webhook_events`

### Alternative Flows
- **3a. Signature validation fails**: Reject event, log security warning
- **5a. Event type not recognized**: Log and ignore
- **5b. `payment.created`**: Trigger order import logic
- **5c. `customer.created/updated`**: Trigger customer sync

### Related Requirements
FR-SQ-005

---

## UC-SQ-006: Process Refund

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-006 |
| **Name** | Refund Square Payment and Record in FA |
| **Trigger** | Admin initiates refund from FA or Square |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Original payment exists; refund amount ≤ payment amount |
| **Postconditions** | Refund created in Square, credit note in FA |

### Basic Flow
1. Admin identifies payment to refund
2. Admin specifies refund amount and reason
3. System calls `RefundsApi::createRefund()` with idempotency key
4. System calls `RefundService::recordRefundInFA()` to create FA credit note
5. System records refund reference on original invoice
6. Admin sees confirmation

### Alternative Flows
- **3a. Amount exceeds payment**: Error, cannot refund more than paid
- **3b. Void instead of refund**: System calls `PaymentsApi::cancelPayment()`
- **4a. FA credit note creation fails**: Log error, refund still recorded in Square

### Related Requirements
FR-SQ-006

---

## UC-SQ-007: Sync Customers Bidirectionally

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-007 |
| **Name** | Synchronize Customers Between FA and Square |
| **Trigger** | Admin syncs customers or import creates customer |
| **Primary Actor** | FA Administrator / System |
| **Preconditions** | Customers exist in one or both systems |
| **Postconditions** | Customers matched/created; mapping stored |

### Basic Flow
1. System receives customer data (from import, webhook, or manual sync)
2. `CustomerService::findCustomerByEmail()` searches Square by email
3. `CustomerService::matchCustomer()` scores against FA debtors (email/phone/name)
4. **Match found**: Link via `square_customer_mappings`
5. **No match**: `CustomerService::syncCustomerToSquare()` creates FA debtor
6. Backfill email from `crm_contacts/crm_persons` if missing

### Alternative Flows
- **4a. Multiple matches**: Use highest-scoring candidate
- **5a. FA debtor exists but no Square customer**: Create Square customer from debtor data

### Related Requirements
FR-SQ-004

---

## UC-SQ-008: Configure Location Mapping

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-008 |
| **Name** | Map FA Stock Locations to Square Locations |
| **Trigger** | Admin configures location mapping |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Locations exist in both FA and Square |
| **Postconditions** | Location mapping stored; inventory push uses mapped locations |

### Basic Flow
1. Admin opens Config page, scrolls to Location Mapping
2. System fetches Square locations via `LocationsApi::listLocations()`
3. Admin maps each FA location to a Square location (or `*ALL*`)
4. Admin saves mappings
5. On next export, `LocationMappingDAO::getQohForLocations()` sums QOH per mapped location

### Alternative Flows
- **3a. `*ALL*` selected**: Sum all FA locations for single Square location
- **3b. Multiple FA → 1 Square**: QOH summed

### Related Requirements
FR-SQ-010

---

## UC-SQ-009: Track Disputes

| Element | Value |
|---------|-------|
| **ID** | UC-SQ-009 |
| **Name** | Monitor Square Payment Disputes |
| **Trigger** | Customer files dispute or admin reviews disputes |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Disputes exist in Square |
| **Postconditions** | Disputes displayed in CRM with status tracking |

### Basic Flow
1. Customer files dispute with card issuer
2. Square creates dispute record
3. System retrieves disputes via Disputes API
4. System links dispute to original FA invoice
5. Admin views disputes in CRM module
6. Admin can respond to dispute with evidence

### Alternative Flows
- **6a. Dispute won**: Status updated, no financial impact
- **6b. Dispute lost**: Status updated, chargeback processed

### Related Requirements
FR-SQ-011

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-05-20 | KSFraser | Initial (UC-09 to UC-11) |
| 2.0 | 2026-08-20 | KSFraser | Added UC-SQ-001 to UC-SQ-009; renumbered with module prefix |
