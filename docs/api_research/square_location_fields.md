# Square Locations API — Writable Field Reference

Extracted from `/tmp/opencode/square/square-connect-api.json` (canonical OpenAPI spec),
`components/schemas/Location` and nested sub-schemas.

## Endpoints
| Endpoint | Method | Purpose |
|---|---|---|
| `/v2/locations` | GET | List locations |
| `/v2/locations` | POST | Create location (`CreateLocationRequest { location }`) |
| `/v2/locations/{location_id}` | GET | Retrieve one location |
| `/v2/locations/{location_id}` | PUT | Update location (`UpdateLocationRequest { location }`) |

Create/update semantics: `CreateLocationRequest` and `UpdateLocationRequest` both wrap a single
`Location` object; you send only the writable fields you want to set (partial update allowed on PUT).

---

## 1. Location — full field table

| Field | Type | Notes/Enum | Writable |
|---|---|---|---|
| `id` | string | server-assigned location id | **readOnly: true** |
| `name` | string | location name (used on receipts) | both (required at create) |
| `address` | `Address` | physical address (see §2) | both |
| `timezone` | string | IANA tz, e.g. `America/New_York` | both |
| `capabilities` | array of string | `CREDIT_CARD_PROCESSING`, `AUTOMATIC_TRANSFERS` | **readOnly: true** |
| `status` | enum `LocationStatus` | `ACTIVE`, `INACTIVE` | both (update to activate/deactivate) |
| `created_at` | string | timestamp | **readOnly: true** |
| `merchant_id` | string | parent merchant | **readOnly: true** |
| `country` | enum `Country` | ISO 3166-1 alpha-2 (`US`, `GB`, `ZA` …) | **readOnly: true** (set at creation by account; `Address.country` is writable) |
| `language_code` | string | BCP 47, e.g. `en-US` | both |
| `currency` | enum `Currency` | ISO 4217 | **readOnly: true** |
| `phone_number` | string | | both |
| `business_name` | string | | both |
| `type` | enum `LocationType` | `PHYSICAL`, `MOBILE` | both (required at create) |
| `website_url` | string | | both |
| `business_hours` | `BusinessHours` | `{periods[]: {day_of_week, start_local_time, end_local_time}}` | both |
| `business_email` | string | | both |
| `description` | string | | both |
| `twitter_username` | string | | both |
| `instagram_username` | string | | both |
| `facebook_url` | string | | both |
| `coordinates` | `Coordinates` | `{latitude, longitude}` (older docs name: `cords`) | both |
| `logo_url` | string | | **readOnly: true** |
| `pos_background_url` | string | | **readOnly: true** |
| `mcc` | string | merchant category code (4 digits) | both |
| `full_format_logo_url` | string | | **readOnly: true** |
| `tax_ids` | `TaxIds` | gov tax identifiers (see §4) | **readOnly: true** |

> **Not in this spec:** direct `postal_code` and `note` fields on `Location` — postal code lives
> inside `address.postal_code`; there is no `note` field in this schema version.

### Enums
- `LocationStatus`: `ACTIVE`, `INACTIVE`
- `LocationType`: `PHYSICAL`, `MOBILE`

---

## 2. Address (nested under `Location.address`)

All fields writable on create/update.

| Field | Type | Notes |
|---|---|---|
| `address_line_1` | string | |
| `address_line_2` | string | |
| `address_line_3` | string | |
| `locality` | string | city |
| `sublocality` | string | district |
| `sublocality_2` | string | |
| `sublocality_3` | string | |
| `administrative_district_level_1` | string | state/province |
| `administrative_district_level_2` | string | |
| `administrative_district_level_3` | string | |
| `postal_code` | string | |
| `country` | enum `Country` | ISO 3166-1 alpha-2 (`US`, `GB`, `ZA`, …) |
| `first_name` | string | |
| `last_name` | string | |

---

## 3. BusinessHours & BusinessHoursPeriod

`Location.business_hours` → `{ periods: BusinessHoursPeriod[] }`. All writable.

| Field | Type | Notes |
|---|---|---|
| `day_of_week` | enum `DayOfWeek` | `SUN`, `MON`, `TUE`, `WED`, `THU`, `FRI`, `SAT` |
| `start_local_time` | string | `HH:MM` (24h) |
| `end_local_time` | string | `HH:MM` (24h) |

## Coordinates (`Location.coordinates`)
| Field | Type | Notes |
|---|---|---|
| `latitude` | number | degrees |
| `longitude` | number | degrees |

## 4. TaxIds (`Location.tax_ids`) — all readOnly
| Field | Type | Notes |
|---|---|---|
| `eu_vat` | string | **readOnly: true** |
| `fr_siret` | string | **readOnly: true** |
| `fr_naf` | string | **readOnly: true** |
| `es_nif` | string | **readOnly: true** |
| `jp_qii` | string | **readOnly: true** |

---

## 5. Summary — readOnly (NOT writable) Location fields
`id`, `capabilities`, `created_at`, `merchant_id`, `country`, `currency`, `logo_url`,
`pos_background_url`, `full_format_logo_url`, `tax_ids` (and all members of `TaxIds`).

## 6. Writable (create + update) Location fields
`name`, `address` (incl. all `Address` sub-fields), `timezone`, `language_code`, `phone_number`,
`business_name`, `type`, `website_url`, `business_hours`, `business_email`, `description`,
`twitter_username`, `instagram_username`, `facebook_url`, `coordinates`, `mcc`.

## 7. Gotchas
- `status`, `country`, `currency` are effectively fixed after creation: `status` may be flipped
  via update, but `country`/`currency` are marked `readOnly: true` — set the correct country on
  the `address` and match `currency` to the merchant's default.
- `name` and `type` are required at creation (`POST /v2/locations`); update is partial (send only
  changed fields in `UpdateLocationRequest.location`).
- Location `id` from the API must match `present_at_location_ids` / `location_overrides[].location_id`
  used in catalog objects.
