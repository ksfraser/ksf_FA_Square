# Use Cases — ksf_FA_Square

## UC-09: Send Invoice to Square

| Element | Value |
|---------|-------|
| **ID** | UC-09 |
| **Name** | Send FA Invoice to Square for Remote Payment |
| **Trigger** | User creates sales invoice with `square_invoice*` payment term |
| **Primary Actor** | Sales Operator / POS Terminal |
| **Preconditions** | Square API configured, payment term mapped in `0_ksf_payment_destinations` |
| **Postconditions** | Square Invoice published, public URL displayed, mapping stored |

### Basic Flow
1. User creates a sales invoice in FA with a Square-Invoice payment term (e.g., "Square Invoice (Manual)")
2. FA calls `db_prewrite` hooks
3. ksf_FA_Square's `db_prewrite` detects `square_invoice*` destination via `resolvePaymentDestination()`
4. Sets `cash_sale=0` to suppress FA auto-payment
5. Stores cart data (line items, customer, destination) in static `$pendingSquareInvoice`
6. FA commits the transaction and calls `db_postwrite` hooks
7. ksf_FA_Square's `db_postwrite` retrieves stored cart data
8. Calls `SquareInvoiceService::createInvoiceFromFA()`:
   a. Checks for existing mapping (idempotent)
   b. Creates Square Order from FA line items (OrdersApi)
   c. Resolves or creates Square Customer from FA debtor (CustomersApi)
   d. Creates Square Invoice (DRAFT) with accepted payment methods (InvoicesApi)
   e. Publishes Invoice (InvoicesApi) — status becomes UNPAID
   f. Stores mapping in `0_square_invoice_map`
9. Displays notification with public payment URL

### Alternative Flows
- **Square Customer exists**: Skip creation, use existing customer_id from `0_square_customer_mappings`
- **Invoice already mapped**: Return existing mapping (idempotent)
- **API failure**: Log error, throw RuntimeException, transaction already committed in FA
- **No access token configured**: `buildSquareInvoiceService()` returns null, hook passes through
- **Email delivery** (`square_invoice_email`): Invoice sent to customer's email via Square
- **Card-on-file** (`square_invoice_card`): Automatic charge to saved card

---

## UC-10: Track Square Invoice Payment Status

| Element | Value |
|---------|-------|
| **ID** | UC-10 |
| **Name** | Track Square Invoice Payment Status |
| **Trigger** | Customer pays via Square payment link / phone app / card-on-file |
| **Primary Actor** | System (automatic) |
| **Preconditions** | Square Invoice was published (UNPAID status) |
| **Postconditions** | `0_square_invoice_map` status updated, FA import can match transaction |

### Basic Flow
1. Customer receives Square Invoice notification (email or link)
2. Customer clicks payment link → Square payment page
3. Customer enters card details and submits payment
4. Square processes payment, updates Invoice status to PAID
5. Staging import (API or CSV) retrieves the transaction
6. Import checks `0_square_invoice_map` for matching order_id or payment_id
7. If match found: allocate payment to existing FA invoice
8. Update mapping status to PAID

### Alternative Flows
- **Partial payment**: Status becomes PARTIALLY_PAID, import allocates partial amount
- **Invoice cancelled**: Status becomes CANCELED, mapping preserved for audit
- **Customer disputes**: Dispute tracked in Square, can be surfaced in CRM

---

## UC-11: Map FA Payment Term to Square Invoice Destination

| Element | Value |
|---------|-------|
| **ID** | UC-11 |
| **Name** | Configure Payment Term Destination |
| **Trigger** | Admin configures payment term → destination mapping |
| **Primary Actor** | FA Administrator |
| **Preconditions** | Both ksf_FA_Square and FA_PaymentDestinations modules installed |
| **Postconditions** | Payment term mapped to `square_invoice*` destination |

### Basic Flow
1. Admin opens Payment Destinations page (Setup → Payment Destinations)
2. Admin adds/edits a mapping: payment_term (e.g., "Square Invoice Manual") → destination name "square_invoice"
3. Mapping saved in `0_ksf_payment_destinations`
4. When customer uses this payment term on a sales invoice, ksf_FA_Square intercepts

### Alternative Flows
- **No mapping**: Normal FA payment flow continues
- **Non-Square destination**: FA_PaymentDestinations handles GL account routing
- **Multiple Square destinations**: `square_invoice` (manual), `square_invoice_email` (email), `square_invoice_card` (auto-charge)
