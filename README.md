# ksf_FA_Square

A connector between [FrontAccounting](https://frontaccounting.com/) and [SquareUp](https://squareup.com/).

## Features

- **Push products** from FrontAccounting to SquareUp, including stock-on-hand counts and prices via Catalog + Inventory APIs
- **Terminal payments** — create Square orders from FA invoices and initiate Square Terminal/Reader checkout for card-present collection
- **Pull transactions** from SquareUp into staging tables for review and processing into FA sales invoices
- **CSV import** — stage Square Dashboard CSV exports for matching and processing
- **Customer matching** — bi-directional matching between Square customers and FA debtors

## Architecture

```
src/
├── Config/Settings.php          — Access token, environment, import config
├── Contracts/                   — Service interfaces
├── Exceptions/                  — Module-specific exceptions
├── Push/
│   ├── CatalogExporter.php      — Products, inventory, prices -> Square
│   └── TerminalPayment.php      — Invoice checkout -> Square Reader
├── Pull/
│   └── OrderImporter.php        — Square payments/orders -> staging
└── Staging/
    ├── CustomerMatcher.php      — Match/create FA debtors
    ├── InvoiceCreator.php       — Staging -> FA sales invoice
    └── StagingTableManager.php  — Staging table CRUD
```

## Dependencies

- PHP >=7.3
- FrontAccounting 2.4.x
- Square PHP SDK ^40.0 (PHP 7.x compatible)
- ksfraser/exceptions, ksfraser/genericinterface, ksfraser/ksf-modules-dao (Packagist)
- ksfraser/traits (GitHub VCS)

