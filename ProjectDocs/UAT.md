# User Acceptance Test Cases — ksf_FA_Square

> **Module Code**: SQ
> **Version**: 2.4.4-0
> **Platform**: PHP 7.3 / FrontAccounting 2.4.19

---

## Test Environment Requirements

- FA 2.4.19 installed with sample data
- ksf_FA_Square module activated with Composer dependencies
- Square sandbox account with API access
- Square Terminal/Reader (for terminal payment tests)
- Test FA stock items with prices, categories, and QOH
- FA_PaymentDestinations module installed (for Square-Invoice tests)

---

## UAT-SQ-001: Export FA Product to Square

**@BABOK Related: FR-SQ-001**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | Square sandbox configured; active FA stock item with price and category |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Navigate to Orders → Export to Square | Export form loads |
| 2 | Select location, category filter | Filters applied |
| 3 | Set max_items=1 for testing | Limit set |
| 4 | Click "Export" | Progress notifications shown |
| 5 | Verify notification: SKU exported successfully | Item name, SKU, price logged |
| 6 | Open Square sandbox dashboard → Items | Product appears with correct SKU, name, price |
| 7 | Verify `square_tokens` has mapping entry | stock_id ↔ Square catalog_object_id stored |

### Pass Criteria
- Product exists in Square with correct fields
- Token mapping stored for future updates
- No errors in export log

---

## UAT-SQ-002: Import Square Orders via Staging

**@BABOK Related: FR-SQ-003**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | Completed Square payments exist; date range includes test data |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Navigate to Orders → Import Square Orders | Import form loads |
| 2 | Select "Stage-First" mode | Mode selected |
| 3 | Set date range, customer, location | Filters set |
| 4 | Click "Import" | System connects to Square API |
| 5 | Verify progress notifications | Payments retrieved, orders resolved |
| 6 | Navigate to Process Staging tab | Staged transactions listed |
| 7 | Select transaction, click "Process" | FA sales invoice created |
| 8 | Verify FA invoice has correct line items | SKU, quantity, price match Square order |
| 9 | Verify payment recorded against debtor | Payment matches Square payment amount |

### Pass Criteria
- Transactions staged successfully
- FA invoice created with correct items and amounts
- Payment recorded idempotently
- Import log records success

---

## UAT-SQ-003: Create Square Invoice from FA

**@BABOK Related: FR-SQ-007**

| Field | Value |
|-------|-------|
| **Actor** | Sales Operator |
| **Preconditions** | Square sandbox configured; "Square Invoice (Manual)" payment term exists; customer exists |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Create Sales Invoice for customer | Invoice form loads |
| 2 | Select payment term "Square Invoice (Manual)" | Term selected |
| 3 | Add line items (e.g., 2× $5.50) | Items added |
| 4 | Process Invoice | FA posts invoice |
| 5 | Verify notification shows Square Invoice URL | Public payment URL displayed |
| 6 | Open Square sandbox → Invoices | Invoice appears with status UNPAID |
| 7 | Verify `0_square_invoice_map` has entry | fa_invoice_no ↔ Square invoice stored |
| 8 | Click payment URL | Square payment page loads correctly |

### Pass Criteria
- Square Invoice created with correct amounts
- Public URL accessible and functional
- Mapping stored for idempotency

---

## UAT-SQ-004: Square Invoice Email Delivery

**@BABOK Related: FR-SQ-007**

| Field | Value |
|-------|-------|
| **Actor** | Sales Operator |
| **Preconditions** | Customer has valid email; payment term uses `square_invoice_email` |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Create invoice with `square_invoice_email` payment term | Invoice processed |
| 2 | Verify delivery_method=EMAIL on Square Invoice | Square shows email delivery |
| 3 | Check customer email for Square notification | Email received |
| 4 | Click payment link in email | Payment page loads with correct invoice |

### Pass Criteria
- Email sent from Square
- Payment link functional
- Invoice details correct

---

## UAT-SQ-005: Idempotent Square Invoice Creation

**@BABOK Related: FR-SQ-007**

| Field | Value |
|-------|-------|
| **Actor** | FA system (automatic) |
| **Preconditions** | Square Invoice already created for FA invoice #N |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Note Square Invoice ID for FA invoice #N | ID recorded |
| 2 | Re-trigger db_postwrite for same invoice | Hook fires again |
| 3 | Verify no duplicate Square Invoice | Only one Square Invoice exists |
| 4 | Verify same mapping returned | Same entry in `0_square_invoice_map` |

### Pass Criteria
- No duplicate Square Invoices or Orders
- Same mapping returned on re-trigger

---

## UAT-SQ-006: Non-Square Payment Terms Pass Through

**@BABOK Related: FR-SQ-007, FR-SQ-014**

| Field | Value |
|-------|-------|
| **Actor** | FA system (automatic) |
| **Preconditions** | Standard payment terms configured |
| **Priority** | Medium |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Create invoice with standard payment term (e.g., "Net 30") | Invoice posted normally |
| 2 | Verify no Square Invoice created | Square dashboard unchanged |
| 3 | Verify GL posting goes to default AR account | Normal FA behavior |

### Pass Criteria
- FA payment flow unchanged for non-Square terms
- No Square API calls made

---

## UAT-SQ-007: Customer Auto-Creation for Square Invoice

**@BABOK Related: FR-SQ-007, FR-SQ-004**

| Field | Value |
|-------|-------|
| **Actor** | FA system (automatic) |
| **Preconditions** | FA debtor has no existing `0_square_customer_mappings` entry |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Create invoice for unmapped debtor | Invoice processed |
| 2 | Verify Square Customer created | Customer in Square sandbox |
| 3 | Verify mapping in `0_square_customer_mappings` | debtor_no ↔ customer_id stored |
| 4 | Create second invoice for same debtor | Same Square Customer reused |

### Pass Criteria
- First invoice: Customer created, mapping stored
- Second invoice: Existing mapping used, no duplicate

---

## UAT-SQ-008: Refund Processing

**@BABOK Related: FR-SQ-006**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | Completed payment exists; refund amount ≤ payment |
| **Priority** | High |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Identify payment to refund | Payment details shown |
| 2 | Initiate refund with reason | Refund amount validated |
| 3 | Verify Square refund created | Refund in Square sandbox |
| 4 | Verify FA credit note created | Credit note linked to original invoice |
| 5 | Verify refund reference on original invoice | Audit trail complete |

### Pass Criteria
- Refund created in Square with correct amount
- FA credit note created and linked
- Original invoice shows refund reference

---

## UAT-SQ-009: Webhook Subscription Management

**@BABOK Related: FR-SQ-005**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | Square API credentials configured |
| **Priority** | Medium |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Navigate to Webhook Management page | Subscription list displayed |
| 2 | Create new subscription (URL, events) | Subscription created in Square |
| 3 | Verify webhook endpoint responds | Test event validated |
| 4 | Trigger `payment.created` event in sandbox | Event logged in `square_webhook_events` |
| 5 | Delete subscription | Subscription removed |

### Pass Criteria
- CRUD operations work on webhook subscriptions
- HMAC-SHA256 signature validated
- Events logged correctly

---

## UAT-SQ-010: Location Mapping for Inventory Push

**@BABOK Related: FR-SQ-010**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | FA locations exist; Square locations retrieved from API |
| **Priority** | Medium |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Navigate to Config → Location Mapping | Mapping UI loads |
| 2 | Verify Square locations fetched from API | Dropdown populated |
| 3 | Map FA location "Main" → Square location "FHS" | Mapping saved |
| 4 | Export product with QOH at "Main" | QOH pushed to Square "FHS" location |
| 5 | Verify Square inventory count matches FA QOH | Inventory accurate |

### Pass Criteria
- Location mapping saved and applied
- QOH correctly mapped to Square location
- Inventory counts match

---

## UAT-SQ-011: Payment Destinations GL Routing

**@BABOK Related: FR-SQ-014**

| Field | Value |
|-------|-------|
| **Actor** | FA Administrator |
| **Preconditions** | FA_PaymentDestinations installed; payment term mapped |
| **Priority** | Medium |

### Steps

| Step | Action | Expected Result |
|------|--------|----------------|
| 1 | Configure payment destination: "Visa MC" → "Credit Card Processing" bank | Mapping saved |
| 2 | Create invoice with "Visa MC" payment term | Invoice posted |
| 3 | Verify GL posting to mapped bank account | Money in correct GL account |
| 4 | Verify cash_sale forced to 1 | Cash transaction recorded |

### Pass Criteria
- GL redirected to configured bank account
- cash_sale=1 for mapped terms
- Unmapped terms follow normal FA behavior

---

## Summary

| UAT ID | Description | FR | Priority |
|--------|-------------|----|----------|
| UAT-SQ-001 | Export FA product to Square | FR-SQ-001 | High |
| UAT-SQ-002 | Import orders via staging | FR-SQ-003 | High |
| UAT-SQ-003 | Create Square Invoice | FR-SQ-007 | High |
| UAT-SQ-004 | Square Invoice email delivery | FR-SQ-007 | High |
| UAT-SQ-005 | Idempotent invoice creation | FR-SQ-007 | High |
| UAT-SQ-006 | Non-Square terms pass through | FR-SQ-007 | Medium |
| UAT-SQ-007 | Auto-create Square Customer | FR-SQ-004 | High |
| UAT-SQ-008 | Refund processing | FR-SQ-006 | High |
| UAT-SQ-009 | Webhook management | FR-SQ-005 | Medium |
| UAT-SQ-010 | Location mapping for inventory | FR-SQ-010 | Medium |
| UAT-SQ-011 | Payment Destinations GL routing | FR-SQ-014 | Medium |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-05-20 | KSFraser | Initial (UAT-SQ-001 to UAT-SQ-007) |
| 2.0 | 2026-08-20 | KSFraser | Added UAT-SQ-008 to UAT-SQ-011; renumbered with module prefix |
