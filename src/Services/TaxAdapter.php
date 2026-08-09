<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Tax Adapter
 * 
 * Handles conversion between Square taxes and FA tax rates.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.02 - Tax Mapping
 */
class TaxAdapter
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Converts FA tax to Square tax format.
     * 
     * @param array $faTax FA tax data
     * @param array|null $mapping Mapping data
     * @return array Square tax data
     */
    public function convertFATaxToSquare(array $faTax, ?array $mapping): array
    {
        $squareTax = [
            'tax_rate_id' => $mapping['square_tax_id'] ?? 'tax_' . $faTax['tax_type_id'],
            'name' => $faTax['tax_type_name'] ?? $faTax['name'] ?? 'FA Tax',
            'percentage' => round($faTax['rate'], 2),
            'inclusive' => false,
            'active' => $faTax['inactive'] ? false : true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($mapping) {
            $squareTax['mapping_id'] = $mapping['id'];
        }

        return $squareTax;
    }

    /**
     * Converts a Square tax to FA tax format without mapping.
     * 
     * @param array $squareTax Square tax data
     * @return array FA tax data
     */
    public function convertToFATax(array $squareTax): array
    {
        return $this->convertSquareTaxToFA($squareTax, null);
    }

    /**
     * Converts Square tax to FA tax format.
     * 
     * @param array $squareTax Square tax data
     * @param array|null $mapping Mapping data
     * @return array FA tax data
     */
    public function convertSquareTaxToFA(array $squareTax, ?array $mapping): array
    {
        $faTax = [
            'tax_type_id' => $mapping['fa_tax_id'] ?? 0,
            'tax_type_name' => $squareTax['name'] ?? 'Square Tax',
            'tax_type_code' => 'SQ_' . $squareTax['tax_rate_id'],
            'rate' => isset($squareTax['percentage']) ? (float)$squareTax['percentage'] : 0,
            'inactive' => $squareTax['active'] ? 0 : 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($mapping) {
            $faTax['mapping_id'] = $mapping['id'];
        }

        return $faTax;
    }

    /**
     * Converts line items to Square tax calculations.
     * 
     * @param array $lineItems Line items
     * @param array $taxes Taxes
     * @return array Square tax calculations
     */
    public function convertLineItemsToSquareTaxes(array $lineItems, array $taxes): array
    {
        $squareTaxes = [];

        foreach ($lineItems as $item) {
            // Calculate tax for this item
            $itemTotal = $item['base_price_money']['amount'] / 100;
            
            foreach ($taxes as $tax) {
                $taxAmount = $itemTotal * ($tax['percentage'] / 100);
                
                $squareTaxes[] = [
                    'line_item_id' => $item['item_id'],
                    'tax_rate_id' => $tax['tax_rate_id'],
                    'applied_money' => [
                        'amount' => round($taxAmount * 100), // Convert to cents
                        'currency' => 'USD'
                    ]
                ];
            }
        }

        return $squareTaxes;
    }

    /**
     * Converts Square taxes to FA tax calculations.
     * 
     * @param array $squareTaxes Square taxes
     * @param array $lineItems Line items
     * @return array FA tax calculations
     */
    public function convertSquareTaxesToFATaxCalculations(array $squareTaxes, array $lineItems): array
    {
        $faCalculations = [];

        foreach ($squareTaxes as $tax) {
            // Find corresponding line item
            $lineItem = null;
            foreach ($lineItems as $item) {
                if ($item['item_id'] === $tax['line_item_id']) {
                    $lineItem = $item;
                    break;
                }
            }

            if ($lineItem) {
                $faCalculations[] = [
                    'item_code' => $lineItem['item_code'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['base_price_money']['amount'] / 100,
                    'tax_amount' => $tax['applied_money']['amount'] / 100,
                    'tax_rate_id' => $tax['tax_rate_id'],
                    'tax_percentage' => $this->getTaxPercentage($tax, $lineItem)
                ];
            }
        }

        return $faCalculations;
    }

    /**
     * Gets tax percentage for a line item.
     * 
     * @param array $tax Tax data
     * @param array $lineItem Line item data
     * @return float Tax percentage
     */
    private function getTaxPercentage(array $tax, array $lineItem): float
    {
        if (isset($tax['percentage'])) {
            return (float)$tax['percentage'];
        }

        $lineItemTotal = $lineItem['base_price_money']['amount'] / 100;
        if ($lineItemTotal > 0) {
            return ($tax['applied_money']['amount'] / 100) / $lineItemTotal * 100;
        }

        return 0;
    }

    /**
     * Formats tax summary for display.
     * 
     * @param array $taxCalculations Tax calculations
     * @return array Formatted tax summary
     */
    public function formatTaxSummary(array $taxCalculations): array
    {
        $summary = [];

        foreach ($taxCalculations as $calc) {
            $taxRateId = $calc['tax_rate_id'];
            
            if (!isset($summary[$taxRateId])) {
                $summary[$taxRateId] = [
                    'tax_rate_id' => $taxRateId,
                    'total_amount' => 0,
                    'percentage' => isset($calc['tax_percentage']) ? $calc['tax_percentage'] : 0,
                    'items' => []
                ];
            }

            $summary[$taxRateId]['total_amount'] += $calc['tax_amount'];
            
            if (isset($calc['item_code'])) {
                $summary[$taxRateId]['items'][] = [
                    'item_code' => $calc['item_code'],
                    'quantity' => $calc['quantity'],
                    'tax_amount' => $calc['tax_amount']
                ];
            }
        }

        return array_values($summary);
    }

    /**
     * Validates tax calculation.
     * 
     * @param array $taxCalculation Tax calculation
     * @return bool Validation result
     */
    public function validateTaxCalculation(array $taxCalculation): bool
    {
        $requiredFields = ['tax_rate_id', 'amount'];
        
        foreach ($requiredFields as $field) {
            if (!isset($taxCalculation[$field])) {
                return false;
            }
        }

        if (!is_numeric($taxCalculation['amount']) || $taxCalculation['amount'] < 0) {
            return false;
        }

        return true;
    }

    /**
     * Calculates tax for a given amount.
     * 
     * @param float $amount Amount
     * @param float $rate Tax rate
     * @return float Tax amount
     */
    public function calculateTax(float $amount, float $rate): float
    {
        return round($amount * ($rate / 100), 2);
    }

    /**
     * Calculates inclusive tax for a given amount.
     * 
     * @param float $inclusiveAmount Inclusive amount
     * @param float $rate Tax rate
     * @return float Tax amount
     */
    public function calculateInclusiveTax(float $inclusiveAmount, float $rate): float
    {
        $exclusiveAmount = $inclusiveAmount / (1 + ($rate / 100));
        return round($inclusiveAmount - $exclusiveAmount, 2);
    }
}