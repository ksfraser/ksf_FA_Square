# ksf_FA_Square → Square Catalog API — Gap Matrix

Scope: writable Square Catalog/Location fields vs. what the module **actually writes** on the
product-push path (`CatalogExporter::buildCatalogObject`, `resolveCategory`, `resolveTax`,
`uploadImage`, plus `pages/export.php` and `ItemEventSyncService` — which delegates 100% to
`CatalogExporter::upsertProduct`).

Legend:
- **Covered (mapped)** = value derived from FA data (stock_master / barcodes / kit prices / config).
- **Covered (hardcoded)** = set to a constant, not derived from FA.
- **Indirect** = covered by the image-upload flow (Square links the image itself), not written explicitly.
- **n/a** = readOnly / server-managed / auto-set by Square; not writable.

Total gaps: **131** writable fields across the audited schemas.

---

## CatalogObject (wrapper) — fields the module controls

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `type` | create-only | ✅ Covered | `CatalogExporter::buildCatalogObject` (`'ITEM'`/`'ITEM_VARIATION'`), `resolveCategory` (`'CATEGORY'`), `resolveTax` (`'TAX'`), `uploadImage` (`'IMAGE'`) | No | n/a |
| `id` | both | ✅ Covered | `buildCatalogObject` (`#sku`, `#sku_var` or existing id) | No | n/a |
| `version` | both (server-managed) | ✅ Covered | `buildCatalogObject` (only on update) | No | n/a |
| `custom_attribute_values` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `present_at_all_locations` | both | ⚠️ Read-only usage (skip logic) | `pages/export.php:365`, `ExportService::processItem:198` (never set) | Yes | FA_ProductAttributes |
| `present_at_location_ids` | both | ❌ NOT written | — | Yes | Sales (locations) / FA_ProductAttributes |
| `absent_at_location_ids` | both | ❌ NOT written | — | Yes | Sales (locations) / FA_ProductAttributes |

## CatalogItem (`item_data`)

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ✅ Covered (mapped) | `buildCatalogObject:447` (= FA `stock_master.description`; event path `ItemEventSyncService::sync:115`) | No | n/a |
| `description` | both | ✅ Covered (mapped) | `buildCatalogObject:448` (= FA `description`; **FA `long_description` never read**) | No | n/a |
| `description_html` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `abbreviation` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `label_color` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `is_taxable` | both | ❌ NOT written (only `tax_ids`) | — | Yes | Sales (tax config) |
| `category_id` | both | ✅ Covered (mapped) | `buildCatalogObject:450` + `resolveCategory:468` (FA `stock_category`) | No | n/a |
| `reporting_category` | both | ❌ NOT written | — | Yes | Sales (reporting/group category) |
| `categories` | both | ❌ NOT written (primary only) | — | Yes | Sales (stock_category) |
| `tax_ids` | both | ✅ Covered (mapped) | `buildCatalogObject:456` + `resolveTax:502` (FA `item_tax_types`) | No | n/a |
| `modifier_list_info` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `variations` | both | ✅ Covered | `buildCatalogObject:452` (single variation) | No | n/a |
| `product_type` | both | ❌ NOT written (defaults `REGULAR`) | — | Yes | FA_ProductAttributes |
| `skip_modifier_screen` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `item_options` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `buyer_facing_name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `ecom_uri` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `ecom_image_uris` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `ecom_seo_data` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `image_ids` | both | ⚠️ Indirect (via `CreateCatalogImageRequest.object_id`) | `uploadImage:378` (Square links automatically) | No (indirect) | n/a |
| `sort_name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `kitchen_name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `channels` | both | ❌ NOT written | — **modern replacement for `available_for_online`** | Yes | FA_ProductAttributes |
| `is_archived` | both | ❌ NOT written (FA `inactive` skips, not archives) | — | Yes | FA_ProductAttributes |
| `food_and_beverage_details` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `is_alcoholic` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `description_plaintext` | readOnly | ❌ | — | n/a | n/a |

## CatalogItemVariation (`item_variation_data`)

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `item_id` | both | ✅ auto (nested in item) | `buildCatalogObject:452` | No | n/a |
| `name` | both | ✅ Covered (mapped) | `buildCatalogObject:432` (= FA description) | No | n/a |
| `sku` | both | ✅ Covered (mapped) | `buildCatalogObject:433` (FA barcode via `getItemSku`) | No | n/a |
| `upc` | both | ❌ NOT written (barcode goes to `sku` instead) | — | Yes | FA_ProductAttributes (item_codes) |
| `ordinal` | readOnly | ❌ | — | n/a | n/a |
| `pricing_type` | both | ✅ Covered (**hardcoded** `FIXED_PRICING`) | `buildCatalogObject:434` | No | n/a |
| `price_money` | both | ✅ Covered (mapped) | `buildCatalogObject:435-437` (`get_kit_price` → cents via `SquarePrice`) | No | n/a |
| `location_overrides` | both | ❌ NOT written — **per-location pricing** | — | Yes | Sales (price lists / sales_types) |
| `track_inventory` | both | ✅ Covered (**hardcoded** `true`) | `buildCatalogObject:438` | No | n/a |
| `inventory_alert_type` | both | ❌ NOT written | — | Yes | Sales / FA_ProductAttributes |
| `inventory_alert_threshold` | both | ❌ NOT written | — | Yes | Sales / FA_ProductAttributes |
| `user_data` | both | ❌ NOT written | — | Yes | n/a |
| `service_duration` | both | ❌ NOT written (appointments) | — | Yes | ProjectMgmt / FA_ProductAttributes |
| `available_for_booking` | both | ❌ NOT written | — | Yes | ProjectMgmt / FA_ProductAttributes |
| `item_option_values` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `measurement_unit_id` | both | ❌ NOT written (FA `stock_master.units` read but never mapped) | — | Yes | FA_ProductAttributes (units) |
| `sellable` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `stockable` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `stockable_conversion` | both | ❌ NOT written | — | Yes | n/a |
| `image_ids` | both | ❌ NOT written (variation level) | — | Yes | n/a |
| `team_member_ids` | both | ❌ NOT written | — | Yes | HRM |
| `kitchen_name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `vendor_information` | both | ❌ NOT written | — | Yes | Purchasing (Sales/CRM scope for now) |

## CatalogCategory (`category_data`)

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ✅ Covered (mapped) | `resolveCategory:484` | No | n/a |
| `image_ids` | both | ❌ NOT written | — | Yes | n/a |
| `category_type` | both | ❌ NOT written (defaults `REGULAR_CATEGORY`) | — | Yes | Sales (stock_category) |
| `parent_category` | both | ❌ NOT written | — | Yes | Sales (stock_category) |
| `is_top_level` | both | ❌ NOT written | — | Yes | Sales (stock_category) |
| `channels` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `availability_period_ids` | both | ❌ NOT written | — | Yes | n/a |
| `online_visibility` | both | ❌ NOT written — **modern replacement for old per-item availability** | — | Yes | FA_ProductAttributes |
| `ecom_seo_data` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `root_category` | readOnly | ❌ | — | n/a | n/a |

## CatalogTax (`tax_data`)

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ✅ Covered (mapped) | `resolveTax:518` (FA `item_tax_types.name`) | No | n/a |
| `calculation_phase` | both | ✅ Covered (**hardcoded** `TAX_SUBTOTAL_PHASE`) | `resolveTax:520` | No | n/a |
| `inclusion_type` | both | ✅ Covered (**hardcoded** `ADDITIVE`) | `resolveTax:521` | No | n/a |
| `percentage` | both | ⚠️ Covered but **always `"0"`** (FA rate never read) | `resolveTax:519`; callers pass 0.0 (`export.php:360`, `ExportService:192`, `ItemEventSyncService:121`) | Yes | Sales (tax rates) |
| `applies_to_custom_amounts` | both | ❌ NOT written | — | Yes | Sales (tax config) |
| `enabled` | both | ✅ Covered (**hardcoded** `true`) | `resolveTax:522` | No | n/a |
| `applies_to_product_set_id` | both | ❌ NOT written | — | Yes | Sales (tax scope) |

## CatalogImage (`image_data`)

| Square field | Writable | Our module covers? | Where (file:method) | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ NOT written (default empty) | — | Yes | n/a |
| `url` | both (server-managed on upload) | ✅ Indirect (via upload) | `uploadImage` | No | n/a |
| `caption` | both | ✅ Covered (mapped = item description) | `uploadImage:374` | No | n/a |
| `photo_studio_order_id` | both | ❌ NOT written | — | Yes | n/a |

## CatalogModifier (`modifier_data`) — entire object unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `price_money` | both | ❌ NOT written | — | Yes | Sales / FA_ProductAttributes |
| `on_by_default` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `ordinal` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `modifier_list_id` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `location_overrides` | both | ❌ NOT written | — | Yes | Sales (locations) |
| `kitchen_name` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `image_id` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |
| `hidden_online` | both | ❌ NOT written | — | Yes | FA_ProductAttributes |

## CatalogModifierList (`modifier_list_data`) — entire object unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ | — | Yes | FA_ProductAttributes |
| `ordinal` | both | ❌ | — | Yes | FA_ProductAttributes |
| `selection_type` | both | ❌ | — | Yes | FA_ProductAttributes |
| `modifier_type` | both | ❌ | — | Yes | FA_ProductAttributes |
| `modifiers` | both | ❌ | — | Yes | FA_ProductAttributes |
| `image_ids` | both | ❌ | — | Yes | n/a |
| `allow_quantities` | both | ❌ | — | Yes | FA_ProductAttributes |
| `is_conversational` | both | ❌ | — | Yes | FA_ProductAttributes |
| `max_length` | both | ❌ | — | Yes | FA_ProductAttributes |
| `text_required` | both | ❌ | — | Yes | FA_ProductAttributes |
| `internal_name` | both | ❌ | — | Yes | FA_ProductAttributes |
| `min_selected_modifiers` | both | ❌ | — | Yes | FA_ProductAttributes |
| `max_selected_modifiers` | both | ❌ | — | Yes | FA_ProductAttributes |
| `hidden_from_customer` | both | ❌ | — | Yes | FA_ProductAttributes |

## CatalogPricingRule (`pricing_rule_data`) — entire object unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ | — | Yes | Sales (discounts) |
| `time_period_ids` | both | ❌ | — | Yes | Sales (promotions) |
| `discount_id` | both | ❌ | — | Yes | Sales (discounts) |
| `match_products_id` | both | ❌ | — | Yes | Sales (discounts) |
| `apply_products_id` | both | ❌ | — | Yes | Sales (discounts) |
| `exclude_products_id` | both | ❌ | — | Yes | Sales (discounts) |
| `valid_from_date` | both | ❌ | — | Yes | Sales (promotions) |
| `valid_from_local_time` | both | ❌ | — | Yes | Sales (promotions) |
| `valid_until_date` | both | ❌ | — | Yes | Sales (promotions) |
| `valid_until_local_time` | both | ❌ | — | Yes | Sales (promotions) |
| `exclude_strategy` | both | ❌ | — | Yes | Sales (discounts) |
| `minimum_order_subtotal_money` | both | ❌ | — | Yes | Sales (promotions) |
| `customer_group_ids_any` | both | ❌ | — | Yes | CRM (customer groups) |

## CatalogProductSet (`product_set_data`) — unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ | — | Yes | Sales (discounts) |
| `product_ids_any` | both | ❌ | — | Yes | Sales (discounts) |
| `product_ids_all` | both | ❌ | — | Yes | Sales (discounts) |
| `quantity_exact` | both | ❌ | — | Yes | Sales (discounts) |
| `quantity_min` | both | ❌ | — | Yes | Sales (discounts) |
| `quantity_max` | both | ❌ | — | Yes | Sales (discounts) |
| `all_products` | both | ❌ | — | Yes | Sales (discounts) |

## CatalogTimePeriod (`time_period_data`)

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `event` | both | ❌ | — | Yes | Sales (promotions) |

## CatalogDiscount (`discount_data`) — entire object unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ | — | Yes | Sales (discounts) |
| `discount_type` | both | ❌ | — | Yes | Sales (discounts) |
| `percentage` | both | ❌ | — | Yes | Sales (discounts) |
| `amount_money` | both | ❌ | — | Yes | Sales (discounts) |
| `pin_required` | both | ❌ | — | Yes | n/a |
| `label_color` | both | ❌ | — | Yes | n/a |
| `modify_tax_basis` | both | ❌ | — | Yes | Sales (tax) |
| `maximum_amount_money` | both | ❌ | — | Yes | Sales (discounts) |

## CatalogItemOption / CatalogItemOptionValue (variation dimensions) — unwritten

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `item_option_data.name` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_data.display_name` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_data.description` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_data.show_colors` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_data.values` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_value_data.item_option_id` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_value_data.name` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_value_data.description` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_value_data.color` | both | ❌ | — | Yes | FA_ProductAttributes |
| `item_option_value_data.ordinal` | both | ❌ | — | Yes | FA_ProductAttributes |

## Location (Locations API) — module only READS (`listLocations`), never writes

| Square field | Writable | Our module covers? | Where | Gap? | Suggested home |
|---|---|---|---|---|---|
| `name` | both | ❌ | (read-only usage in `export.php`/`config.php`) | Yes | Sales (locations master) / CRM |
| `address` (+ all subfields) | both | ❌ | — | Yes | Sales / CRM |
| `timezone` | both | ❌ | — | Yes | Sales / CRM |
| `language_code` | both | ❌ | — | Yes | Sales / CRM |
| `phone_number` | both | ❌ | — | Yes | CRM |
| `business_name` | both | ❌ | — | Yes | CRM |
| `type` | both | ❌ | — | Yes | Sales / CRM |
| `website_url` | both | ❌ | — | Yes | CRM |
| `business_hours` | both | ❌ | — | Yes | Sales / CRM |
| `business_email` | both | ❌ | — | Yes | CRM |
| `description` | both | ❌ | — | Yes | CRM |
| `twitter_username` | both | ❌ | — | Yes | CRM |
| `instagram_username` | both | ❌ | — | Yes | CRM |
| `facebook_url` | both | ❌ | — | Yes | CRM |
| `coordinates` | both | ❌ | — | Yes | Sales / CRM |
| `mcc` | both | ❌ | — | Yes | CRM |
| `status` | both | ❌ | — | Yes | Sales / CRM |
| `id`, `capabilities`, `created_at`, `merchant_id`, `country`, `currency`, `logo_url`, `pos_background_url`, `full_format_logo_url`, `tax_ids` | readOnly | ❌ | — | n/a | n/a |

---

## Fields we WRITE that the API marks readOnly / removed (bugs)

Direct audit of every write call site found **zero** fields currently written that the spec
marks readOnly or removed. The mismatch risks are *latent* (see `square_usage_notes.md` §4):

| Field | Status | Risk |
|---|---|---|
| `available_for_online` / `available_for_pickup` / `available_electronically` | **removed from current spec** (replaced by `channels`) | SDK 40.0.0 still exposes setters — a naive fix of the dead "Available Online" UI toggle would write a removed field |
| `tax_data.percentage` | writable, but **always sent as `"0"`** | Data-integrity bug: FA tax rate never read, so tax objects are created with a 0% rate |
| `version` on update | not readOnly (sanctioned concurrency pattern) | ✅ safe as used |
| `sold_out` / `sold_out_valid_until` (location overrides) | readOnly | SDK exposes setters — must not be written when adding `location_overrides` |
| Location `country`, `currency`, `logo_url`, `tax_ids` etc. | readOnly | SDK `Location` model exposes setters — must not be written in a future Location-write feature |
