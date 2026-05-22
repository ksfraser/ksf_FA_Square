# UML Documentation — ksf_FA_Square

## 1. Component Diagram: Current Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   FrontAccounting 2.4.x                  │
│  ┌─────────────────────────────────────────────────────┐ │
│  │              ksf_FA_Square Module                     │ │
│  │                                                       │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌───────────┐  │ │
│  │  │  Config      │  │  Dashboard   │  │  Import   │  │ │
│  │  │  Page        │  │  Page        │  │  Page     │  │ │
│  │  └──────┬───────┘  └──────┬───────┘  └─────┬─────┘  │ │
│  │  ┌──────┴─────────────────┴─────────────────┴──────┐ │ │
│  │  │               Layer: Square Services              │ │ │
│  │  │  ┌──────────┐ ┌──────────┐ ┌──────────────────┐ │ │ │
│  │  │  │CatalogEx │ │Terminal  │ │  OrderImporter   │ │ │ │
│  │  │  │porter    │ │Payment   │ │  (Pull)          │ │ │ │
│  │  │  └──────────┘ └──────────┘ └──────────────────┘ │ │ │
│  │  │  ┌─────────────────────────────────────────────┐ │ │ │
│  │  │  │  Staging: CustomerMatcher, InvoiceCreator,  │ │ │ │
│  │  │  │            StagingTableManager               │ │ │ │
│  │  │  └─────────────────────────────────────────────┘ │ │ │
│  │  └─────────────────────────────────────────────────┘ │ │
│  │  ┌────────────────────────────────────────────────┐  │ │
│  │  │          FA Database Layer                       │  │ │
│  │  │  stock_master, sales_orders, debtors_master,    │  │ │
│  │  │  cust_branch, square (config),                  │  │ │
│  │  │  square_staging_transactions,                   │  │ │
│  │  │  square_staging_items, square_customer_mappings,│  │ │
│  │  │  square_import_log                              │  │ │
│  │  └────────────────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────────────┘ │
│                                                           │
│  PHP 7.3/7.4 | MySQL/MariaDB                              │
└─────────────────────────────────────────────────────────┘
                            │
                            │ HTTPS / OAuth
                            ▼
┌─────────────────────────────────────────────────────────┐
│                   Square Platform                        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐ │
│  │ Catalog  │  │ Inventory│  │ Orders & │  │Terminal│ │
│  │ API      │  │ API      │  │ Payments │  │ API    │ │
│  └──────────┘  └──────────┘  └──────────┘  └────────┘ │
│  ┌──────────┐  ┌──────────┐                              │
│  │ Customers│  │ Locations│                              │
│  │ API      │  │ API      │                              │
│  └──────────┘  └──────────┘                              │
└─────────────────────────────────────────────────────────┘
```

---

## 2. Class Diagram: Core Domain

```
┌──────────────────────────────────────────────────────────┐
│                SettingsInterface                          │
├──────────────────────────────────────────────────────────┤
│ + getAccessToken(): ?string                               │
│ + getEnvironment(): string                                │
│ + getLastImportDate(): ?DateTimeInterface                 │
│ + getDestinationCustomer(): ?int                          │
│ + getDefaultLocation(): ?string                           │
└──────────────────────────┬───────────────────────────────┘
                           │ implements
┌──────────────────────────┴───────────────────────────────┐
│  Settings                                                 │
├──────────────────────────────────────────────────────────┤
│ - config: array                                           │
├──────────────────────────────────────────────────────────┤
│ + __construct(array $config)                              │
│ + static fromFADatabase(string $prefix): self             │
│ + get/set*(): mixed                                       │
│ + toArray(): array                                        │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│          SquareClientFactory  (NEW)                       │
├──────────────────────────────────────────────────────────┤
│ + static create(SettingsInterface): SquareClient         │
│   (Replaces 3x duplicate ::create() methods)             │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│ <<interface>>          <<interface>>                     │
│ CatalogExporterInterface TerminalPaymentInterface        │
├─────────────────────────├────────────────────────────────┤
│ + upsertProduct()       │ + createOrderFromInvoice()     │
│ + batchUpsertProducts() │ + createTerminalCheckout()     │
│ + pushInventory()       │ + getCheckoutStatus()          │
│ + batchPushInventory()  │ + cancelCheckout()             │
│ + getInventoryCount()   └──────────┬─────────────────────┘
│ + deleteProduct()                  │ implements
│ + searchProductBySku()  ┌──────────┴─────────────────────┐
│ + listAllItems()        │  TerminalPayment               │
└──────────┬──────────────├────────────────────────────────┤
           │ implements   │ - client: SquareClient         │
┌──────────┴──────────────┤ - settings: SettingsInterface  │
│  CatalogExporter        ├────────────────────────────────┤
├─────────────────────────┤ + __construct(client,settings) │
│ - client: SquareClient  └────────────────────────────────┘
│ - settings: Settings
├─────────────────────────┘
│ + __construct(client,settings)
│ + resolveCategory(name): ?string
│ + resolveTax(name,rate): ?string
└─────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│ <<interface>>                                            │
│ OrderImporterInterface                                   │
├──────────────────────────────────────────────────────────┤
│ + listPayments(from,to,loc): Payment[]                   │
│ + getPaymentWithOrder(id): array                         │
│ + getOrder(id): ?Order                                   │
│ + getOrders(ids): Order[]                                │
└──────────┬───────────────────────────────────────────────┘
           │ implements
┌──────────┴───────────────────────────────────────────────┐
│  OrderImporter                                           │
├──────────────────────────────────────────────────────────┤
│ - client: SquareClient                                   │
│ - settings: SettingsInterface                            │
├──────────────────────────────────────────────────────────┤
│ + __construct(client,settings)                           │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│ <<interface>>                                            │
│ CustomerMatcherInterface                                 │
├──────────────────────────────────────────────────────────┤
│ + findOrCreateDebtor(data): int                          │
│ + findOrCreateBranch(debtorNo, data): int                │
│ + matchSquareCustomerToFaDebtor(id): ?int                │
│ + linkSquareCustomer(id, debtorNo): void                 │
└──────────┬───────────────────────────────────────────────┘
           │ implements
┌──────────┴───────────────────────────────────────────────┐
│  CustomerMatcher                                         │
├──────────────────────────────────────────────────────────┤
│ - tablePrefix: string                                    │
│ - mappings: array                                        │
├──────────────────────────────────────────────────────────┤
│ + __construct(tablePrefix)                               │
│ - getNextDebtorNo(): int                                 │
│ - insertDebtor(no, data): void                           │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│ <<interface>>                                            │
│ InvoiceCreatorInterface                                  │
├──────────────────────────────────────────────────────────┤
│ + createSalesInvoice(...): int                           │
│ + recordPayment(invoice, amount, date, pos): int         │
└──────────┬───────────────────────────────────────────────┘
           │ implements
┌──────────┴───────────────────────────────────────────────┐
│  InvoiceCreator                                          │
├──────────────────────────────────────────────────────────┤
│ - tablePrefix: string                                    │
├──────────────────────────────────────────────────────────┤
│ + __construct(tablePrefix)                               │
│ - getNextReference(): string                             │
│ - createBlankOrder(...): int                             │
│ - addOrderLine(orderNo, item): void                      │
│ - processOrder(orderNo): void                            │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│  StagingTableManager                                     │
├──────────────────────────────────────────────────────────┤
│ - tablePrefix: string                                    │
├──────────────────────────────────────────────────────────┤
│ + __construct(tablePrefix)                               │
│ + createStagingTables(): void                            │
│ + dropStagingTables(): void                              │
│ + insertStagingTransaction(data): int                    │
│ + getUnprocessedTransactions(source): array              │
│ + markProcessed(id): void                                │
│ + markFailed(id, error): void                            │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────┐
│  Exceptions                                              │
├──────────────────────────────────────────────────────────┤
│ SquareException extends FAException                      │
│   + apiError(endpoint, msg, errors): self                │
│   + configurationError(key): self                        │
│   + importFailed(reason): self                           │
│   + exportFailed(reason): self                           │
│                                                          │
│ ProductNotFoundException extends FAEntityNotFoundException│
│   + bySku(sku): self                                     │
│   + byStockId(stockId): self                             │
└──────────────────────────────────────────────────────────┘

---

## 3. Sequence Diagram: Export Inventory (with SquareClientFactory)

```
FA_Admin          ExportUI              CatalogExporter       SquareClientFactory        Square API
   │                  │                      │                      │                     │
   │   Click Export   │                      │                      │                     │
   │─────────────────>│                      │                      │                     │
   │                  │  new CatalogExporter  │                      │                     │
   │                  │─────────────────────>│                      │                     │
   │                  │                      │  create(settings)    │                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ new SquareClient()  │
   │                  │                      │<─── SquareClient ────│                     │
   │                  │                      │                      │                     │
   │                  │  query FA items      │                      │                     │
   │                  │─────────────────────>│                      │                     │
   │                  │                      │  (loop per item)     │                     │
   │                  │                      │  resolveCategory()   │                     │
   │                  │                      │─────────────────────>│ searchCatalogObjects│
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── CatalogObject ───│
   │                  │                      │  upsertProduct()     │                     │
   │                  │                      │─────────────────────>│ upsertCatalogObject │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── CatalogObject ───│
   │                  │                      │<── success ──────────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  pushInventory()     │                     │
   │                  │                      │─────────────────────>│ batchChangeInventor │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<──── success ───────│
   │                  │<── ExportResult ─────│                      │                     │
   │<── Summary ─────│                      │                      │                     │
```

---

## 4. Sequence Diagram: Terminal Payment Flow

```
SalesOp          FA_InvoiceUI           TerminalPayment         SquareClientFactory       Square API        Square_Reader
   │                  │                      │                      │                     │                  │
   │ Create Invoice   │                      │                      │                     │                  │
   │─────────────────>│                      │                      │                     │                  │
   │                  │  processTerminal     │                      │                     │                  │
   │                  │  Payment(inv_id)     │                      │                     │                  │
   │                  │─────────────────────>│                      │                     │                  │
   │                  │                      │  createOrderFrom     │                     │                  │
   │                  │                      │  Invoice(items)      │                     │                  │
   │                  │                      │─────────────────────>│ createOrder()        │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │<─────── Order ───────│                  │
   │                  │                      │<── Order ────────────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │                      │  createTerminal      │                     │                  │
   │                  │                      │  Checkout(order)     │                     │                  │
   │                  │                      │─────────────────────>│ createTerminal       │                  │
   │                  │                      │                      │ Checkout()           │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │                     │ Push Checkout    │
   │                  │                      │                      │                     │─────────────────>│
   │                  │                      │                      │< TerminalCheckout ───│                  │
   │                  │                      │< checkoutId ────────│                     │                  │
   │                  │  Awaiting Reader...  │                      │                     │                  │
   │                  │<─────────────────────│                      │                     │                  │
   │                  │                      │                      │                     │ Customer Taps    │
   │                  │                      │                      │                     │<─────────────────│
   │                  │                      │  getCheckoutStatus() │                     │                  │
   │                  │                      │────(poll loop)──────>│ getTerminalCheckout │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │<── COMPLETED ────────│                  │
   │                  │                      │< completed ──────────│                     │                  │
   │                  │< PaymentResult ──────│                      │                     │                  │
   │< Invoice Paid    │                      │                      │                     │                  │
```

---

## 5. Sequence Diagram: Import Sales (Square -> Staging -> FA)

```
FA_Admin          ImportUI              OrderImporter          StagingTableManager     InvoiceCreator      Square API
   │                  │                      │                      │                     │                  │
   │  Click Import    │                      │                      │                     │                  │
   │─────────────────>│                      │                      │                     │                  │
   │                  │  listPayments(from,  │                      │                     │                  │
   │                  │  to)                 │                      │                     │                  │
   │                  │─────────────────────>│                      │                     │                  │
   │                  │                      │─── listPayments() ────────────────────────>│                  │
   │                  │                      │<─── Payment[] ─────────────────────────────│                  │
   │                  │<── Payment[] ────────│                      │                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │  (loop per payment)  │                      │                     │                  │
   │                  │  getPaymentWithOrder │                      │                     │                  │
   │                  │  (paymentId)         │                      │                     │                  │
   │                  │─────────────────────>│                      │                     │                  │
   │                  │                      │─── getPayment() ──────────────────────────>│                  │
   │                  │                      │<── payment+order ──────────────────────────│                  │
   │                  │<── {payment,order} ──│                      │                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │  insertStaging       │                      │                     │                  │
   │                  │  Transaction(data)   │                      │                     │                  │
   │                  │────────────────────────────────────────────>│                     │                  │
   │                  │                      │                      │<── staging_id ──────│                  │
   │                  │                      │                      │                     │                  │
   │                  │  (manual review)     │                      │                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │  createSalesInvoice  │                      │                     │                  │
   │                  │  (staging record)    │                      │                     │                  │
   │                  │──────────────────────────────────────────────────────────────────>│                  │
   │                  │                      │                      │  (creates FA        │                  │
   │                  │                      │                      │   sales invoice)    │                  │
   │                  │                      │                      │<── invoice_no ──────│                  │
   │                  │  recordPayment()     │                      │                     │                  │
   │                  │──────────────────────────────────────────────────────────────────>│                  │
   │                  │                      │                      │  (records payment   │                  │
   │                  │                      │                      │   in FA)            │                  │
   │                  │                      │                      │<── success ─────────│                  │
   │                  │                      │                      │                     │                  │
   │                  │  markProcessed(id)   │                      │                     │                  │
   │                  │────────────────────────────────────────────>│                     │                  │
   │                  │                      │                      │                     │                  │
   │<── Results ──────│                      │                      │                     │                  │
```

---

## 6. Database Schema: Current Staging Tables

```
┌─────────────────────────┐    ┌─────────────────────────┐
│      stock_master (FA)  │    │   sales_orders (FA)     │
├─────────────────────────┤    ├─────────────────────────┤
│ PK: stock_id            │    │ PK: order_no            │
│ description             │    │ debtor_id, branch_id    │
│ category_id             │    │ ord_date, deliver_date  │
│ tax_type_id             │    │ reference, comments      │
│ units                   │    │ freight_cost             │
│ mb_flag                 │    │ delivery_address         │
│ inactive                │    └────────────┬────────────┘
└────────────┬────────────┘                 │
             │                 ┌────────────┴────────────┐
             │                 │ sales_order_details(FA) │
             │                 ├─────────────────────────┤
             │                 │ PK: order_no + stk_code │
             │                 │ unit_price, quantity     │
             │                 │ discount_percent         │
             │                 └─────────────────────────┘
             │
┌────────────┴────────────┐
│       square (config)   │
├─────────────────────────┤
│ PK: name                │
│ value (varchar)         │
│ type (nullable)         │
└─────────────────────────┘

┌─────────────────────────────┐
│  square_staging_transactions│
├─────────────────────────────┤
│ PK: id                      │
│ source (api/csv/woo)        │
│ square_transaction_id       │
│ square_order_id             │
│ square_payment_id           │
│ location_id                 │
│ customer_id                 │
│ customer_name               │
│ transaction_date            │
│ total_amount, tax_amount    │
│ tip_amount, discount_amount │
│ currency                    │
│ raw_json (LONGTEXT)         │
│ status (staged/imported/    │
│         failed)             │
│ error_log                   │
│ fa_invoice_no               │
│ created_at / updated_at     │
└─────────────────────────────┘

┌─────────────────────────────┐
│  square_staging_items       │
├─────────────────────────────┤
│ PK: id                      │
│ staging_transaction_id (FK) │
│ sku, name                   │
│ quantity, unit_price        │
│ total, tax, discount        │
│ raw_json                    │
│ created_at                  │
└─────────────────────────────┘

┌─────────────────────────────┐
│  square_customer_mappings   │
├─────────────────────────────┤
│ PK: id                      │
│ square_customer_id (UQ)     │
│ fa_debtor_no                │
│ created_at                  │
└─────────────────────────────┘

┌─────────────────────────────┐
│  square_import_log          │
├─────────────────────────────┤
│ PK: id                      │
│ run_date                    │
│ source                      │
│ orders_imported             │
│ orders_skipped              │
│ orders_failed               │
│ status                      │
│ error_log                   │
│ created_at                  │
└─────────────────────────────┘
```

---

## 7. Data Flow: Export

```
FA stock_master ───> CatalogExporter ───> Square Catalog API
     │                      │                      │
     ├─ stock_id ───────────┼──────────> item_variation.sku
     ├─ description ────────┼──────────> item.name, item.description
     ├─ category_id ────────┼─ resolveCategory() ──> category.id
     ├─ price via           │                      │
     │  get_kit_price() ────┼──────────> variation.price_money
     └─ tax_type_id ────────┼─ resolveTax() ───────> tax.id

FA stock_moves ───> CatalogExporter ───> Square Inventory API
     │                      │                      │
     └─ qty on hand ────────┼─ pushInventory() ────> batchChangeInventory
```

---

## 8. Future: Unified Import Staging Architecture

```
                    ┌──────────────────────────────┐
                    │     ksf_ImportStaging         │
                    │   (Shared Composer Package)   │
                    ├──────────────────────────────┤
                    │  import_staging_transactions  │
                    │  import_staging_line_items    │
                    │  import_customer_mappings     │
                    │  import_log                   │
                    │                              │
                    │  StagingTableManager          │
                    │  CustomerMatcher              │
                    │  InvoiceCreator               │
                    └──────────┬───────────────────┘
                               │ "require"
              ┌────────────────┼────────────────┐
              │                │                │
              ▼                ▼                ▼
┌─────────────────────┐ ┌─────────────┐ ┌──────────────┐
│   ksf_FA_Square     │ │FA_Import    │ │Export_       │
│   (this module)     │ │SquareUp     │ │Woocommerce   │
├─────────────────────┤ ├─────────────┤ ├──────────────┤
│ Pull from API ──────┼─┤ CSV import  │ │ Pull from    │
│ Square Terminal     │ │ → staging   │ │ WooCommerce  │
│ Catalog Export      │ │             │ │ → staging    │
└─────────────────────┘ └─────────────┘ └──────────────┘
```

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial UML documentation |
| 0.2 | 2026-05-21 | KSFraser | Updated to reflect refactored class architecture, SquareClientFactory, unified staging vision |
