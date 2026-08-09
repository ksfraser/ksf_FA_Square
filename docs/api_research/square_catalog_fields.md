# Square Catalog API — Writable Field Reference

Source: canonical Square Connect OpenAPI spec `/tmp/opencode/square/square-connect-api.json`
(OpenAPI 3.x, `components/schemas/`). Field tables below are extracted verbatim from the
spec's schema definitions.

> **Spec version note:** This is a **current-generation** spec. Several fields that appear in
> older/Square developer documentation and in some third-party SDK docs are **no longer in this
> schema**: `available_for_online`, `available_for_pickup`, `available_electronically`,
> `ecom_available`, `ecom_visibility`, item-level `pricing_type`, item-level `taxes`,
> `measurement_unit_id` (item), `max_selections`, `text_options`. Their replacements are listed
> inline below.

## Write endpoints (create/update)

| Endpoint | Method | Purpose |
|---|---|---|
| `/v2/catalog/object` | POST | Upsert one `CatalogObject` (create if `id` unknown, else update) |
| `/v2/catalog/batch-upsert` | POST | Batch upsert (`batches[].objects[]`); required `idempotency_key` |
| `/v2/catalog/images` | POST | Create image object (multipart; body `CreateCatalogImageRequest`) |
| `/v2/catalog/images/{image_id}` | PUT | Update image |
| `/v2/catalog/update-item-taxes` | POST | Bulk enable/disable taxes on items (`taxes_to_enable`/`taxes_to_disable`) |
| `/v2/catalog/update-item-modifier-lists` | POST | Bulk enable/disable modifier lists on items |

Conventions:
- On upsert, `CatalogObject.id` is **client-supplied** (e.g. UUID) on create; on update it must be
  the existing object id. `version` is returned by the server and can be sent back to enforce
  optimistic concurrency (omitting it forces an unconditional update).
- Deletion: `DELETE /v2/catalog/object/{object_id}`.

---

## 1. CatalogObject (the wrapper — every object is one of these)

`type` (string enum `CatalogObjectType`) is **required**, along with `id`. Exactly one
`<x>_data` field is populated depending on `type`.

### `CatalogObjectType` enum values
`ITEM`, `IMAGE`, `CATEGORY`, `ITEM_VARIATION`, `TAX`, `DISCOUNT`, `MODIFIER_LIST`, `MODIFIER`,
`PRICING_RULE`, `PRODUCT_SET`, `TIME_PERIOD`, `MEASUREMENT_UNIT`, `SUBSCRIPTION_PLAN_VARIATION`,
`ITEM_OPTION`, `ITEM_OPTION_VAL`, `CUSTOM_ATTRIBUTE_DEFINITION`, `QUICK_AMOUNTS_SETTINGS`,
`SUBSCRIPTION_PLAN`, `AVAILABILITY_PERIOD`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `type` | string | enum `CatalogObjectType` (see above) | **create-only** (required; change = delete + recreate) |
| `id` | string | client-generated on create; must match existing on update | **required both** |
| `version` | integer | server-managed; send back for conditional (concurrent) updates | server-managed (not marked readOnly in spec) |
| `is_deleted` | boolean | server-managed tombstone flag | read-only by convention |
| `updated_at` | string | timestamp | **readOnly: true** |
| `custom_attribute_values` | object | map of custom attribute key → value | both |
| `catalog_v1_ids` | array | legacy V1 ids | read-only in practice |
| `present_at_all_locations` | boolean | if true, present at all locations | both |
| `present_at_location_ids` | array of string | explicit locations where present | both |
| `absent_at_location_ids` | array of string | explicit locations where absent | both |
| `item_data` | `CatalogItem` | type = `ITEM` | both |
| `category_data` | `CatalogCategory` | type = `CATEGORY` | both |
| `item_variation_data` | `CatalogItemVariation` | type = `ITEM_VARIATION` | both |
| `tax_data` | `CatalogTax` | type = `TAX` | both |
| `discount_data` | `CatalogDiscount` | type = `DISCOUNT` | both |
| `modifier_list_data` | `CatalogModifierList` | type = `MODIFIER_LIST` | both |
| `modifier_data` | `CatalogModifier` | type = `MODIFIER` | both |
| `time_period_data` | `CatalogTimePeriod` | type = `TIME_PERIOD` | both |
| `product_set_data` | `CatalogProductSet` | type = `PRODUCT_SET` | both |
| `pricing_rule_data` | `CatalogPricingRule` | type = `PRICING_RULE` | both |
| `image_data` | `CatalogImage` | type = `IMAGE` | both |
| `measurement_unit_data` | `CatalogMeasurementUnit` | type = `MEASUREMENT_UNIT` | both |
| `subscription_plan_data` | `CatalogSubscriptionPlan` | type = `SUBSCRIPTION_PLAN` | both |
| `item_option_data` | `CatalogItemOption` | type = `ITEM_OPTION` | both |
| `item_option_value_data` | `CatalogItemOptionValue` | type = `ITEM_OPTION_VAL` | both |
| `custom_attribute_definition_data` | `CatalogCustomAttributeDefinition` | type = `CUSTOM_ATTRIBUTE_DEFINITION` | both |
| `quick_amounts_settings_data` | `CatalogQuickAmountsSettings` | type = `QUICK_AMOUNTS_SETTINGS` | both |
| `subscription_plan_variation_data` | `CatalogSubscriptionPlanVariation` | type = `SUBSCRIPTION_PLAN_VARIATION` | both |
| `availability_period_data` | `CatalogAvailabilityPeriod` | type = `AVAILABILITY_PERIOD` | both |

**Required in schema:** `type`, `id`.

---

## 2. CatalogItem (`item_data`) — type `ITEM`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | item display name | both |
| `description` | string | plain text description | both |
| `description_html` | string | HTML description (supersedes `description` when set) | both |
| `description_plaintext` | string | server-derived plaintext of HTML description | **readOnly: true** |
| `abbreviation` | string | short name used on receipts | both |
| `label_color` | string | hex color, e.g. `FF0000` | both |
| `is_taxable` | boolean | legacy taxable flag | both |
| `category_id` | string | primary category object id | both |
| `reporting_category` | `CatalogObjectCategory` | `{id, ordinal}` — report/grouping category | both |
| `categories` | array of string | all category ids (incl. sub/group categories) | both |
| `tax_ids` | array of string | tax object ids applied to this item (replaces old `taxes` array) | both |
| `modifier_list_info` | array of `CatalogItemModifierListInfo` | per-item modifier list customization | both |
| `variations` | array of `CatalogObject` | nested `ITEM_VARIATION` objects; ≥1 variation, ≤250 | both (needed at create) |
| `product_type` | enum `CatalogItemProductType` | `REGULAR`, `GIFT_CARD`, `APPOINTMENTS_SERVICE`, `FOOD_AND_BEV`, `EVENT`, `DIGITAL`, `DONATION`, `LEGACY_SQUARE_ONLINE_SERVICE`, `LEGACY_SQUARE_ONLINE_MEMBERSHIP` (API only allows creating `REGULAR` / `APPOINTMENTS_SERVICE`) | both |
| `skip_modifier_screen` | boolean | skip modifier selection screen at POS | both |
| `item_options` | array of `CatalogItemOptionForItem` | options (variation dimensions) available on the item | both |
| `buyer_facing_name` | string | name shown to buyers (Square Online) | both |
| `ecom_uri` | string | Square Online URL slug (replaces old `ecom_visibility`) | both |
| `ecom_image_uris` | array of string | Square Online image URLs | both |
| `ecom_seo_data` | `CatalogEcomSeoData` | `{page_title, page_description, permalink}` | both |
| `image_ids` | array of string | image object ids | both |
| `sort_name` | string | name used for alphabetical sorting | both |
| `kitchen_name` | string | kitchen display name | both |
| `channels` | array of string | channels where item is available (`ONLINE`, `POS` …) — this is the **modern replacement for `available_for_online` / `available_for_pickup`** | both |
| `is_archived` | boolean | archive flag (hides from sale surfaces) | both |
| `food_and_beverage_details` | `CatalogItemFoodAndBeverageDetails` | `{calorie_count, dietary_preferences[], ingredients[]}` for `FOOD_AND_BEV` | both |
| `is_alcoholic` | boolean | alcohol flag | both |

> **Not in this spec:** `pricing_type` (moved to variation), `taxes` (replaced by `tax_ids`),
> `measurement_unit_id` (moved to variation), `available_for_online` / `available_for_pickup` /
> `available_electronically` / `ecom_available` / `ecom_visibility` (replaced by `channels`,
> `ecom_uri`, `ecom_image_uris`, `online_visibility` on category).

### `CatalogItemModifierListInfo` (item-specific modifier list config)
| Field | Type | Notes | Writable |
|---|---|---|---|
| `modifier_list_id` | string | **required** — the modifier list object id | both |
| `modifier_overrides` | array of `CatalogModifierOverride` | `{modifier_id (req), on_by_default, hidden_online_override, on_by_default_override}` | both |
| `min_selected_modifiers` | integer | | both |
| `max_selected_modifiers` | integer | replaces older `max_selections` | both |
| `enabled` | boolean | | both |
| `ordinal` | integer | | both |
| `allow_quantities` | enum `CatalogModifierToggleOverrideType` | `NO`/`YES`/`NOT_SET` | both |
| `is_conversational` | enum `CatalogModifierToggleOverrideType` | | both |
| `hidden_from_customer_override` | enum `CatalogModifierToggleOverrideType` | | both |

---

## 3. CatalogItemVariation (`item_variation_data`) — type `ITEM_VARIATION`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `item_id` | string | parent item object id (set automatically when nested in item) | both |
| `name` | string | variation name | both |
| `sku` | string | merchant SKU | both |
| `upc` | string | numeric UPC (must be 12 digits, GTIN-12) | both |
| `ordinal` | integer | server-assigned ordering | **readOnly: true** |
| `pricing_type` | enum `CatalogPricingType` | `FIXED_PRICING`, `VARIABLE_PRICING` (default `FIXED_PRICING`; VARIABLE prompts price entry at sale) | both |
| `price_money` | `Money` | `{amount, currency}`; required for `FIXED_PRICING` | both |
| `location_overrides` | array of `ItemVariationLocationOverrides` | per-location price / tracking overrides (see pricing file) | both |
| `track_inventory` | boolean | whether inventory is tracked | both |
| `inventory_alert_type` | enum `InventoryAlertType` | `NONE`, `LOW_QUANTITY` | both |
| `inventory_alert_threshold` | integer | low-quantity threshold | both |
| `user_data` | string | internal merchant notes | both |
| `service_duration` | integer | seconds (booking/appointments) | both |
| `available_for_booking` | boolean | | both |
| `item_option_values` | array of `CatalogItemOptionValueForItemVariation` | `{item_option_id, item_option_value_id}` | both |
| `measurement_unit_id` | string | measurement unit object id | both |
| `sellable` | boolean | sellable flag (affects inventory) | both |
| `stockable` | boolean | stockable flag (affects inventory) | both |
| `stockable_conversion` | `CatalogStockConversion` | `{stockable_item_variation_id, stockable_quantity, nonstockable_quantity}` (all required) | both |
| `image_ids` | array of string | | both |
| `team_member_ids` | array of string | team members who can sell | both |
| `kitchen_name` | string | | both |
| `vendor_information` | array of `CatalogItemVariationVendorInformation` | `{vendor_id, vendor_code, unit_cost_money}` | both |

> Limits: each item must have ≥1 variation and ≤250 variations.

### `InventoryAlertType` enum
`NONE`, `LOW_QUANTITY`

---

## 4. CatalogCategory (`category_data`) — type `CATEGORY`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | category name | both |
| `image_ids` | array of string | | both |
| `category_type` | enum `CatalogCategoryType` | `REGULAR_CATEGORY`, `MENU_CATEGORY`, `KITCHEN_CATEGORY` | both |
| `parent_category` | `CatalogObjectCategory` | `{id, ordinal}` — parent category | both |
| `is_top_level` | boolean | | both |
| `channels` | array of string | availability channels | both |
| `availability_period_ids` | array of string | | both |
| `online_visibility` | boolean | show/hide in Square Online (replaces old per-item availability flags) | both |
| `root_category` | string | server-derived root path | **readOnly: true** |
| `ecom_seo_data` | `CatalogEcomSeoData` | `{page_title, page_description, permalink}` | both |
| `path_to_root` | array | parent chain | read-only in practice |

### `CatalogCategoryType` enum
`REGULAR_CATEGORY`, `MENU_CATEGORY`, `KITCHEN_CATEGORY`

---

## 5. CatalogTax (`tax_data`) — type `TAX`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | tax name | both |
| `calculation_phase` | enum `TaxCalculationPhase` | `TAX_SUBTOTAL_PHASE`, `TAX_TOTAL_PHASE` | both |
| `inclusion_type` | enum `TaxInclusionType` | `ADDITIVE`, `INCLUSIVE` | both |
| `percentage` | string | decimal string, e.g. `"8.5"` | both |
| `applies_to_custom_amounts` | boolean | applies to custom/variable-priced amounts | both |
| `enabled` | boolean | active flag | both |
| `applies_to_product_set_id` | string | product set object id (scope) | both |

---

## 6. CatalogImage (`image_data`) — type `IMAGE`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | image name | both |
| `url` | string | public URL of the image (server-managed when uploading via `/v2/catalog/images`) | both |
| `caption` | string | alt/caption text | both |
| `photo_studio_order_id` | string | | both |

> Upload flow: `POST /v2/catalog/images` with `CreateCatalogImageRequest`
> `{idempotency_key (req), object_id, image (req), is_primary}` + multipart file.

---

## 7. CatalogModifier & CatalogModifierList

### CatalogModifier (`modifier_data`) — type `MODIFIER`
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | modifier choice label | both |
| `price_money` | `Money` | extra charge | both |
| `on_by_default` | boolean | default-on at sale | both |
| `ordinal` | integer | sort order | both |
| `modifier_list_id` | string | parent modifier list | both |
| `location_overrides` | array of `ModifierLocationOverrides` | `{location_id, price_money, sold_out(readOnly)}` | both |
| `kitchen_name` | string | | both |
| `image_id` | string | | both |
| `hidden_online` | boolean | hide in Square Online | both |

### CatalogModifierList (`modifier_list_data`) — type `MODIFIER_LIST`
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | list name | both |
| `ordinal` | integer | sort order | both |
| `selection_type` | enum `CatalogModifierListSelectionType` | `SINGLE`, `MULTIPLE` | both |
| `modifier_type` | enum `CatalogModifierListModifierType` | `LIST`, `TEXT` | both |
| `modifiers` | array of `CatalogObject` | nested `MODIFIER` objects | both |
| `image_ids` | array of string | | both |
| `allow_quantities` | boolean | quantity picker for each selection | both |
| `is_conversational` | boolean | | both |
| `max_length` | integer | max chars for `TEXT` modifier type | both |
| `text_required` | boolean | text entry mandatory | both |
| `internal_name` | string | internal (non-buyer-facing) name | both |
| `min_selected_modifiers` | integer | replaces older `max_selections`/`text_options` model | both |
| `max_selected_modifiers` | integer | | both |
| `hidden_from_customer` | boolean | | both |

### Enums
- `CatalogModifierListSelectionType`: `SINGLE`, `MULTIPLE`
- `CatalogModifierListModifierType`: `LIST`, `TEXT`
- `CatalogModifierToggleOverrideType`: `NO`, `YES`, `NOT_SET`

---

## 8. CatalogPricingRule (`pricing_rule_data`) — type `PRICING_RULE`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | rule name | both |
| `time_period_ids` | array of string | `TIME_PERIOD` object ids defining active windows (old `time_periods` field gone) | both |
| `discount_id` | string | `DISCOUNT` object applied by this rule (old `discount_ids` gone) | both |
| `match_products_id` | string | `PRODUCT_SET` id — products the rule matches | both |
| `apply_products_id` | string | `PRODUCT_SET` id — products discount applies to | both |
| `exclude_products_id` | string | `PRODUCT_SET` id — products excluded | both |
| `valid_from_date` | string | YYYY-MM-DD | both |
| `valid_from_local_time` | string | HH:MM | both |
| `valid_until_date` | string | YYYY-MM-DD | both |
| `valid_until_local_time` | string | HH:MM | both |
| `exclude_strategy` | enum `ExcludeStrategy` | `LEAST_EXPENSIVE`, `MOST_EXPENSIVE`, `MOST_EXPENSIVE_LOWEST_VALUE` | both |
| `minimum_order_subtotal_money` | `Money` | min order subtotal for rule to apply | both |
| `customer_group_ids_any` | array of string | customer groups rule applies to | both |

### `CatalogProductSet` (`product_set_data`) — the match/apply/exclude targets
| Field | Type | Notes | Writable |
|---|---|---|---|
| `name` | string | | both |
| `product_ids_any` | array of string | | both |
| `product_ids_all` | array of string | | both |
| `quantity_exact` | integer | | both |
| `quantity_min` | integer | | both |
| `quantity_max` | integer | | both |
| `all_products` | boolean | match all | both |

### `CatalogTimePeriod` (`time_period_data`)
| Field | Type | Notes | Writable |
|---|---|---|---|
| `event` | string | cron-like expression, e.g. `BEGIN(VTIMEZONE;TZID=...)RRULE:...` | both |

### `ExcludeStrategy` enum
`LEAST_EXPENSIVE`, `MOST_EXPENSIVE`, `MOST_EXPENSIVE_LOWEST_VALUE`

---

## 9. CatalogDiscount (`discount_data`) — type `DISCOUNT`

### Fields
| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `name` | string | discount name | both |
| `discount_type` | enum `CatalogDiscountType` | `FIXED_PERCENTAGE`, `FIXED_AMOUNT`, `VARIABLE_PERCENTAGE`, `VARIABLE_AMOUNT` | both |
| `percentage` | string | decimal string; used with FIXED_PERCENTAGE | both |
| `amount_money` | `Money` | used with FIXED_AMOUNT | both |
| `pin_required` | boolean | require PIN to apply | both |
| `label_color` | string | hex color | both |
| `modify_tax_basis` | enum `CatalogDiscountModifyTaxBasis` | `MODIFY_TAX_BASIS`, `DO_NOT_MODIFY_TAX_BASIS` | both |
| `maximum_amount_money` | `Money` | cap on discount amount (older docs name: `max_discount_money`) | both |

> **Not in this spec:** `valid_from` / `valid_until`, `apply_to_all_products`,
> `discount_scope`, `pricing_rule_ids`, `exclude_products_id`. Discount-to-rule wiring is done
> via `CatalogPricingRule.discount_id` instead.

---

## 10. CatalogItemOption & CatalogItemOptionValue (variation dimensions)

### CatalogItemOption (`item_option_data`)
| Field | Type | Notes | Writable |
|---|---|---|---|
| `name` | string | | both |
| `display_name` | string | | both |
| `description` | string | | both |
| `show_colors` | boolean | | both |
| `values` | array of `CatalogObject` | nested `ITEM_OPTION_VAL` objects | both |

### CatalogItemOptionValue (`item_option_value_data`)
| Field | Type | Notes | Writable |
|---|---|---|---|
| `item_option_id` | string | parent option | both |
| `name` | string | | both |
| `description` | string | | both |
| `color` | string | | both |
| `ordinal` | integer | | both |

### CatalogItemOptionForItem
`item_option_id` (string) — both.

### CatalogItemOptionValueForItemVariation
`item_option_id`, `item_option_value_id` — both.

---

## 11. Money

| Field | Type | Notes | Writable |
|---|---|---|---|
| `amount` | integer | amount in the smallest currency unit (e.g. cents); can be negative where signed | both |
| `currency` | enum `Currency` | ISO 4217 + extras (`UNKNOWN_CURRENCY`, `BTC`, `XUS`); see full enum in pricing file | both |

---

## 12. CatalogQuery (search filters — read-side only)

`CatalogQuery` is used in `POST /v2/catalog/search`. All fields are query filters, not data.

| Field | Type | Notes |
|---|---|---|
| `sorted_attribute_query` | `CatalogQuerySortedAttribute` | `{attribute_name(req), initial_attribute_value, sort_order: DESC/ASC}` |
| `exact_query` | `CatalogQueryExact` | `{attribute_name(req), attribute_value(req)}` |
| `set_query` | `CatalogQuerySet` | `{attribute_name(req), attribute_values(req)}` |
| `prefix_query` | `CatalogQueryPrefix` | `{attribute_name(req), attribute_prefix(req)}` |
| `range_query` | `CatalogQueryRange` | `{attribute_name(req), attribute_min_value, attribute_max_value}` |
| `text_query` | `CatalogQueryText` | `{keywords(req)}` |
| `items_for_tax_query` | `CatalogQueryItemsForTax` | `{tax_ids(req)}` |
| `items_for_modifier_list_query` | `CatalogQueryItemsForModifierList` | `{modifier_list_ids(req)}` |
| `items_for_item_options_query` | `CatalogQueryItemsForItemOptions` | `{item_option_ids}` |
| `item_variations_for_item_option_values_query` | `CatalogQueryItemVariationsForItemOptionValues` | `{item_option_value_ids}` |

`POST /v2/catalog/search-catalog-items` (`SearchCatalogItemsRequest`) adds: `text_filter`,
`category_ids`, `stock_levels`, `enabled_location_ids`, `cursor`, `limit`,
`sort_order`, `product_types`, `custom_attribute_filters`, `archived_state`.

---

## Batch limits (`CatalogInfoResponseLimits`, from `/v2/catalog/info`)
- `batch_upsert_max_objects_per_batch` — max objects per batch in `BatchUpsertCatalogObjects`
- `batch_upsert_max_total_objects`
- `batch_retrieve_max_object_ids`
- `search_max_page_limit`
- `batch_delete_max_object_ids`
- `update_item_taxes_max_item_ids`, `update_item_taxes_max_taxes_to_enable/disable`
- `update_item_modifier_lists_max_item_ids`, `update_item_modifier_lists_max_modifier_lists_to_enable/disable`

---

## Full readOnly (non-writable) list for catalog objects
| Schema | Field |
|---|---|
| `CatalogObject` | `updated_at` |
| `CatalogItem` | `description_plaintext` |
| `CatalogItemVariation` | `ordinal` |
| `CatalogCategory` | `root_category` |
| `ItemVariationLocationOverrides` | `sold_out`, `sold_out_valid_until` |
| `ModifierLocationOverrides` | `sold_out` |
| `CatalogCustomAttributeValue` | `custom_attribute_definition_id`, `key`, `type` |
| `CatalogCustomAttributeDefinition` | `custom_attribute_usage_count` |
