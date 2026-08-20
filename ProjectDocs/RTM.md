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
| **FR-01.01** | Export items to Square Catalog | UC-01: Export Inventory | `src/Push/CatalogExporter.php` | TC-01 |
| **FR-01.02** | Map stock_id to SKU | UC-01 | `src/Push/CatalogExporter.php` | TC-01.02 |
| **FR-01.03** | Map description to name/description | UC-01 | `src/Push/CatalogExporter.php` | TC-01.03 |
| **FR-01.04** | Map category to CatalogCategory | UC-01 | `src/Push/CatalogExporter::resolveCategory()` | TC-01.04 |
| **FR-01.05** | Map price to price_money | UC-01 | `src/Push/CatalogExporter.php` | TC-01.05 |
| **FR-01.06** | Push stock-on-hand to Square | UC-01 | `src/Push/CatalogExporter::pushInventory()` | TC-01.06 |
| **FR-01.07** | Upload item images | UC-01 | `pages/export.php` (to refactor into service) | TC-01.07 |
| **FR-01.08** | Map FA tax types to CatalogTax | UC-01 | `src/Push/CatalogExporter::resolveTax()` | TC-01.08 |
| **FR-01.09** | Batch upsert catalog objects | UC-01 | `src/Push/CatalogExporter::batchUpsertProducts()` | TC-01.09 |
| **FR-01.10** | Delete deactivated items from Square | UC-01 | `src/Push/CatalogExporter::deleteProduct()` | TC-01.10 |
| **FR-01.11** | Sanitize FA data (latin1→UTF-8) | UC-01 | `src/Push/CatalogExporter::sanitizeUtf8()` | TC-01.11 |
| **FR-01.12** | Configurable item limit for export | UC-01 | `pages/export.php` (`max_items` field) | Manual |
| **FR-01.13** | Per-item progress logging | UC-01 | `pages/export.php` (detailed notifications) | Manual |
| **FR-01.14** | Track export status + tokens | UC-01 | `FA_SquareUpTokens` integration (future) | TC-01.14 |
| **FR-02.01** | Create Square order from FA invoice | UC-02: Terminal Payment | `src/Push/TerminalPayment::createOrderFromInvoice()` | TC-02.01 |
| **FR-02.02** | Initiate Terminal checkout | UC-02 | `src/Push/TerminalPayment::createTerminalCheckout()` | TC-02.02 |
| **FR-02.03** | Support device selection | UC-02 | `src/Push/TerminalPayment.php` | TC-02.03 |
| **FR-02.04** | Poll checkout status | UC-02 | `src/Push/TerminalPayment::getCheckoutStatus()` | TC-02.04 |
| **FR-02.05** | Handle cancellation/timeout | UC-02 | `src/Push/TerminalPayment::cancelCheckout()` | TC-02.05 |
| **FR-02.06** | Record Square payment ref on invoice | UC-02 | `src/Staging/InvoiceCreator::recordPayment()` | TC-02.06 |
| **FR-03.01** | Retrieve completed payments by date | UC-03: Import Sales | `src/Pull/OrderImporter::listPayments()` | TC-03.01 |
| **FR-03.02** | Retrieve full order details | UC-03 | `src/Pull/OrderImporter::getPaymentWithOrder()` | TC-03.02 |
| **FR-03.03** | Map location to cust_branch | UC-03 | `src/Staging/CustomerMatcher::findOrCreateBranch()` | TC-03.03 |
| **FR-03.04** | Resolve SKU to stock_id | UC-03 | `pages/import.php` (SKU resolution inline) | TC-03.04 |
| **FR-03.05** | Create FA sales invoices | UC-03 | `src/Staging/InvoiceCreator::createSalesInvoice()` | TC-03.05 |
| **FR-03.06** | Map Square taxes to FA tax types | UC-03 | `pages/import.php` (tax mapping inline) | TC-03.06 |
| **FR-03.07** | Map discounts to adjustment items | UC-03 | `pages/import.php` (adjustment inline) | TC-03.07 |
| **FR-03.08** | Record payment against FA invoice | UC-03 | `src/Staging/InvoiceCreator::recordPayment()` | TC-03.08 |
| **FR-03.09** | Handle tips | UC-03 | `pages/import.php` (tip inline) | TC-03.09 |
| **FR-03.10** | Deduplicate imports | UC-03 | `pages/import.php` (check against sales_orders) | TC-03.10 |
| **FR-03.11** | Log import results | UC-03 | `src/Staging/StagingTableManager.php` / `square_import_log` | TC-03.11 |
| **FR-04.01** | Search Square customers by ref ID | UC-04: Customer Sync | `src/Staging/CustomerMatcher.php` | TC-04.01 |
| **FR-04.02** | Create Square customers from FA | UC-04 | `src/Staging/CustomerMatcher.php` | TC-04.02 |
| **FR-04.03** | Store Square customer_id in FA | UC-04 | `src/Staging/CustomerMatcher::linkSquareCustomer()` | TC-04.03 |
| **FR-04.04** | Create FA debtors from Square customers | UC-04 | `src/Staging/CustomerMatcher::findOrCreateDebtor()` | TC-04.04 |
| **FR-04.05** | Match customers by email/name/reference | UC-04 | `src/Staging/CustomerMatcher.php` | TC-04.05 |
| **FR-05.01** | Accept CSV uploads | UC-05: CSV Import | `FA_ImportSquareUp` (existing module) | TC-05.01 |
| **FR-05.02** | Stage CSV data | UC-05 | Unified staging tables (future) | TC-05.02 |
| **FR-05.03** | Validate CSV structure | UC-05 | CSV parser in `FA_ImportSquareUp` | TC-05.03 |
| **FR-05.04** | Match CSV to FA transactions | UC-05 | `FA_ImportSquareUp` matching logic | TC-05.04 |
| **FR-05.05** | Flag unmatched records | UC-05 | `FA_ImportSquareUp` review UI | TC-05.05 |
| **FR-05.06** | Merge matched transactions | UC-05 | `FA_ImportSquareUp` processing | TC-05.06 |
| **FR-05.07** | Review UI before finalizing | UC-05 | `FA_ImportSquareUp` review UI | TC-05.07 |
| **FR-06.01** | Source-agnostic staging tables | UC-03/05/07: Unified Import | `ksf_ImportStaging` (planned package) | TC-06.01 |
| **FR-06.02** | Common order schema | UC-07 | `ksf_ImportStaging` schema | TC-06.02 |
| **FR-06.03** | Per-record import status | UC-07 | `ksf_ImportStaging` status column | TC-06.03 |
| **FR-06.04** | Shared staging library | UC-07 | `ksf_ImportStaging` Composer package | TC-06.04 |
| **FR-06.05** | DRY across Square + WooCommerce | UC-07 | composer.json dependencies | TC-06.05 |
| **FR-07.01** | Store access token | UC-06: Configuration | `src/Config/Settings.php` | TC-07.01 |
| **FR-07.02** | Sandbox/production switch | UC-06 | `src/Config/Settings::getEnvironment()` | TC-07.02 |
| **FR-07.03** | Last import date tracking | UC-06 | `src/Config/Settings::getLastImportDate()` | TC-07.03 |
| **FR-07.04** | Customer/branch mapping | UC-06 | `src/Config/Settings::getDestinationCustomer()` | TC-07.04 |
| **FR-07.05** | Activity log | UC-06 | `square_import_log` table | TC-07.05 |
| **FR-07.06** | Error message display | UC-06 | `src/Exceptions/SquareException.php` | TC-07.06 |
| **FR-08.01** | UI to map FA locations to Square locations | UC-08: Location Mapping | Location mapping page (new) | TC-08.01 |
| **FR-08.02** | Store location mappings in DB | UC-08 | `sql/` (new table `square_location_mappings`) | TC-08.02 |
| **FR-08.03** | N FA → 1 Square (SUM aggregation) | UC-08 | Location mapper service (new) | TC-08.03 |
| **FR-08.04** | 1 FA → 1 Square (DIRECT aggregation) | UC-08 | Location mapper service (new) | TC-08.04 |
| **FR-08.05** | Retrieve Square locations via API | UC-08 | `LocationsApi::listLocations()` | TC-08.05 |
| **FR-08.06** | Aggregate QOH by SUM across mapped FA locations | UC-01/08 | `CatalogExporter::pushInventory()` enhanced | TC-08.06 |
| **FR-08.07** | Pass individual QOH for DIRECT mapping | UC-01/08 | `CatalogExporter::pushInventory()` enhanced | TC-08.07 |
| **FR-08.08** | Replace manual Square Location dropdown with mapping-based selection | UC-01/08 | `pages/export.php` updated | TC-08.08 |
| **FR-09.01** | Detect square_invoice* payment terms in db_prewrite | UC-09: Send to Square | `hooks.php::db_prewrite()` | UAT-SQ-001 |
| **FR-09.02** | Suppress FA auto-payment (cash_sale=0) | UC-09 | `hooks.php::db_prewrite()` | UAT-SQ-001 |
| **FR-09.03** | Create Square Order from FA cart line items | UC-09 | `src/Services/SquareInvoiceService::createSquareOrder()` | UAT-SQ-001 |
| **FR-09.04** | Auto-create Square Customer from FA debtor | UC-09 | `src/Services/SquareInvoiceService::createSquareCustomer()` | UAT-SQ-004 |
| **FR-09.05** | Create Square Invoice (DRAFT) with accepted payment methods | UC-09 | `src/Services/SquareInvoiceService::createInvoiceFromFA()` | UAT-SQ-001 |
| **FR-09.06** | Publish Square Invoice (UNPAID) with public payment URL | UC-09 | `src/Services/SquareInvoiceService::publishInvoiceInternal()` | UAT-SQ-001 |
| **FR-09.07** | Store FA↔Square Invoice mapping | UC-09 | `src/DAO/SquareInvoiceMapDAO::insert()` | UAT-SQ-003 |
| **FR-09.08** | Store FA↔Square Customer mapping | UC-09 | `src/Services/SquareInvoiceService::createSquareCustomer()` | UAT-SQ-004 |
| **FR-09.09** | Support SHARE_MANUALLY, EMAIL, SMS delivery | UC-09 | `SquareInvoiceService` + hooks | UAT-SQ-002 |
| **FR-09.10** | Support automatic payment source (card-on-file) | UC-09 | `SquareInvoiceService::createInvoiceFromFA()` | UAT-SQ-002 |
| **FR-09.11** | Idempotent: return existing mapping | UC-09 | `SquareInvoiceService::createInvoiceFromFA()` | UAT-SQ-003 |
| **FR-09.12** | Display notification with payment URL | UC-09 | `hooks.php::db_postwrite()` | UAT-SQ-001 |
| **FR-09.13** | resolvePaymentDestination checks both tables | UC-09 | `hooks.php::resolvePaymentDestination()` | UAT-SQ-005 |
| **FR-09.14** | Payment term mapping via FA_PaymentDestinations | UC-11 | FA_PaymentDestinations module | UAT-SQ-006 |
| **NFR-01** | PHP 7.3/7.4 compatibility | All | composer.json | PHP unit |
| **NFR-02** | FA 2.4.x integration | All | hooks.php | Manual |
| **NFR-03** | Square SDK ^40.0.0 | All | composer.json | CI |
| **NFR-04** | TB_PREF convention | All | All SQL queries | Code review |
| **NFR-05** | Token security | All | `src/Config/Settings.php` | Code review |
| **NFR-06** | Idempotency keys | UC-01/02/03 | API writes use `uniqid()` | Code review |
| **NFR-07** | Error handling | All | `try/catch` + `SquareException` | TC-07 |
| **NFR-08** | Batch operations | UC-01 | `CatalogExporter::batchUpsertProducts()` | Perf test |

---

## Use Case Index

| UC ID | Name | Trigger | Primary Actor |
|-------|------|---------|---------------|
| UC-01 | Export Inventory to Square | User clicks "Export to Square" | FA Administrator |
| UC-02 | Process Terminal Payment | User creates invoice + clicks "Charge Card" | Sales Operator |
| UC-03 | Import Sales from Square (API) | Scheduled / User clicks "Import Orders" | FA Administrator |
| UC-04 | Sync Customers | During export/import processes | System (automatic) |
| UC-05 | Import CSV from Square Dashboard | User uploads CSV file | FA Administrator |
| UC-06 | Configure Integration | User opens Configuration page | FA Administrator |
| UC-07 | Unified Import Staging | Any import flow (Square API/CSV/WooCommerce) | System (automatic) |
| UC-08 | Map Locations | User opens Location Mapping page | FA Administrator |
| UC-09 | Send Invoice to Square | User creates invoice with square_invoice term | Sales Operator |
| UC-10 | Track Invoice Payment Status | Customer pays via Square link | System (automatic) |
| UC-11 | Configure Payment Term Destination | Admin maps payment term to Square | FA Administrator |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial draft from Square SDK v40 API analysis |
| 0.2 | 2026-05-21 | KSFraser | Updated to reflect refactored class-based architecture, added unified staging vision |
| 0.3 | 2026-05-21 | KSFraser | Added FR-01.11–14 (UTF-8 sanitization, item limit, logging, token tracking), FR-08 (Location Mapping with N:1/1:1 aggregation), UC-08 |
