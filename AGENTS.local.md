<!-- Repo-specific appendix to the shared AGENTS.md. Generic conventions live in AGENTS_ARCH.md (hardlinked). -->

# AGENTS.md - ksf_FA_Square
## DTO (Data Transfer Object) Pattern for Form Handling
For form handling and request data, we use DTOs to encapsulate the extraction and validation of POST/GET/SESSION variables.
### The Problem
Previously, pages had multiple lines like:
```php
$locationId = $_POST['location_id'] ?? '0';
$category = (int)($_POST['category'] ?? -1);
$stockLike = $_POST['stocklike'] ?? '';
// ... and many more
```
This violates SRP because:
1. The page is responsible for both extracting data AND orchestrating the business logic
2. No centralized place for type casting, default values, or validation
3. Hard to test in isolation
### The Solution
Use DTO classes that:
1. Extract data from superglobals ($_POST, $_GET)
2. Apply type casting and default values
3. Provide type-safe getters
4. Optionally include validation logic
### Implemented DTOs
| DTO Class | Purpose |
|-----------|---------|
| `ExportRequest` | Encapsulates export form data: location, category, stock filter, max items, etc. |
| `ImportRequest` | Encapsulates import form data: customer, date range, trial run, etc. |
### Example: ExportRequest
```php
<?php
declare(strict_types=1);
namespace ksfraser\FrontAccounting\Square\DTO;
class ExportRequest
{
    private string $locationId;
    private int $category;
    private string $stockLike;
    private bool $uploadImages;
    // ... more fields
    public static function fromPost(
        string $defaultCurrency = '',
        int $defaultSalesType = 0
    ): self {
        return self::fromArray($_POST, $defaultCurrency, $defaultSalesType);
    }
    public static function fromArray(
        array $data,
        string $defaultCurrency = '',
        int $defaultSalesType = 0
    ): self {
        return new self(
            $data['location_id'] ?? '0',
            isset($data['category']) ? (int)$data['category'] : -1,
            $data['stocklike'] ?? '',
            isset($data['upload']) ? (int)$data['upload'] === 1 : false,
            // ... more fields
        );
    }
    // Type-safe getters
    public function getLocationId(): string { return $this->locationId; }
    public function getCategory(): int { return $this->category; }
    public function getCategoryId(): ?int { return $this->category > 0 ? $this->category : null; }
    public function shouldUploadImages(): bool { return $this->uploadImages; }
    // ... more getters
}
```
### Usage in Pages
```php
// OLD: Multiple $_POST extractions
$locationId = $_POST['location_id'] ?? '0';
$category = (int)($_POST['category'] ?? -1);
// ...
// NEW: Single DTO creation
$exportRequest = ExportRequest::fromPost(
    get_company_pref('curr_default'),
    0
);
// Use type-safe getters
$locationId = $exportRequest->getLocationId();
$categoryId = $exportRequest->getCategoryId(); // Returns null if -1
$uploadImages = $exportRequest->shouldUploadImages(); // bool, not int
```
### Benefits
1. **SRP Compliance**: DTO is responsible for data extraction, page is responsible for orchestration
2. **Type Safety**: Getters return proper types (bool, int, string)
3. **Testability**: Can create DTOs from arrays in tests without superglobals
4. **Centralized Logic**: Default values, type casting, and validation in one place
5. **Cleaner Code**: Pages become more readable with fewer variable assignments
### Template for New DTOs
```php
<?php
declare(strict_types=1);
namespace Your\Namespace\DTO;
class YourRequest
{
    // Define all fields with proper types
    private string $field1;
    private int $field2;
    private bool $field3;
    public function __construct(
        string $field1 = 'default',
        int $field2 = 0,
        bool $field3 = false
    ) {
        $this->field1 = $field1;
        $this->field2 = $field2;
        $this->field3 = $field3;
    }
    public static function fromPost(): self
    {
        return self::fromArray($_POST);
    }
    public static function fromArray(array $data): self
    {
        return new self(
            $data['field1'] ?? 'default',
            isset($data['field2']) ? (int)$data['field2'] : 0,
            isset($data['field3']) ? (bool)$data['field3'] : false
        );
    }
    // Getters (use descriptive names for booleans: shouldX(), isX(), hasX())
    public function getField1(): string { return $this->field1; }
    public function getField2(): int { return $this->field2; }
    public function isField3(): bool { return $this->field3; }
    // Optional: Validation
    public function validate(): array
    {
        $errors = [];
        if ($this->field2 < 0) {
            $errors[] = 'field2 must be non-negative';
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
```

--- Update 2026-09-26 ---
- Customer interface renamed: syncCustomerFromFAToSquare / syncCustomerFromSquareToFA.
- syncCustomerFromSquareToFA now stages through Import Staging (StagingCustomerDAO) instead of direct debtor insert/update, supporting review/matching before final debtor creation (coordinates with ksf_FA_ImportStagingProcessing).
- PHPUnit: 309 tests pass; 2 skipped (GD extension missing for CatalogExporter image tests).

## Hook-dispatch audit (2026-10, cross-module sweep)

Square's 11 `hook_invoke(self::HOOK_MODULE, ...)` calls were converted to
capability dispatch (`hook_invoke_first`, `hook_invoke_all` fallback) in commit
`e892a03`. `HOOK_MODULE = 'ksf_FA_ImportStagingProcessing'` is gone;
`NoHardcodedStagerTest` now fails the build if any production file names a
stager. Use that test as the template for the other source systems.

### Known gap: eight `stage_*` broadcasts have no responder anywhere

`GiftCardService`, `InventoryAnalyticsService`, `PaymentService`,
`RefundService`, `SalesOrderService`, `WebhookService` and `DisputesService`
each emit one of these via `hook_invoke_all`, and a sweep of every module in
`~/Documents` (vendor and `fa_modules` excluded) found **no implementation**:

| emitted event | notes |
|---|---|
| `stage_gift_card` | no `StagingGiftCard` DTO in staging-dto |
| `stage_loyalty_program` | `StagingLoyaltyProgram` DTO **does** exist |
| `stage_location_transfer` | no DTO |
| `stage_inventory_adjustment` | `StagingInventory` DTO **does** exist |
| `stage_payment_type` | no DTO |
| `stage_refund` | `StagingRefund` DTO **does** exist |
| `stage_order_lifecycle` | no DTO |
| `stage_webhook_event` | no DTO |
| `log_dispute_crm` | CRM logging, not staging |

These are fire-and-forget broadcasts into the void, so Square believes it staged
data that nothing received. Note the snake_case `stage_*` naming versus the
working uppercase `STAGE_ENTITY` capability — the working path is the DTO
contract, and these look like an earlier speculative design that was never wired
up. **Do not treat them as working.** Building them out means choosing which
subset ISU should own and adding responders; that is an open decision, not a
mechanical fix.

By contrast `order_imported` (`ImportService.php:566`) is a correct broadcast:
`ksf_FA_HRM` and `ksf_FA_ProjectManagement` both implement it and accumulate
into the by-reference payload (`$data['commissions_created']`), which is exactly
what `hook_invoke_all` is for.
