# ksf_FA_Square — Push Path Usage Notes

Companion to `square_gap_analysis.md`. Documents the actual push path, which fields are hardcoded
vs. mapped from FA, where a location-override feature would slot in, and version-drift hazards.

---

## 1. Payload builders that exist

There is exactly **one** catalog-write engine: `src/Push/CatalogExporter.php`. All other code
delegates to it.

| Builder / method | Object(s) written | Entry points |
|---|---|---|
| `CatalogExporter::buildCatalogObject` (private) | `CatalogObject(ITEM)` → `CatalogItem` + `CatalogItemVariation` | `upsertProduct()` (single upsert) and `batchUpsertProducts()` (batch) |
| `CatalogExporter::resolveCategory` (private) | `CatalogObject(CATEGORY)` → `CatalogCategory` (name only; search-or-create) | called from `buildCatalogObject` |
| `CatalogExporter::resolveTax` (private) | `CatalogObject(TAX)` → `CatalogTax` (search-or-create) | called from `buildCatalogObject` when `taxName` non-empty |
| `CatalogExporter::uploadImage` | `CatalogObject(IMAGE)` + `CreateCatalogImageRequest` (`object_id`, `is_primary`) | `pages/export.php:491-499`, `ExportService::processItem:266-273` |
| `CatalogExporter::pushInventory` / `batchPushInventory` | **Inventory API** adjustments (`catalog_object_id`, `location_id`, `quantity`, `from_state`, `to_state`) — not catalog fields | `pages/export.php:447/475`, `LocationMappingDAO` QOH |

Call chain (both paths identical):

```
hooks.php item_created/item_updated        pages/export.php (UI form)
        └─ ItemEventSyncService::sync           └─ ExportService::processItem
               └─ CatalogExporter::upsertProduct      └─ CatalogExporter::upsertProduct
                        └─ buildCatalogObject
```

`ItemEventSyncService` (the event-driven path) adds **no** fields of its own — it re-fetches via
`StockMasterDAO::getItemForSync` and calls the same `upsertProduct`. It hardcodes `taxRate = 0.0`
(line 121) and uses `description` for both item name and description (lines 115-116).

### Fields actually written (complete coverage set)

- **CatalogItem**: `name`, `description`, `category_id`, `variations`, `tax_ids`
- **CatalogItemVariation**: `name`, `sku`, `pricing_type` (`FIXED_PRICING`), `price_money`
  (`amount`+`currency`), `track_inventory` (`true`)
- **CatalogCategory**: `name`
- **CatalogTax**: `name`, `percentage` (always `"0"`), `calculation_phase`
  (`TAX_SUBTOTAL_PHASE`), `inclusion_type` (`ADDITIVE`), `enabled` (`true`)
- **CatalogImage**: `caption`; image linkage via `CreateCatalogImageRequest.object_id` + `is_primary`
- **CatalogObject**: `type`, `id`, `version` (update path only)
- **Inventory adjustment** (separate API): `catalog_object_id`, `location_id`, `quantity`,
  `from_state`, `to_state`

The module also **reads** (never writes) `CatalogObject.present_at_all_locations` to skip items
already present at all locations (`pages/export.php:365`, `ExportService::processItem:198`).

---

## 2. Hardcoded vs. mapped from FA

**Mapped from FA (stock_master / item codes / kit prices):**
- `name` / `description` → `stock_master.description` (both the same string; `long_description`
  is never read)
- `category_id` → `stock_category.description` matched by name (search-or-create)
- `tax_ids` → `item_tax_types.name` matched by name (search-or-create)
- `price_money` → `get_kit_price(stock_id, currency, sales_type)` → `SquarePrice::fromDollars`
  (sentinel $999,999.99 when price ≤ 0; capped at max)
- `sku` → `get_all_item_codes` first barcode row, else `stock_id`
- `currency` → company default (`curr_default`) or `'CAD'` fallback
- `uploadImage` caption → item description; filenames `{stock_id}[-(n)].jpg`

**Hardcoded (never derived from FA):**
- `pricing_type = 'FIXED_PRICING'` (always; no VARIABLE_PRICING path)
- `track_inventory = true` (always)
- Tax `calculation_phase = 'TAX_SUBTOTAL_PHASE'`, `inclusion_type = 'ADDITIVE'`, `enabled = true`
- Tax `percentage = "0"` (callers compute `$taxRate = $item['exempt'] ? 0.0 : 0.0` — **always 0.0**;
  FA tax rate is never looked up)
- Category create id: `#` + sanitized name; item/variation ids: `#sku` / `#sku_var`
- Image resize 600×600, JPEG q90, `image/jpeg`
- Inventory adjustments: `from_state=NONE`, `to_state=IN_STOCK`

**Product attributes:** `FA_ProductAttributes` is **not consumed anywhere** on the push path.
`StockMasterDAO::getItemForSync`/`getItemsForExport` select only `stock_id, description, units,
inactive, cat_description, tax_name, exempt`. No attribute table (product attributes, size/colour,
extended fields) is queried.

---

## 3. Where a "location override" feature would go

The only location-aware code today is **inventory** pushing (QOH → Square locations) via
`LocationMappingDAO` (`*ALL*` sum vs. per-FA-location mappings) plus the
`present_at_all_locations` skip check. There is no per-location *pricing*.

To add `CatalogItemVariation.location_overrides` (and the item-level presence fields that make it
effective), the natural seam is:

1. **Data source** — FA has no per-location price; prices are per *sales type* (price lists) via
   `get_kit_price`. A location-override feature needs a mapping of **Square location ↔ FA sales
   type/price list** (Sales module home). This is a new lookup, not something to bolt onto
   `StockMasterDAO::getItemPrice`.
2. **Payload builder** — extend `buildCatalogObject` in `CatalogExporter` to accept an optional
   array of overrides and set:
   - `CatalogObject.present_at_location_ids` (or keep `present_at_all_locations`)
   - `CatalogItemVariation.location_overrides[]` → `ItemVariationLocationOverrides`
     (`location_id`, `price_money`, optional `track_inventory`, `pricing_type`)
   - ⚠️ Gotcha from the spec: overrides only apply for locations the variation is explicitly
     present at; `sold_out` / `sold_out_valid_until` are readOnly — never send them.
   - `Money` must carry `amount` **and** `currency`; location `currency` must match the merchant's.
3. **Call site** — pass overrides through `upsertProduct` from both `pages/export.php` (add a
   per-location price column to the mapping table UI in `pages/config.php`) and
   `ItemEventSyncService::sync` (extend `StockMasterDAO::getItemForSync`/new DAO to read the
   mapping). Both must converge on `buildCatalogObject` to stay DRY.
4. **Home module** — mapping table lives naturally in Sales (price lists/sales_types ↔ Square
   location); value storage in a new `0_ksf_square_location_overrides` table or columns on the
   existing `square_location_mappings` table.

---

## 4. Version-drift hazards

The module pins `square/square` `^40.0.0` (lock: `40.0.0.20250123`). The research spec is a
**current-generation** spec; the pinned SDK **lags it** in both directions:

**Removed fields that the SDK still exposes (do NOT write):**
- `CatalogItem.setAvailableOnline()` / `setAvailableForPickup()` / `setAvailableElectronically()`
  — removed from the current spec; replaced by `channels` (+ category `online_visibility`).
  The module does not call them today, but the **dead "Available Online" UI toggle** in
  `pages/export.php` (yesno row line 579; `ExportRequest::isAvailableOnline()` parsed at line 250)
  is parsed and **never applied** — anyone wiring it up naively would use the deprecated setters.
  Correct target: `CatalogItem.channels` / `CatalogCategory.online_visibility`.
- `CatalogItem.setDescriptionPlaintext()` — now readOnly.
- `ItemVariationLocationOverrides.setSoldOut()` / `setSoldOutValidUntil()` — readOnly.
- `Location` setters for `country`, `currency`, `logo_url`, `pos_background_url`,
  `full_format_logo_url`, `tax_ids`, `capabilities`, `created_at`, `merchant_id` — all readOnly
  per spec; a future Location-write feature must not send them.
- Item-level `pricing_type`, item-level `taxes`, item-level `measurement_unit_id`,
  `max_selections`, `text_options` — gone from the spec (moved to variation /
  `max_selected_modifiers`).

**Newer fields the pinned SDK LACKS (need SDK upgrade to implement):**
- `CatalogItem`: `kitchen_name`, `buyer_facing_name`, `ecom_uri`, `ecom_image_uris`, `is_alcoholic`
- `CatalogItemVariation`: `kitchen_name`, `vendor_information`
- So several gap-matrix rows (`buyer_facing_name`, `ecom_uri`, `kitchen_name`, `is_alcoholic`,
  `vendor_information`) are not settable with the pinned SDK without upgrading.

**Naming drift (older docs vs. current spec):**
- Pricing rules: `time_period_ids` (not `time_periods`), `discount_id` (not `discount_ids`),
  validity split into `valid_from_date` + `valid_from_local_time` (not one `valid_from`).
- Discount: `maximum_amount_money` (older docs: `max_discount_money`); `valid_from`/`valid_until`
  and `pricing_rule_ids` removed.
- `Money.amount` is integer **cents**; `price_money` needs both `amount` and `currency`.

**Other observations on the audited code:**
- `hooks.php` version property is 2.4.3 while `ItemEventSyncService` is `@since 2.4.4` — cosmetic
  drift; hooks class also omits the `$version` property required by the master AGENTS.md.
- Tax rate is hardcoded to 0 on both push paths — a genuine sync-completeness bug (FA
  `item_tax_types` carries only name + exempt; the rate needs `tax_groups`/tax-type rate lookup,
  Sales module home).
- The event path (`ItemEventSyncService`) ignores `full_sync`, image upload, inventory push, and
  the presence-skip check that `pages/export.php` honours — it is a reduced subset of the export
  path by design, but any field added to `buildCatalogObject` automatically flows to both.
