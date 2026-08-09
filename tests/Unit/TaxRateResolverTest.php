<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Services\TaxRateResolver;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: FR-06.01 - Tax Calculation
 */
class TaxRateResolverTest extends TestCase
{
    public function testExemptItemReturnsZeroRate(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return 5.0;
        });

        $this->assertSame(0.0, $resolver->resolveForItem(true, 1));
    }

    public function testNoConfiguredTaxGroupReturnsZeroRate(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return 5.0;
        });

        $this->assertSame(0.0, $resolver->resolveForItem(false, null));
    }

    public function testInvalidTaxGroupReturnsZeroRate(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return 5.0;
        });

        $this->assertSame(0.0, $resolver->resolveForItem(false, 0));
    }

    public function testUsesProvidedRateProvider(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return 13.0;
        });

        $this->assertSame(13.0, $resolver->resolveForItem(false, 1));
    }

    public function testRateProviderNullResultFallsBackToZero(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return null;
        });

        $this->assertSame(0.0, $resolver->resolveForItem(false, 1));
    }

    public function testWithoutProviderAndWithoutFAReturnsZero(): void
    {
        $resolver = new TaxRateResolver();

        $this->assertSame(0.0, $resolver->resolveForItem(false, 1));
    }

    public function testDoesNotGuessWhenFAResolverReturnsNoRates(): void
    {
        $resolver = new TaxRateResolver(function (?int $groupId): ?float {
            return null;
        });

        $this->assertSame(0.0, $resolver->resolveForItem(false, 7));
    }
}
