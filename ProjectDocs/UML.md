# UML Documentation — ksf_FA_Square

## 1. Component Diagram: System Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   FrontAccounting 2.4.x                  │
│  ┌─────────────────────────────────────────────────────┐ │
│  │              ksf_FA_Square Module                     │ │
│  │                                                       │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌───────────┐  │ │
│  │  │  Export       │  │  Import      │  │  Terminal  │  │ │
│  │  │  Inventory    │  │  Sales       │  │  Payments  │  │ │
│  │  │  (i_export)   │  │  (o_import)  │  │  (new)    │  │ │
│  │  └──────┬───────┘  └──────┬───────┘  └─────┬─────┘  │ │
│  │         │                 │                 │         │ │
│  │  ┌──────┴─────────────────┴─────────────────┴──────┐ │ │
│  │  │           Square API Adapter Layer                │ │ │
│  │  │  ┌──────────┐ ┌──────────┐ ┌──────────────────┐ │ │ │
│  │  │  │CatalogApi│ │Inventory │ │  Orders/Payments │ │ │ │
│  │  │  │Adapter   │ │ApiAdapter│ │  ApiAdapter      │ │ │ │
│  │  │  └──────────┘ └──────────┘ └──────────────────┘ │ │ │
│  │  │  ┌──────────┐ ┌──────────┐ ┌──────────────────┐ │ │ │
│  │  │  │Customers │ │ Terminal │ │  CSV Import      │ │ │ │
│  │  │  │ApiAdapter│ │ApiAdapter│ │  Processor       │ │ │ │
│  │  │  └──────────┘ └──────────┘ └──────────────────┘ │ │ │
│  │  └─────────────────────────────────────────────────┘ │ │
│  │                                                       │ │
│  │  ┌────────────────────────────────────────────────┐  │ │
│  │  │          FA Database Layer                      │  │ │
│  │  │  stock_master, sales_orders, debtors_master,    │  │ │
│  │  │  cust_branch, square (config), square_mappings, │  │ │
│  │  │  square_staging_*, square_import_log            │  │ │
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
│                                                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐ │
│  │ Catalog  │  │ Inventory│  │ Orders & │  │Terminal│ │
│  │ API      │  │ API      │  │ Payments │  │ API    │ │
│  └──────────┘  └──────────┘  └──────────┘  └────────┘ │
│                                                          │
│  ┌──────────┐  ┌──────────┐                              │
│  │ Customers│  │ Locations│                              │
│  │ API      │  │ API      │                              │
│  └──────────┘  └──────────┘                              │
└─────────────────────────────────────────────────────────┘
```

---

## 2. Class Diagram: Core Domain Model

```
┌──────────────────────────────────────┐
│          SquareClient (SDK)          │
├──────────────────────────────────────┤
│ + __construct(config)                │
├──────────────────────────────────────┤
│ + getCatalogApi(): CatalogApi        │
│ + getInventoryApi(): InventoryApi    │
│ + getOrdersApi(): OrdersApi          │
│ + getPaymentsApi(): PaymentsApi      │
│ + getCustomersApi(): CustomersApi    │
│ + getTerminalApi(): TerminalApi      │
│ + getLocationsApi(): LocationsApi    │
│ + getRefundsApi(): RefundsApi        │
└──────────┬───────────────────────────┘
           │
           │ 1
           ├──────────────────────────────────────────────┐
           │                                              │
┌──────────┴──────────────────────────┐    ┌──────────────┴──────────────┐
│      SquareApiAdapterInterface      │    │    SquareApiAdapter         │
├─────────────────────────────────────┤    ├─────────────────────────────┤
│ + getLocationList(): Location[]     │    │ - client: SquareClient      │
│ + getCatalogItem(sku): CatalogItem  │    │ - config: array             │
│ + upsertProduct(item): CatalogItem  │    ├─────────────────────────────┤
│ + pushInventory(sku, qty): bool     │    │ + getLocationList()          │
│ + createOrder(items): Order         │    │ + getCatalogItem()           │
│ + createTerminalCheckout(): Tx      │    │ + upsertProduct()            │
│ + listPayments(from, to): Payment[] │    │ + pushInventory()            │
│ + retrieveOrder(id): Order          │    │ + createOrder()              │
│ + searchCustomers(q): Customer[]    │    │ + createTerminalCheckout()   │
│ + createCustomer(data): Customer    │    │ + listPayments()             │
│ + getLocations(): Location[]        │    │ + ...                        │
└─────────────────────────────────────┘    └─────────────────────────────┘

┌──────────────────────────────────────┐
│         FASquareService              │
├──────────────────────────────────────┤
│ - adapter: SquareApiAdapterInterface │
│ - db: FADatabase                     │
├──────────────────────────────────────┤
│ + exportInventory(items, loc): array │
│ + importSales(from, to): ImportResult│
│ + processTerminalPayment(inv_id): Tx │
│ + syncCustomers(): SyncResult        │
│ + processCSV(file): StageResult      │
│ + getConfig(key): string             │
│ + saveConfig(key, value): void       │
└──────────────────────────────────────┘

┌──────────────────────────────────────┐
│         ProductModel                 │
├──────────────────────────────────────┤
│ - stockId: string                    │
│ - description: string                │
│ - category: string                   │
│ - price: Money                       │
│ - quantityOnHand: float              │
│ - unit: string                       │
│ - taxType: string                    │
│ - imagePath: string                  │
│ - squareObjectId: string             │
│ - squareVersion: int                 │
├──────────────────────────────────────┤
│ + toCatalogObject(): CatalogObject   │
│ + fromFAItem(row): ProductModel      │
│ + fromSquareObject(obj): ProductModel│
└──────────────────────────────────────┘

┌──────────────────────────────────────┐
│         SalesOrderModel              │
├──────────────────────────────────────┤
│ - squareOrderId: string              │
│ - faOrderReference: string           │
│ - location: string                   │
│ - customer: CustomerModel            │
│ - lineItems: OrderLineItem[]         │
│ - taxes: TaxItem[]                   │
│ - discounts: DiscountItem[]          │
│ - tip: Money                         │
│ - total: Money                       │
│ - status: string                     │
│ - createdAt: DateTime                │
├──────────────────────────────────────┤
│ + toFASalesInvoice(): bool           │
│ + toSquareOrder(): CreateOrderRequest│
│ + fromSquareOrder(order): static     │
└──────────────────────────────────────┘

┌──────────────────────────────────────┐
│         CustomerModel                │
├──────────────────────────────────────┤
│ - squareCustomerId: string           │
│ - faDebtorId: string                 │
│ - givenName: string                  │
│ - familyName: string                 │
│ - companyName: string                │
│ - email: string                      │
│ - phone: string                      │
│ - referenceId: string                │
├──────────────────────────────────────┤
│ + toSquareCustomer(): CreateRequest  │
│ + toFADebtor(): array                │
│ + matchConfidence(): float           │
└──────────────────────────────────────┘
```

---

## 3. Sequence Diagram: Export Inventory

```
FA_Admin          ExportUI              FASquareService        SquareApiAdapter         Square API
   │                  │                      │                      │                     │
   │   Click Export   │                      │                      │                     │
   │─────────────────>│                      │                      │                     │
   │                  │  getItems(category)  │                      │                     │
   │                  │─────────────────────>│                      │                     │
   │                  │                      │  query FA stock_master, stock_moves          │
   │                  │                      │──────────────────────│                     │
   │                  │                      │<─── ProductModel[] ──│                     │
   │                  │                      │                      │                     │
   │                  │                      │  searchCatalog(sku)  │                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ searchCatalogObjects │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── CatalogObject ───│
   │                  │                      │<── exists/new ───────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  upsertProduct(item) │                     │
   │  (loop per item) │                      │─────────────────────>│                     │
   │                  │                      │                      │ upsertCatalogObject  │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<── CatalogObject ────│
   │                  │                      │<── success ──────────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  pushInventory(sku,qty,loc)                  │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ batchChangeInventory │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── success ─────────│
   │                  │                      │<── success ──────────│                     │
   │                  │                      │                      │                     │
   │                  │<── ExportResult ─────│                      │                     │
   │                  │                      │                      │                     │
   │<─── Summary ─────│                      │                      │                     │
```

---

## 4. Sequence Diagram: Terminal Payment Flow

```
SalesOp          FA_InvoiceUI           FASquareService        SquareApiAdapter         Square API        Square_Reader
   │                  │                      │                      │                     │                  │
   │ Create Invoice   │                      │                      │                     │                  │
   │─────────────────>│                      │                      │                     │                  │
   │                  │  processTerminal     │                      │                     │                  │
   │                  │  Payment(inv_id)     │                      │                     │                  │
   │                  │─────────────────────>│                      │                     │                  │
   │                  │                      │  createOrder(items)  │                     │                  │
   │                  │                      │─────────────────────>│                     │                  │
   │                  │                      │                      │ createOrder()        │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │<─── Order ───────────│                  │
   │                  │                      │<── Order ────────────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │                      │  createTerminal      │                     │                  │
   │                  │                      │  Checkout(orderId)   │                     │                  │
   │                  │                      │─────────────────────>│                     │                  │
   │                  │                      │                      │ createTerminal       │                  │
   │                  │                      │                      │ Checkout()           │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │                     │  Push Checkout   │
   │                  │                      │                      │                     │─────────────────>│
   │                  │                      │                      │<── TerminalCheckout ─│                  │
   │                  │                      │<── checkoutId ───────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │  Awaiting Reader...  │                      │                     │                  │
   │                  │<─────────────────────│                      │                     │                  │
   │                  │                      │                      │                     │  Customer Taps   │
   │                  │                      │                      │                     │  /Dips Card      │
   │                  │                      │                      │                     │<─────────────────│
   │                  │                      │  pollCheckout(id)    │                     │                  │
   │                  │                      │────(loop every 5s)──>│                     │                  │
   │                  │                      │                      │ getTerminalCheckout  │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │<── COMPLETED ────────│                  │
   │                  │                      │<── completed ────────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │                      │  retrieveOrder(id)   │                     │                  │
   │                  │                      │─────────────────────>│                     │                  │
   │                  │                      │                      │ retrieveOrder()      │                  │
   │                  │                      │                      │────────────────────>│                  │
   │                  │                      │                      │<─── Order ───────────│                  │
   │                  │                      │<── Order ────────────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │                      │  recordPayment       │                     │                  │
   │                  │                      │  inFA(order)         │                     │                  │
   │                  │                      │───(creates FA        │                     │                  │
   │                  │                      │   payment, links     │                     │                  │
   │                  │                      │   to invoice) ───────│                     │                  │
   │                  │                      │                      │                     │                  │
   │                  │<── PaymentResult ────│                      │                     │                  │
   │<── Invoice       │                      │                      │                     │                  │
   │   Marked Paid    │                      │                      │                     │                  │
```

---

## 5. Sequence Diagram: Import Sales (Square -> FA)

```
FA_Admin          ImportUI              FASquareService        SquareApiAdapter         Square API
   │                  │                      │                      │                     │
   │  Click Import    │                      │                      │                     │
   │─────────────────>│                      │                      │                     │
   │                  │  importSales(from,   │                      │                     │
   │                  │  to)                 │                      │                     │
   │                  │─────────────────────>│                      │                     │
   │                  │                      │  listLocations()     │                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ listLocations()      │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── Location[] ──────│
   │                  │                      │<── Location[] ───────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  listPayments(from,to)│                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ listPayments()       │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── Payment[] ───────│
   │                  │                      │<── Payment[] ────────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  (filter by: location,                      │
   │                  │                      │   status=COMPLETED,                          │
   │                  │                      │   not already imported)                      │
   │                  │                      │                      │                     │
   │                  │(loop per payment)    │                      │                     │
   │                  │                      │  retrieveOrder(id)   │                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ retrieveOrder()      │
   │                  │                      │                      │────────────────────>│
   │                  │                      │                      │<─── Order ───────────│
   │                  │                      │<── Order ────────────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  retrieveCustomer()  │                     │
   │                  │                      │─────────────────────>│                     │
   │                  │                      │                      │ retrieveCustomer()   │
   │                  │                      │                      │────────────────────>│
   │                  │                      │<── Customer ─────────│                     │
   │                  │                      │                      │                     │
   │                  │                      │  map to FA structures│                     │
   │                  │                      │  (branch, items,     │                     │
   │                  │                      │   taxes, discounts,  │                     │
   │                  │                      │   tips, customer)    │                     │
   │                  │                      │                      │                     │
   │                  │                      │  create FA Sales     │                     │
   │                  │                      │  Invoice + Payment   │                     │
   │                  │                      │───(FA DB writes)─────│                     │
   │                  │                      │                      │                     │
   │                  │<── ImportResult ─────│                      │                     │
   │                  │                      │                      │                     │
   │<── Results ──────│                      │                      │                     │
```

---

## 6. Database Schema Diagram

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
│ inactive                │    │ ────────────────────────│
└────────────┬────────────┘    │ square_payment_id (ext) │
             │                 │ square_order_id (ext)   │
             │                 └────────────┬────────────┘
             │                              │
             │                 ┌────────────┴────────────┐
             │                 │   sales_order_details(FA)│
             │                 ├─────────────────────────┤
             │                 │ PK: order_no + stk_code │
             │                 │ unit_price, quantity     │
             │                 │ discount_percent         │
             │                 └─────────────────────────┘
             │
┌────────────┴────────────┐
│       square (config)   │
├─────────────────────────┤
│ PK: name                │    ┌─────────────────────────┐
│ value (varchar)         │    │   square_mappings       │
│ type (ext)              │    ├─────────────────────────┤
└─────────────────────────┘    │ PK: id                  │
                               │ fa_type, fa_id          │
┌─────────────────────────┐    │ square_type, square_id  │
│  square_import_log      │    │ created_at              │
├─────────────────────────┤    └─────────────────────────┘
│ PK: id                  │
│ run_date                │    ┌─────────────────────────┐
│ location_id             │    │  square_staging_orders  │
│ orders_imported         │    ├─────────────────────────┤
│ orders_skipped          │    │ PK: id                  │
│ orders_failed           │    │ raw_json (TEXT)          │
│ status                  │    │ status (staged/imported) │
│ error_log               │    │ created_at              │
└─────────────────────────┘    └─────────────────────────┘
```

---

## 7. Data Flow Diagram: Information Flows

```
                    ┌──────────────────────────────────────┐
                    │          FA User Interface            │
                    │  (square.php and new pages)           │
                    └──┬──────────┬──────────┬─────────────┘
                       │          │          │
              ┌────────┘    ┌─────┘     ┌───┘
              ▼             ▼           ▼
     ┌──────────────┐ ┌──────────┐ ┌──────────┐
     │   Export     │ │  Import  │ │ Terminal │
     │  Inventory   │ │  Sales   │ │ Payments │
     └──────┬───────┘ └────┬─────┘ └─────┬────┘
            │              │              │
            ▼              ▼              ▼
     ┌──────────────────────────────────────────┐
     │          FASquareService Layer            │
     │  (business logic, mapping, validation)    │
     └──────────────────────────────────────────┘
            │              │              │
            ├──────────────┼──────────────┤
            ▼              ▼              ▼
     ┌──────────────────────────────────────────┐
     │          Square API Adapter Layer         │
     └──────────────────────────────────────────┘
            │              │              │
            ▼              ▼              ▼
     ┌──────────┐ ┌──────────┐ ┌──────────────────┐
     │  Square  │ │  Square  │ │   Square Terminal│
     │ Catalog  │ │  Orders  │ │   / Payments API │
     │ & Invent │ │ & Cust   │ │                  │
     └──────────┘ └──────────┘ └──────────────────┘

Data Flows:
─────────────────────────────────────────────────────
Products Export:       FA stock_master ────────> Square Catalog
Stock Counts:          FA stock_moves ─────────> Square Inventory
Prices:                FA sales_prices ────────> Square ItemVariation
Images:                FA file system ─────────> Square CatalogImage

Orders Import:         Square Orders ──────────> FA sales_orders
Payments Import:       Square Payments ────────> FA debtor_trans + 0_00
Customers:             Square Customers ───────> FA debtors_master
                       FA debtors_master ──────> Square Customers

Terminal Payments:     FA invoice ─────────────> Square TerminalCheckout
                       Square TerminalCheckout ─> FA payment record
```

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-20 | KSFraser | Initial UML documentation |
