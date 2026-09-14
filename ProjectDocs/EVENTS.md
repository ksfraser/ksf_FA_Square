# KSF FrontAccounting — Event Registry

Master list of all events emitted and listened to across KSF modules. Used to track cross-module communication and ensure no duplicate/conflicting events.

**Format:**
```
Event Name: {object}_{action}

Emitters: (modules that emit this event)
  - ksf_FA_ModuleName: emit condition

Listeners: (modules that listen to this event)
  - ksf_FA_ModuleName: action taken

Payload:
  - field: description
```

---

## Inventory / Stock Events

### `stock_reserved`

Stock was successfully reserved for an order.

**Emitters:**
- `ksf_FA_StockReservations`: When SO created and stock reserved successfully

**Listeners:**
- `ksf_FA_Teams`: Create salesman followup task (if delivery date is future)

**Payload:**
```php
[
    'module'      => 'ksf_FA_StockReservations',
    'event'       => 'stock_reserved',
    'timestamp'   => '2024-01-15 14:30:00',
    'so_order_no' => 12345,
    'items'       => [
        ['stock_id' => 'SKU-001', 'quantity' => 10, 'location' => 'MAIN'],
    ],
]
```

---

### `stock_released`

Stock reservation was released (fulfilled, voided, cancelled).

**Emitters:**
- `ksf_FA_StockReservations`: When SO voided, delivery completed, or cancelled

**Listeners:**
- `ksf_FA_SuggestedPO`: Release related purchase suggestions
- `ksf_FA_Teams`: Create followup task if needed

**Payload:**
```php
[
    'module'       => 'ksf_FA_StockReservations',
    'event'        => 'stock_released',
    'timestamp'    => '2024-01-15 14:30:00',
    'so_order_no'  => 12345,
    'items'        => [
        ['stock_id' => 'SKU-001', 'quantity' => 10],
    ],
    'reason'       => 'voided',  // or 'delivered', 'cancelled'
]
```

---

### `stock_insufficient`

Stock level below threshold during SO creation.

**Emitters:**
- `ksf_FA_StockReservations`: When SO creation fails stock check

**Listeners:**
- `ksf_FA_SuggestedPO`: Check lead times, create suggestion
- `ksf_FA_Teams`: Create salesman followup task

**Payload:**
```php
[
    'module'      => 'ksf_FA_StockReservations',
    'event'       => 'stock_insufficient',
    'timestamp'   => '2024-01-15 14:30:00',
    'so_order_no' => 12345,
    'items'       => [
        ['stock_id' => 'SKU-001', 'requested' => 10, 'available' => 3],
    ],
]
```

---

## Purchase Order Events

### `suggested_po_created`

A suggested PO was auto-generated.

**Emitters:**
- `ksf_FA_SuggestedPO`: After nightly recalc or stock insufficient trigger

**Listeners:**
- `ksf_FA_Teams`: Create purchasing task

**Payload:**
```php
[
    'module'         => 'ksf_FA_SuggestedPO',
    'event'          => 'suggested_po_created',
    'timestamp'      => '2024-01-15 14:30:00',
    'suggestion_id'  => 789,
    'supplier_id'    => 42,
    'items'          => [
        ['stock_id' => 'SKU-001', 'qty' => 100, 'unit_cost' => 5.99],
    ],
    'reason'         => 'stock_insufficient',  // or 'lead_time_coverage', 'moq_gap'
    'needed_by'      => '2024-02-15',
    'order_by'       => '2024-02-01',
]
```

---

### `suggested_po_approved`

A suggested PO was approved by user.

**Emitters:**
- `ksf_FA_SuggestedPO`: When user approves suggestion

**Listeners:**
- `ksf_FA_Teams`: Create submission task

**Payload:**
```php
[
    'module'        => 'ksf_FA_SuggestedPO',
    'event'         => 'suggested_po_approved',
    'timestamp'      => '2024-01-15 14:30:00',
    'suggestion_id' => 789,
    'supplier_id'   => 42,
    'approved_by'   => 15,  // user_id
]
```

---

### `po_created`

A PO was created (manual or from suggestion).

**Emitters:**
- `ksf_FA_SuggestedPO`: When suggestion converted to PO or manual PO created

**Listeners:**
- `ksf_FA_Teams`: Create receiving task (warehouse) + AP task
- `ksf_FA_Quality`: Queue QA check

**Payload:**
```php
[
    'module'       => 'ksf_FA_SuggestedPO',
    'event'        => 'po_created',
    'timestamp'    => '2024-01-15 14:30:00',
    'po_number'    => 'PO/2024/00123',
    'supplier_id'  => 42,
    'suggestion_id' => 789,  // null if manual
    'created_by'   => 'system',  // or user_id
]
```

---

### `grn_received`

Goods received against a PO.

**Emitters:**
- `ksf_FA_PurchaseOrderTracking`: When GRN posted

**Listeners:**
- `ksf_FA_Teams`: Create put-away task (warehouse)
- `ksf_FA_Quality`: Queue QA inspection

**Payload:**
```php
[
    'module'      => 'ksf_FA_PurchaseOrderTracking',
    'event'       => 'grn_received',
    'timestamp'   => '2024-01-15 14:30:00',
    'po_number'   => 'PO/2024/00123',
    'grn_number'  => 'GRN/2024/00056',
    'supplier_id' => 42,
    'items'       => [
        ['stock_id' => 'SKU-001', 'qty_received' => 100],
    ],
]
```

---

## Sales Order Events

### `so_created`

A sales order was created.

**Emitters:**
- `ksf_FA_StockReservations`: After successful stock reservation

**Listeners:**
- `ksf_FA_Teams`: Create confirm-with-customer task (if delivery date future)

**Payload:**
```php
[
    'module'      => 'ksf_FA_StockReservations',
    'event'       => 'so_created',
    'timestamp'   => '2024-01-15 14:30:00',
    'so_order_no' => 12345,
    'customer_id' => 67,
    'items'       => [...],
    'delivery_date' => '2024-02-15',
]
```

---

### `so_delivered`

A sales order was delivered.

**Emitters:**
- `ksf_FA_StockReservations`: On ST_CUSTDELIVERY

**Listeners:**
- `ksf_FA_Teams`: Create AR task for invoicing

**Payload:**
```php
[
    'module'       => 'ksf_FA_StockReservations',
    'event'        => 'so_delivered',
    'timestamp'    => '2024-01-15 14:30:00',
    'so_order_no'  => 12345,
    'delivery_no'  => 'DO/2024/00089',
    'items'        => [...],
]
```

---

### `so_invoiced`

A sales order was invoiced.

**Emitters:**
- `ksf_FA_StockReservations`: On ST_SALESINVOICE

**Listeners:**
- `ksf_FA_Teams`: Mark AR task complete, create collection followup if needed

**Payload:**
```php
[
    'module'      => 'ksf_FA_StockReservations',
    'event'       => 'so_invoiced',
    'timestamp'   => '2024-01-15 14:30:00',
    'so_order_no' => 12345,
    'invoice_no' => 'INV/2024/00123',
    'total'      => 1599.99,
]
```

---

## Quality Events

### `quality_issue_created`

A quality issue/8D was logged.

**Emitters:**
- `ksf_FA_Quality`: When issue created

**Listeners:**
- `ksf_FA_Teams`: Create quality team task

**Payload:**
```php
[
    'module'      => 'ksf_FA_Quality',
    'event'       => 'quality_issue_created',
    'timestamp'   => '2024-01-15 14:30:00',
    'issue_id'    => 45,
    'stock_id'    => 'SKU-001',
    'po_number'   => 'PO/2024/00123',  // optional
    'so_order_no' => 12345,  // optional
    'severity'    => 'major',  // or 'minor', 'critical'
]
```

---

## Task Events

### `task_created`

A task was created in Teams.

**Emitters:**
- `ksf_FA_Teams`: After creating any task

**Listeners:**
- (informational only — other modules may listen for task lifecycle)

**Payload:**
```php
[
    'module'       => 'ksf_FA_Teams',
    'event'        => 'task_created',
    'timestamp'    => '2024-01-15 14:30:00',
    'task_id'      => 456,
    'team_type'    => 'purchasing',
    'title'        => 'Review PO for SKU-001',
    'description'  => 'Suggested PO #789 needs approval',
    'due_date'     => '2024-02-01',
    'related'      => [
        'type' => 'suggested_po',
        'id'   => 789,
    ],
]
```

---

### `task_completed`

A task was marked complete.

**Emitters:**
- `ksf_FA_Teams`: When task status changed to complete

**Listeners:**
- (informational — for audit trail)

**Payload:**
```php
[
    'module'     => 'ksf_FA_Teams',
    'event'      => 'task_completed',
    'timestamp' => '2024-01-15 14:30:00',
    'task_id'    => 456,
    'completed_by' => 15,  // user_id
]
```

---

## Module-to-Module Query Events

### `stock_level_query`

Request current stock level for an item.

**Emitters:**
- Any module needing stock level

**Listeners:**
- `ksf_FA_StockReservations`: Returns available qty

**Payload (request):**
```php
[
    'module'    => 'ksf_FA_SuggestedPO',
    'event'     => 'stock_level_query',
    'stock_id'  => 'SKU-001',
]
```

**Payload (response via $data):**
```php
[
    'available' => 150,
    'on_order'   => 50,
    'location'   => 'MAIN',
]
```

---

## Event Lifecycle Summary

| Event | Emitted When | Primary Listener |
|-------|-------------|------------------|
| `stock_reserved` | SO created, stock available | Teams (sales followup) |
| `stock_insufficient` | SO creation fails stock check | SuggestedPO, Teams |
| `stock_released` | SO voided/delivered | SuggestedPO, Teams |
| `suggested_po_created` | Auto-suggestion generated | Teams (purchasing task) |
| `suggested_po_approved` | User approves suggestion | Teams |
| `po_created` | PO submitted to vendor | Teams (warehouse), Quality |
| `grn_received` | Goods received | Teams (put-away), Quality |
| `so_created` | Sales order created | Teams (confirm with customer) |
| `so_delivered` | Delivery completed | Teams (AR invoicing) |
| `so_invoiced` | Invoice posted | Teams (collection) |
| `quality_issue_created` | Quality issue logged | Teams (quality team) |
| `task_created` | Task created | (audit trail) |
| `task_completed` | Task completed | (audit trail) |

---

*Document Version: 1.0.0*
*Maintained in: ~/Documents/EVENTS.md*
*Cross-reference: ProjectDcs/Event-Driven Architecture.md*
---

## Project Services Events (BR-TIME-001 / BR-EXPENSE-001 / BR-APPROVAL-001)

### Status Tracking Events

### `timesheet_status_changed`
Status: draft → submitted → approved → rejected/denied → returned
Comment logged to `approval_steps.comments` for each transition.

**Emitters:** `ksf_FA_Timesheets`
**Listeners:** `ksf_FA_Teams` (approval chain tracking)
**Comments:** Mandatory field for each status change

---

### `expense_status_changed`
Status: draft → submitted → pending_approval → approved/denied/rejected → reimbursed
Comment logged for each transition.

**Emitters:** `ksf_FA_TravelExpense`
**Listeners:** `ksf_FA_Teams`, `ksf_FA_Sales` (billing module traps at approval)
**Comments:** Mandatory field

---

### Auto-Approve Events

### `expense_check_auto_approve`
Query: Check auto-approve conditions.
**Conditions:** Meals < $50, Hotels < $200, Small amount < $25.
**Returns:** `auto_approve` (bool), `reason` (string).

**Emitters (query):** `ksf_FA_TravelExpense`
**Listeners:** `ksf_Finance` or dedicated package
**Use case:** Auto-approve small expenses without manager review.

---

### `timesheet_check_auto_approve`
Query: Check auto-approve conditions.
**Conditions:** Hours within range (e.g., 40h ± 2h), No overtime, Only regular hours.
**Returns:** `auto_approve` (bool), `reason`.

**Emitters (query):** `ksf_FA_Timesheets`

---

### Expense-Time Correlation Events

### `expense_check_time_correlation`
Query: Find time entries for same project/activity within +/- 1 day.
**Returns:** `related_time_entries[]`, `date_range`.

---

### `time_check_expense_correlation`
Query: Find expenses for same project/activity within +/- 1 day.
**Returns:** `related_expenses[]`, `expense_range`.

---

## Cross-Domain Event Flow Example

```
Timesheet Entry (BR-TIME-001)
  → Submit (hook: timesheet_submitted)
      → Approval Chain (hook: approval_request → Teams module)
          → Manager/Delegated Approver
              → Approve (hook: approval_approve + timesheet_approved)
                  → Payroll Export (hook: timesheet_export_payroll → Payroll)
                  → GL Entry (hook: gl_entry_create → Finance)
                  → Billing Module (hook: time_get_billing_rule + billing applied)
                      → Batch Item Created (table: billing_batch_items)
                          → AR Batch → Invoice Generation

Expense Entry (BR-EXPENSE-001)
  → Submit (hook: expense_submitted)
      → Approval Chain (hook: approval_request)
          → Approve (hook: approval_approve + expense_approved)
              → Contract Billing (hook: expense_approval_billing_applied)
                  → Direct Delivery (table: billing_batch_items)
                      → AR Batch → Invoice Generation
              → Reimbursement (hook: expense_reimbursed → GL + Payroll)
```

---

## Users & Contacts Workflow Lanes (hook_invoke_all — native FA dispatcher)

### `user_create`
Fires on Users module save (hooks/ksf_FA_Users). Calls the native FA seam
`add_user()` (2.4.3 admin/db/users_db.inc:11) with the DEFAULT USER ACCESS LEVEL
designated from the FA security-role catalogue on the Users pages/users.php UI.

**Emitters:** `ksf_FA_Users`
**Listeners:** `ksf_FA_Employee` (rides the returned user xref lane)
**BABOK:** @BR-009, @FR-009-001..

### `contact_create`
Fires on Contacts module save (hooks/ksf_FA_Contacts). Calls the native FA seam
`add_crm_person()` + `add_crm_contact()` (2.4.3 includes/db/crm_contacts_db.inc)
with the CONTACT TYPE designated from the FA crm category catalogue on the
Contacts pages/contacts.php UI.

**Emitters:** `ksf_FA_Contacts`
**Listeners:** `ksf_FA_RBAC` (contact-type access lane)
**BABOK:** @BR-010, @FR-010-001..

---

### `timesheet_status_changed`  **← correction confirmed: the FA ROAD the lane the
### `user_create` lane seat rides (hook_invoke_all rides the SAME dispatcher;
### the EVENTS.md the user's memory names seats BOTH — the Calendar lane was MY
### misparent, the MOTHER is this Documents file)**

## ksf_FA_Users & ksf_FA_Contacts — new hook lanes (FR-009 / FR-010)

### `user_create`
Fired from ksf_FA_Users/hooks.php init() via FA-native hook_invoke_all()
(2.4.3 includes/hooks.inc:288) on the Users pages/users.php save.

**Emitters:** ksf_FA_Users
**Listeners:** ksf_FA_Employee, ksf_FA_RBAC (default-access lane)
**Returns:** the native add_user() row the Users src/UsersController rides;
role_id = the DEFAULT USER ACCESS LEVEL the pages/users.php dropdown designates.

---

### `add_contact`
Fired from ksf_FA_Contacts/hooks.php init() via FA-native hook_invoke_all()
on the Contacts pages/contacts.php save.

**Emitters:** ksf_FA_Contacts
**Listeners:** ksf_FA_Employee, ksf_FA_RBAC (contact-type lane)
**Returns:** the native add_crm_person() + add_crm_contact() rows the Contacts
src/ContactsController rides; type = the CONTACT TYPE the pages/contacts.php
crm-category dropdown designates.

---

---

## ksf_FA_Users — `user_create` lane (The default-user-access designation UI)

Fired by `ksf_FA_Users/hooks.php init()` via FA-native `hook_invoke_all()` on
the Users pages/users.php save.

**Emitters:** `ksf_FA_Users`
**Listeners:** `ksf_FA_Employee` (the employee's user-xref lane rides the
returned user id), `ksf_FA_RBAC` (the default-access lane the UI designates
rides the role_id drop-down).
**FA-native call:** `add_user()` (FA 2.4.3 `admin/db/users_db.inc:11`) — the
`UsersController::addNative()` src/ call seats the SAME signature.
**Designation:** UNISON — the page's "default user access level" dropdown
lists FA's OWN security-role catalogue (`get_all_security_roles`); the lane
seats the DESIGNATED access-level default.

---

## ksf_FA_Contacts — `contact_create` lane (The contact-type designation UI)

Fired by `ksf_FA_Contacts/hooks.php init()` via FA-native `hook_invoke_all()` on
the Contacts pages/contacts.php save.

**Emitters:** `ksf_FA_Contacts`
**Listeners:** `ksf_FA_Employee` (employee-contact xref rides the returned
contact id), `ksf_FA_RBAC` (contact-type access lane).
**FA-native call:** `add_crm_person()` + `add_crm_contact()` (FA 2.4.3
`includes/db/crm_contacts_db.inc:251`) — the `ContactsController::addNative()`
src/ call seats the SAME signature.
**Designation:** UNISON — the page's "contact type" dropdown lists FA's OWN
crm-category catalogue (`get_crm_categories(false)`); the lane seats the
DESIGNATED contact-type default.

---
