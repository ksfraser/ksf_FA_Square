# Square Pricing Fields — price_money, location_overrides, pricing rules, discounts

Extracted from `/tmp/opencode/square/square-connect-api.json` (canonical OpenAPI spec).
Focused on everything that determines sale price, per-location pricing, automatic discounts
(pricing rules) and catalog discounts.

---

## 1. Money (base type for all money fields)

| Field | Type | Notes | Writable |
|---|---|---|---|
| `amount` | integer | **smallest currency unit** (cents). Can be negative where explicitly allowed (refunds/adjustments). Positive elsewhere. | both |
| `currency` | enum `Currency` | ISO 4217 code; see enum below | both |

`Currency` enum (abridged — full list in spec): `UNKNOWN_CURRENCY`, `AED`, `AFN`, `ALL` … `USD`,
`CAD`, `EUR`, `GBP`, `JPY`, `AUD`, `ZAR`, `BRL`, `MXN`, `INR`, `KRW`, `CHF`, `CNY`, `HKD`, `SGD`,
`SEK`, `NOK`, `DKK`, `PLN`, `RUB`, `TRY`, `AED`, `SAR`, `ILS`, `THB`, `MYR`, `IDR`, `VND`, `PHP`,
`PKR`, `BDT`, `EGP`, `NGN`, `KES`, `BTC`, `XUS`, plus `ZZ`/`XXX`-style specials. (Full list: 170+ entries in spec.)

> No per-field `currency` default — must supply `currency` alongside `amount` for
> `price_money` on variation/location overrides.

---

## 2. CatalogItemVariation price fields

| Field | Type | Notes | Writable |
|---|---|---|---|
| `pricing_type` | enum `CatalogPricingType` | `FIXED_PRICING` (default) or `VARIABLE_PRICING` | both |
| `price_money` | `Money` (`amount`, `currency`) | required when `pricing_type` = `FIXED_PRICING`; ignored for `VARIABLE_PRICING` | both |
| `location_overrides` | array of `ItemVariationLocationOverrides` | per-location price override (below) | both |

`CatalogPricingType` enum: `FIXED_PRICING`, `VARIABLE_PRICING`

---

## 3. ItemVariationLocationOverrides (per-location pricing / inventory)

Nested in `CatalogItemVariation.location_overrides`.

| Field | Type | Notes | Writable |
|---|---|---|---|
| `location_id` | string | target location object id | both |
| `price_money` | `Money` | **per-location price**; overrides the variation's default `price_money` at that location | both |
| `pricing_type` | enum `CatalogPricingType` | per-location pricing type override | both |
| `track_inventory` | boolean | per-location inventory tracking override | both |
| `inventory_alert_type` | enum `InventoryAlertType` | `NONE`, `LOW_QUANTITY` | both |
| `inventory_alert_threshold` | integer | | both |
| `sold_out` | boolean | server-managed "sold out at this location" flag | **readOnly: true** |
| `sold_out_valid_until` | string | | **readOnly: true** |

> Effective price at a location = `location_overrides[].price_money` if present for that
> location, else the variation's top-level `price_money`.

---

## 4. CatalogModifier price fields

`CatalogModifier` (`modifier_data`):

| Field | Type | Notes | Writable |
|---|---|---|---|
| `price_money` | `Money` | extra charge to add when modifier selected | both |
| `location_overrides` | array of `ModifierLocationOverrides` | per-location modifier price | both |

`ModifierLocationOverrides`:

| Field | Type | Notes | Writable |
|---|---|---|---|
| `location_id` | string | | both |
| `price_money` | `Money` | | both |
| `sold_out` | boolean | | **readOnly: true** |

---

## 5. CatalogPricingRule (`pricing_rule_data`) — automatic discount rules

| Field | Type | Notes | Writable |
|---|---|---|---|
| `name` | string | rule name | both |
| `time_period_ids` | array of string | `TIME_PERIOD` object ids for active windows | both |
| `discount_id` | string | `DISCOUNT` object applied when rule matches | both |
| `match_products_id` | string | `PRODUCT_SET` id whose membership triggers the rule | both |
| `apply_products_id` | string | `PRODUCT_SET` id receiving the discount | both |
| `exclude_products_id` | string | `PRODUCT_SET` id excluded from the rule | both |
| `valid_from_date` | string | `YYYY-MM-DD` | both |
| `valid_from_local_time` | string | `HH:MM` | both |
| `valid_until_date` | string | `YYYY-MM-DD` | both |
| `valid_until_local_time` | string | `HH:MM` | both |
| `exclude_strategy` | enum `ExcludeStrategy` | `LEAST_EXPENSIVE`, `MOST_EXPENSIVE`, `MOST_EXPENSIVE_LOWEST_VALUE` | both |
| `minimum_order_subtotal_money` | `Money` | rule only fires above this subtotal | both |
| `customer_group_ids_any` | array of string | rule applies to these customer groups | both |

> Naming drift vs older docs: `time_period_ids` (not `time_periods`), `discount_id` (not
> `discount_ids`), and validity split into `valid_from_date` + `valid_from_local_time` (not a
> single `valid_from`/`valid_until`).

### `CatalogProductSet` (`product_set_data`) — referenced by the `*_products_id` fields
| Field | Type | Notes | Writable |
|---|---|---|---|
| `name` | string | | both |
| `product_ids_any` | array of string | matches any of these | both |
| `product_ids_all` | array of string | matches all of these | both |
| `quantity_exact` | integer | match on exact quantity | both |
| `quantity_min` | integer | | both |
| `quantity_max` | integer | | both |
| `all_products` | boolean | match every product | both |

### `CatalogTimePeriod` (`time_period_data`)
| Field | Type | Notes | Writable |
|---|---|---|---|
| `event` | string | iCalendar-style repeat rule, e.g. `BEGIN:VTIMEZONE;TZID=...RRULE:FREQ=...` | both |

### `ExcludeStrategy` enum
`LEAST_EXPENSIVE`, `MOST_EXPENSIVE`, `MOST_EXPENSIVE_LOWEST_VALUE`

---

## 6. CatalogDiscount (`discount_data`) — the discount applied by rules

| Field | Type | Notes | Writable |
|---|---|---|---|
| `name` | string | | both |
| `discount_type` | enum `CatalogDiscountType` | `FIXED_PERCENTAGE`, `FIXED_AMOUNT`, `VARIABLE_PERCENTAGE`, `VARIABLE_AMOUNT` | both |
| `percentage` | string | decimal string (e.g. `"10"`), for percentage types | both |
| `amount_money` | `Money` | for `FIXED_AMOUNT` / `VARIABLE_AMOUNT` | both |
| `pin_required` | boolean | require merchant PIN to apply | both |
| `label_color` | string | hex | both |
| `modify_tax_basis` | enum `CatalogDiscountModifyTaxBasis` | `MODIFY_TAX_BASIS`, `DO_NOT_MODIFY_TAX_BASIS` | both |
| `maximum_amount_money` | `Money` | cap on the discounted amount (older docs: `max_discount_money`) | both |

> **Not in this spec version:** `apply_to_all_products`, `discount_scope`, `pricing_rule_ids`,
> `exclude_products_id`, `valid_from`/`valid_until` on the discount. Rule linkage is one-way via
> `CatalogPricingRule.discount_id`.

`CatalogDiscountType` enum: `FIXED_PERCENTAGE`, `FIXED_AMOUNT`, `VARIABLE_PERCENTAGE`, `VARIABLE_AMOUNT`
`CatalogDiscountModifyTaxBasis` enum: `MODIFY_TAX_BASIS`, `DO_NOT_MODIFY_TAX_BASIS`

---

## 7. Tax interplay with pricing

`CatalogTax` (`tax_data`) fields affecting money: `percentage` (string, e.g. `"8.5"`),
`calculation_phase` (`TAX_SUBTOTAL_PHASE` / `TAX_TOTAL_PHASE`), `inclusion_type`
(`ADDITIVE` / `INCLUSIVE`), `applies_to_custom_amounts`, `enabled`. Applied per item via
`CatalogItem.tax_ids`.

---

## 8. Pricing-related readOnly fields

| Schema | Field |
|---|---|
| `ItemVariationLocationOverrides` | `sold_out`, `sold_out_valid_until` |
| `ModifierLocationOverrides` | `sold_out` |
| `CatalogItemVariation` | `ordinal` |

---

## 9. Gotchas likely to bite an implementation

1. `Money.amount` is in the **smallest currency unit** (cents) — an integer; 0 == zero; no floats.
2. `price_money` must include both `amount` and `currency`; the location's `currency`
   (`Location.currency`) should match what you send.
3. `VARIABLE_PRICING` variations must **not** have a `price_money`.
4. Per-location price overrides only apply for locations explicitly listed — unknown/omitted
   locations fall back to the variation's default `price_money`.
5. `location_overrides` is only honored when `present_at_location_ids` (or
   `present_at_all_locations`) makes the variation present at that location.
6. Discounts applied by pricing rules reference a `PRODUCT_SET` (`match_products_id`) — a
   `PRODUCT_SET` object must be created separately for rule wiring.
7. `pricing_type` at variation level, plus an optional per-location `pricing_type` in
   `location_overrides`.
