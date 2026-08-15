<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\ValueObjects;

/**
 * Value Object for handling Square price formatting and validation.
 * 
 * Square API requires prices in cents (integers), with a maximum of 99999999 cents ($999,999.99).
 * This class handles:
 * - Converting float prices to integer cents
 * - Sentinel values for prices <= 0
 * - Capping prices at maximum allowed value
 * - Providing warning messages for UI display
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SquarePrice
{
    public const MAX_PRICE_CENTS = 99999999;
    public const MAX_PRICE_DOLLARS = 999999.99;
    public const SENTINEL_PRICE_CENTS = self::MAX_PRICE_CENTS;
    public const SENTINEL_PRICE_DOLLARS = self::MAX_PRICE_DOLLARS;

    /**
     * @var int
     */
    private $priceCents;

    /**
     * @var float
     */
    private $originalPrice;

    /**
     * @var bool
     */
    private $wasNegative;

    /**
     * @var bool
     */
    private $wasCapped;

    private function __construct(
        int $priceCents,
        float $originalPrice,
        bool $wasNegative,
        bool $wasCapped
    ) {
        $this->priceCents = $priceCents;
        $this->originalPrice = $originalPrice;
        $this->wasNegative = $wasNegative;
        $this->wasCapped = $wasCapped;
    }

    /**
     * Creates a SquarePrice from a float price (in dollars).
     * 
     * @param float $price Price in dollars (e.g., 19.99)
     * @return self
     */
    public static function fromDollars(float $price): self
    {
        $originalPrice = $price;
        $wasNegative = false;
        $wasCapped = false;

        // Handle prices <= 0 with sentinel value
        if ($price <= 0) {
            $wasNegative = true;
            return new self(
                self::SENTINEL_PRICE_CENTS,
                $originalPrice,
                $wasNegative,
                $wasCapped
            );
        }

        // Convert to cents
        $priceCents = (int)round(100 * $price);

        // Cap at maximum
        if ($priceCents > self::MAX_PRICE_CENTS) {
            $wasCapped = true;
            $priceCents = self::MAX_PRICE_CENTS;
        }

        return new self(
            $priceCents,
            $originalPrice,
            $wasNegative,
            $wasCapped
        );
    }

    /**
     * Creates a SquarePrice from an integer price (in cents).
     * 
     * @param int $priceCents Price in cents (e.g., 1999)
     * @return self
     */
    public static function fromCents(int $priceCents): self
    {
        $originalPrice = $priceCents / 100.0;
        $wasNegative = false;
        $wasCapped = false;

        if ($priceCents <= 0) {
            $wasNegative = true;
            return new self(
                self::SENTINEL_PRICE_CENTS,
                $originalPrice,
                $wasNegative,
                $wasCapped
            );
        }

        if ($priceCents > self::MAX_PRICE_CENTS) {
            $wasCapped = true;
            $priceCents = self::MAX_PRICE_CENTS;
        }

        return new self(
            $priceCents,
            $originalPrice,
            $wasNegative,
            $wasCapped
        );
    }

    /**
     * Gets the price in cents (for Square API).
     * 
     * @return int
     */
    public function getCents(): int
    {
        return $this->priceCents;
    }

    /**
     * Gets the price in dollars.
     * 
     * @return float
     */
    public function getDollars(): float
    {
        return $this->priceCents / 100.0;
    }

    /**
     * Gets the original price before any transformations.
     * 
     * @return float
     */
    public function getOriginalPrice(): float
    {
        return $this->originalPrice;
    }

    /**
     * Checks if the original price was <= 0 (sentinel value used).
     * 
     * @return bool
     */
    public function wasNegative(): bool
    {
        return $this->wasNegative;
    }

    /**
     * Checks if the price was capped at the maximum.
     * 
     * @return bool
     */
    public function wasCapped(): bool
    {
        return $this->wasCapped;
    }

    /**
     * Checks if any transformation was applied.
     * 
     * @return bool
     */
    public function wasTransformed(): bool
    {
        return $this->wasNegative || $this->wasCapped;
    }

    /**
     * Gets a warning message for UI display (if any transformation was applied).
     * 
     * @param string $itemId Optional item ID for context
     * @return string|null Warning message or null if no transformation
     */
    public function getWarningMessage(string $itemId = ''): ?string
    {
        if ($this->wasNegative) {
            if ($itemId !== '') {
                return sprintf(
                    _("No price for %s — set to $999,999.99 (sentinel)"),
                    $itemId
                );
            }
            return _("No price set — using $999,999.99 (sentinel)");
        }

        if ($this->wasCapped) {
            if ($itemId !== '') {
                return sprintf(
                    _("Price capped for %s at $999,999.99"),
                    $itemId
                );
            }
            return _("Price capped at $999,999.99");
        }

        return null;
    }

    /**
     * Gets a log message for debugging.
     * 
     * @return string
     */
    public function getLogMessage(): string
    {
        if ($this->wasNegative) {
            return sprintf(
                "Price [%.2f] -> sentinel [%d cents] (no price set)",
                $this->originalPrice,
                $this->priceCents
            );
        }

        if ($this->wasCapped) {
            return sprintf(
                "Price [%.2f] -> capped [%d cents] (exceeded max)",
                $this->originalPrice,
                $this->priceCents
            );
        }

        return sprintf(
            "Price [%.2f] -> [%d cents]",
            $this->originalPrice,
            $this->priceCents
        );
    }

    /**
     * Gets the sentinel price in cents.
     * 
     * @return int
     */
    public static function getSentinelCents(): int
    {
        return self::SENTINEL_PRICE_CENTS;
    }

    /**
     * Gets the maximum allowed price in cents.
     * 
     * @return int
     */
    public static function getMaxCents(): int
    {
        return self::MAX_PRICE_CENTS;
    }

    /**
     * Convenience method to get cents from a dollar price.
     * 
     * @param float $price Price in dollars
     * @return int Price in cents
     */
    public static function toCents(float $price): int
    {
        return self::fromDollars($price)->getCents();
    }

    /**
     * Convenience method to validate and get cents with warnings.
     * 
     * @param float $price Price in dollars
     * @param string $itemId Optional item ID for warnings
     * @return array Array with 'cents' and 'warning' (null if none)
     */
    public static function validate(float $price, string $itemId = ''): array
    {
        $squarePrice = self::fromDollars($price);
        return [
            'cents' => $squarePrice->getCents(),
            'dollars' => $squarePrice->getDollars(),
            'original' => $squarePrice->getOriginalPrice(),
            'was_negative' => $squarePrice->wasNegative(),
            'was_capped' => $squarePrice->wasCapped(),
            'was_transformed' => $squarePrice->wasTransformed(),
            'warning' => $squarePrice->getWarningMessage($itemId),
            'log_message' => $squarePrice->getLogMessage(),
        ];
    }
}
