# User Acceptance Test Plan — ksf_FA_Square (Square-Invoice Feature)

## UAT-SQ-001: Create Square Invoice from FA Sales Invoice

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-001 |
| **Title** | Square Invoice created when posting sales invoice with square_invoice payment term |
| **Priority** | High |
| **Preconditions** | Square sandbox configured, "Square Invoice (Manual)" payment term exists, customer "Donald Easter LLC" has payment term set |

### Steps
1. Log into FA as admin
2. Navigate to Sales → Sales Invoice Entry → New Sales Invoice
3. Select customer "Donald Easter LLC"
4. Select payment term "Square Invoice (Manual)" (terms_indicator=8)
5. Add line items (e.g., "Wedding Embellishments" × 2 @ $5.50)
6. Click "Process Invoice"
7. Verify FA notification shows Square Invoice public URL
8. Log into Square sandbox dashboard → Invoices
9. Verify invoice appears with status UNPAID
10. Verify line items, amounts match FA invoice

### Expected Result
- FA invoice posted successfully (no error)
- Square Invoice created with status UNPAID
- Public payment URL displayed in FA notification
- Line items and amounts match between FA and Square

---

## UAT-SQ-002: Square Invoice with Email Delivery

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-002 |
| **Title** | Email delivery sends Square Invoice to customer email |
| **Priority** | High |
| **Preconditions** | Customer has valid email address, payment term uses `square_invoice_email` destination |

### Steps
1. Create sales invoice with `square_invoice_email` payment term
2. Verify Square Invoice created with delivery_method=EMAIL
3. Check customer's email for Square Invoice notification
4. Click payment link in email
5. Verify Square payment page loads with correct invoice details

### Expected Result
- Email sent from Square to customer
- Payment link works and opens correct invoice
- Invoice details (items, amounts) are correct

---

## UAT-SQ-003: Idempotent Invoice Creation

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-003 |
| **Title** | Re-posting same invoice returns existing mapping |
| **Priority** | High |
| **Preconditions** | Square Invoice already created for FA invoice #N |

### Steps
1. Note the Square Invoice ID for FA invoice #N
2. Simulate re-posting the same invoice (trigger db_postwrite again)
3. Verify no duplicate Square Invoice created
4. Verify same mapping returned

### Expected Result
- Only one Square Invoice exists in Square
- Same mapping returned from `0_square_invoice_map`
- No duplicate Square Orders or Invoices created

---

## UAT-SQ-004: Auto-Create Square Customer

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-004 |
| **Title** | Square Customer auto-created from FA debtor on first invoice |
| **Priority** | High |
| **Preconditions** | FA debtor has no existing `0_square_customer_mappings` entry |

### Steps
1. Create sales invoice for a debtor with no Square customer mapping
2. Verify Square Customer created in Square sandbox
3. Verify mapping stored in `0_square_customer_mappings`
4. Create another invoice for same debtor
5. Verify same Square Customer reused (no duplicate)

### Expected Result
- First invoice: Square Customer created, mapping stored
- Second invoice: Existing mapping used, no duplicate customer

---

## UAT-SQ-005: Non-Square Payment Terms Pass Through

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-005 |
| **Title** | Normal FA payment flow for non-Square payment terms |
| **Priority** | Medium |
| **Preconditions** | Standard payment terms (e.g., "Due 15th Of the Following Month") configured |

### Steps
1. Create sales invoice with standard payment term (terms_indicator=1)
2. Verify normal FA payment flow (no Square Invoice created)
3. Verify GL posting goes to normal AR account

### Expected Result
- FA payment flow unchanged for non-Square terms
- No Square Invoice created
- GL posting as normal

---

## UAT-SQ-006: Payment Destinations Module GL Routing

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-006 |
| **Title** | FA_PaymentDestinations routes GL for mapped payment terms |
| **Priority** | Medium |
| **Preconditions** | FA_PaymentDestinations installed, payment term mapped to bank account |

### Steps
1. Configure payment destination: payment_term → bank_account = 1200 (Checking)
2. Create sales invoice with that payment term (non-Square destination)
3. Verify GL posting goes to bank account 1200
4. Verify cash_sale forced to 1

### Expected Result
- GL posting redirected to configured bank account
- cash_sale=1 (cash transaction)

---

## UAT-SQ-007: Square Invoice Sandbox Email Delivery

| Field | Value |
|-------|-------|
| **ID** | UAT-SQ-007 |
| **Title** | Verify email delivery to kevin@ksfraser.ca |
| **Priority** | High |
| **Preconditions** | Square sandbox configured with sandbox email |

### Steps
1. Create Square Invoice for Kevin Fraser (kevin@ksfraser.ca) with EMAIL delivery
2. Check kevin@ksfraser.ca inbox for Square sandbox email
3. Open email and click "Pay Invoice" link
4. Verify payment page shows correct invoice amount ($40.00 CAD)
5. Enter test card details and submit payment
6. Verify Square Invoice status changes to PAID

### Expected Result
- Email received from Square sandbox
- Payment page loads with correct details
- Payment processing works end-to-end
