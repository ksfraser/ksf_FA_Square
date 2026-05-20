# Requirements Traceability Matrix (RTM) — ksf_FA_Square

## Matrix Legend
- **FR**: Functional Requirement
- **NFR**: Non-Functional Requirement
- **UC**: Use Case
- **TC**: Test Case

---

## RTM: Requirement -> Use Case -> Code File -> Test

| Req ID | Requirement Summary | Use Case | Code File(s) | Test |
|--------|--------------------|----------|--------------|------|
| **FR-01.01** | Export items to Square Catalog | UC-01: Export Inventory | `square.php` (i_export) | TC-01 |
| **FR-01.02** | Map stock_id to SKU | UC-01 | `square.php` (square_variation) | TC-01.02 |
| **FR-01.03** | Map description to name/description | UC-01 | `square.php` (square_v2body) | TC-01.03 |
| **FR-01.04** | Map category to CatalogCategory | UC-01 | `square.php` (square_v2body) | TC-01.04 |
| **FR-01.05** | Map price to price_money | UC-01 | `square.php` (square_variation) | TC-01.05 |
| **FR-01.06** | Push stock-on-hand to Square | UC-01 | `inventory_api.php` (new) | TC-01.06 |
| **FR-01.07** | Upload item images | UC-01 | `square.php` (uploadItemImage) | TC-01.07 |
| **FR-01.08** | Map FA tax types to CatalogTax | UC-01 | `square.php` (square_v2body) | TC-01.08 |
| **FR-01.09** | Batch upsert catalog objects | UC-01 | `catalog_api.php` (new) | TC-01.09 |
| **FR-01.10** | Delete deactivated items from Square | UC-01 | `square.php` (i_export) | TC-01.10 |
| **FR-01.11** | Track export status | UC-01 | square table (extend) | TC-01.11 |
| **FR-02.01** | Create Square order from FA invoice | UC-02: Terminal Payment | `terminal_api.php` (new) | TC-02.01 |
| **FR-02.02** | Initiate Terminal checkout | UC-02 | `terminal_api.php` (new) | TC-02.02 |
| **FR-02.03** | Support device selection | UC-02 | `terminal_api.php` (new) | TC-02.03 |
| **FR-02.04** | Poll checkout status | UC-02 | `terminal_api.php` (new) | TC-02.04 |
| **FR-02.05** | Handle cancellation/timeout | UC-02 | `terminal_api.php` (new) | TC-02.05 |
| **FR-02.06** | Record Square payment ref on invoice | UC-02 | `square.php` (o_import) | TC-02.06 |
| **FR-03.01** | Retrieve completed payments by date | UC-03: Import Sales | `square.php` (square_locs) | TC-03.01 |
| **FR-03.02** | Retrieve full order details | UC-03 | `square.php` (o_import) | TC-03.02 |
| **FR-03.03** | Map location to cust_branch | UC-03 | `square.php` (o_import) | TC-03.03 |
| **FR-03.04** | Resolve SKU to stock_id | UC-03 | `square.php` (o_import) | TC-03.04 |
| **FR-03.05** | Create FA sales invoices | UC-03 | `square.php` (o_import) | TC-03.05 |
| **FR-03.06** | Map Square taxes to FA tax types | UC-03 | `square.php` (o_import) | TC-03.06 |
| **FR-03.07** | Map discounts to adjustment items | UC-03 | `square.php` (o_import) | TC-03.07 |
| **FR-03.08** | Record payment against FA invoice | UC-03 | `square.php` (o_import) | TC-03.08 |
| **FR-03.09** | Handle tips | UC-03 | `square.php` (o_import) | TC-03.09 |
| **FR-03.10** | Deduplicate imports | UC-03 | `square.php` (paymentExistsInFA) | TC-03.10 |
| **FR-03.11** | Log import results | UC-03 | `square_import_log` (new table) | TC-03.11 |
| **FR-04.01** | Search Square customers by ref ID | UC-04: Customer Sync | `customers_api.php` (new) | TC-04.01 |
| **FR-04.02** | Create Square customers from FA | UC-04 | `customers_api.php` (new) | TC-04.02 |
| **FR-04.03** | Store Square customer_id in FA | UC-04 | `square_mappings` (new table) | TC-04.03 |
| **FR-04.04** | Create FA debtors from Square customers | UC-04 | `customers_api.php` (new) | TC-04.04 |
| **FR-04.05** | Match customers by email/name/reference | UC-04 | `customers_api.php` (new) | TC-04.05 |
| **FR-05.01** | Accept CSV uploads | UC-05: CSV Import | `csv_import.php` (new) | TC-05.01 |
| **FR-05.02** | Stage CSV data | UC-05 | `square_staging_csv` (new table) | TC-05.02 |
| **FR-05.03** | Validate CSV structure | UC-05 | `csv_import.php` (new) | TC-05.03 |
| **FR-05.04** | Match CSV to FA transactions | UC-05 | `csv_import.php` (new) | TC-05.04 |
| **FR-05.05** | Flag unmatched records | UC-05 | `csv_import.php` (new) | TC-05.05 |
| **FR-05.06** | Merge matched transactions | UC-05 | `csv_import.php` (new) | TC-05.06 |
| **FR-05.07** | Review UI before finalizing | UC-05 | `csv_import.php` (new) | TC-05.07 |
| **FR-06.01** | Store access token | UC-06: Configuration | `square.php` (show/create) | TC-06.01 |
| **FR-06.02** | Sandbox/production switch | UC-06 | `square.php` (show) | TC-06.02 |
| **FR-06.03** | Last import date tracking | UC-06 | square table | TC-06.03 |
| **FR-06.04** | Customer/branch mapping | UC-06 | square table | TC-06.04 |
| **FR-06.05** | Activity log | UC-06 | `square_import_log` (new) | TC-06.05 |
| **FR-06.06** | Error message display | UC-06 | `square.php` (get_error_status) | TC-06.06 |
| **NFR-01** | PHP 7.3/7.4 compatibility | All | composer.json | PHP unit |
| **NFR-02** | FA 2.4.x integration | All | hooks.php | Manual |
| **NFR-03** | Square SDK ^40.0.0 | All | composer.json | CI |
| **NFR-04** | TB_PREF convention | All | square.php | Code review |
| **NFR-05** | Token security | All | square.php | Code review |
| **NFR-06** | Idempotency keys | UC-01/02/03 | API wrappers | Code review |
| **NFR-07** | Error handling | All | square.php | TC-07 |
| **NFR-08** | Batch operations | UC-01 | catalog_api.php | Perf test |

---

## Use Case Index

| UC ID | Name | Trigger | Primary Actor |
|-------|------|---------|---------------|
| UC-01 | Export Inventory to Square | User clicks "Export Inventory" | FA Administrator |
| UC-02 | Process Terminal Payment | User creates invoice + clicks "Charge Card" | Sales Operator |
| UC-03 | Import Sales from Square | Scheduled / User clicks "Import Orders" | FA Administrator |
| UC-04 | Sync Customers | During export/import processes | System (automatic) |
| UC-05 | Import CSV from Square Dashboard | User uploads CSV file | FA Administrator |
| UC-06 | Configure Integration | User opens Configuration page | FA Administrator |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial draft from Square SDK v40 API analysis |
