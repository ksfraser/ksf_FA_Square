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
