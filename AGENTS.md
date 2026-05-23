# AGENTS.md - Development Patterns and Guidelines

## Architecture Overview

This repository follows a **Layered Architecture** with clear separation of concerns:

### Core Principles
- **SOLID**: Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **DRY**: Don't Repeat Yourself - extract reusable logic
- **TDD**: Test-Driven Development - write tests first
- **DI**: Dependency Injection - inject dependencies, don't hardcode
- **SRP**: Single Responsibility Principle - each class has one reason to change

## Repository Structure

```
repo/
├── src/                    # Business logic (framework-agnostic)
│   ├── Contracts/        # Interfaces
│   ├── Services/         # Business logic services
│   ├── Models/           # Domain models
│   ├── ValueObjects/    # Immutable value objects
│   └── Exceptions/      # Custom exceptions
├── includes/              # Framework-specific integration (FA/WordPress)
├── tests/
│   ├── Unit/             # PHPUnit tests
│   └── Integration/     # Integration tests
├── ProjectDocs/           # Project documentation
│   ├── Requirements.md
│   ├── RTM.md            # Requirements Traceability Matrix
│   ├── BABOK.md         # Business Analysis Body of Knowledge
│   └── UML.md           # UML diagrams
├── sql/                   # Database schemas
├── pages/                 # UI pages (FA-specific)
└── composer.json
```

## Coding Standards

### PHP Compatibility
- **Target**: PHP 7.3+ (with eye to PHP 8.x upgrades)
- Use `declare(strict_types=1)` at top of all PHP files
- Avoid PHP 8+ features until we drop PHP 7.3 support

### Naming Conventions
- **Interfaces**: `InterfaceNameInterface` (e.g., `WalletServiceInterface`)
- **Abstract classes**: `AbstractClassName` (e.g., `AbstractPricingRule`)
- **Services**: `ServiceNameService` (e.g., `CalculateShippingService`)
- **Value Objects**: `ValueObjectName` (e.g., `Money`, `DiscountRate`)

### Documentation
Every class/method MUST have:
```php
/**
 * Short description
 * 
 * Long description with business context
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
```

## Testing Strategy

### TDD Red-Green-Refactor
1. **RED**: Write failing test
2. **GREEN**: Write minimal code to pass
3. **REFACTOR**: Improve code while keeping tests green

### Test Structure
```php
namespace Tests\Unit;

class WalletServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testCanAddFundsToWallet(): void
    {
        // Arrange
        $wallet = new Wallet();
        $amount = new Money(100.00, 'USD');
        
        // Act
        $wallet->addFunds($amount);
        
        // Assert
        $this->assertEquals(100.00, $wallet->getBalance()->getAmount());
    }
}
```

## Design Patterns Used

### Strategy Pattern
- Pricing rules, Shipping calculators use strategy pattern
- Allows swapping algorithms at runtime

### Factory Pattern
- Service creation, complex object creation

### Repository Pattern
- Data access abstraction (DB-agnostic)

### Observer Pattern
- Event-driven architecture for wallet transactions, pricing changes

## Version Tagging

Follow Semantic Versioning (SemVer): `MAJOR.MINOR.PATCH`
- **MAJOR**: Incompatible API changes
- **MINOR**: New functionality (backward compatible)
- **PATCH**: Bug fixes (backward compatible)

```bash
git tag -a v1.0.0 -m "Initial release with wallet functionality"
git push origin v1.0.0
```

## Composer/Packagist

```json
{
    "name": "ksfraser/ksf-wallet-core",
    "description": "Wallet business logic (framework-agnostic)",
    "type": "library",
    "require": {
        "php": ">=7.3",
        "ext-json": "*"
    },
    "autoload": {
        "psr-4": {
            "Ksf\\Wallet\\": "src/"
        }
    }
}
```

## RTM (Requirements Traceability Matrix)

See `ProjectDocs/RTM.md` for full traceability:
- Requirement ID → Test Case ID → Code File → Version

## BABOK Alignment

See `ProjectDocs/BABOK.md` for business analysis alignment:
- Stakeholder needs → Solution approach → Acceptance criteria

## UML Documentation

See `ProjectDocs/UML.md` for:
- Class diagrams
- Sequence diagrams
- Component diagrams

---

## Inter-Module Communication (NEW)

For ksf modules to discover and communicate with each other, we've adopted a standardized pattern using FrontAccounting's built-in `hook_invoke` function.

### The Problem
Previously, modules used fragile methods to detect each other:
- Hardcoded file paths (e.g., `/tmp/ksf_generate/`) that only work in dev
- Assuming constants are always defined

### The Solution
All ksf modules should implement 4 standard methods in their hooks class:
1. `getModuleConstants(&$data, $opts)` - Returns module constants
2. `getModuleCapabilities(&$data, $opts)` - Returns capabilities with descriptions
3. `hasCapability(&$data, $opts)` - Checks for specific capability
4. `respondToCapabilityRequest(&$data, $opts)` - Generic responder

### How to Call Another Module
```php
// Get constants from ksf_generate
$data = [];
$constants = hook_invoke('ksf_generate', 'getModuleConstants', $data);

// Check if ksf_FA_Square has export capability
$data2 = [];
$hasExport = hook_invoke('ksf_FA_Square', 'hasCapability', $data2, ['capability' => 'export']);
```

### Complete Documentation
See `AGENTS_MODULE_COMMUNICATION_ADDENDUM.md` for:
- Complete method signatures and examples
- Multi-layered discovery strategy
- Full hooks class template to copy-paste
- How other modules can adopt this pattern

### Modules Using This Pattern
- `ksf_FA_Square` (this module) - Version 2.4.3+

---

## DTO (Data Transfer Object) Pattern for Form Handling (NEW)

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

namespace Ksfraser\Frontaccounting\SquareUp\DTO;

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
