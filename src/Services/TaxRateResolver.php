<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Tax Rate Resolver
 *
 * Resolves the effective FA tax percentage to attach to a Square catalog
 * tax for a stock item. FA stores item exemptions on 0_item_tax_types
 * (which has no rate column); the real percentages live on 0_tax_types and
 * are summed per tax group via 0_tax_group_items + 0_tax_groups.
 *
 * The configured default tax group is used because FA applies tax per
 * customer-branch tax group at transaction time, which is not available at
 * item export time. Without an explicit, valid tax group the resolver
 * returns 0.0 rather than guessing at FA's MIN(id) fallback group.
 *
 * A rate provider callable can be injected for testability and to allow a
 * future location-aware provider (province-level rates) to swap in without
 * changing consumers.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.01 - Tax Calculation
 * @since 2.4.4
 */
class TaxRateResolver
{
    /** @var callable|null */
    private $rateProvider;

    /**
     * @param callable|null $rateProvider Function (?int $taxGroupId): ?float
     *        returning the combined tax rate for a group. When null, the FA
     *        get_tax_group_rates() helper is used if available.
     *
     * @since 2.4.4
     */
    public function __construct(?callable $rateProvider = null)
    {
        $this->rateProvider = $rateProvider;
    }

    /**
     * Resolve the tax percentage to push to Square for a stock item.
     *
     * @param bool  $exempt     True when the item's tax type is exempt
     * @param int|null $taxGroupId Configured default FA tax group id
     *
     * @return float Combined tax percentage (0.0 when exempt or unconfigured)
     *
     * @since 2.4.4
     */
    public function resolveForItem(bool $exempt, ?int $taxGroupId): float
    {
        if ($exempt || $taxGroupId === null || $taxGroupId <= 0) {
            return 0.0;
        }
        return $this->resolveGroupRate($taxGroupId);
    }

    /**
     * Resolve the combined rate for a tax group.
     *
     * @param int $taxGroupId Tax group id
     * @return float Combined tax percentage
     *
     * @since 2.4.4
     */
    private function resolveGroupRate(int $taxGroupId): float
    {
        if ($this->rateProvider !== null) {
            $rate = call_user_func($this->rateProvider, $taxGroupId);
            return is_numeric($rate) ? (float) $rate : 0.0;
        }

        if (!function_exists('get_tax_group_rates')) {
            return 0.0;
        }

        $total = 0.0;
        $result = \get_tax_group_rates($taxGroupId);
        if ($result !== false) {
            while ($row = \db_fetch($result)) {
                if (isset($row['rate']) && is_numeric($row['rate'])) {
                    $total += (float) $row['rate'];
                }
            }
        }
        return $total;
    }
}
