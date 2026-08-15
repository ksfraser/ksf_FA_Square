<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

use ksfraser\FrontAccounting\Square\Contracts\TaxServiceInterface;

use ksfraser\FrontAccounting\Square\DAO\TaxMappingDAO;
use ksfraser\FrontAccounting\Square\DAO\TaxRatesDAO;
/**
 * Tax Service
 * 
 * Handles tax calculations and mapping between Square and FrontAccounting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.01 - Tax Calculation, FR-06.02 - Tax Mapping
 */
class TaxService implements TaxServiceInterface
{
    private TaxRatesDAO $taxRatesDao;
    private TaxMappingDAO $taxMappingDao;
    private TaxAdapter $taxAdapter;
    private string $tablePrefix;

    public function __construct(
        TaxRatesDAO $taxRatesDao,
        TaxMappingDAO $taxMappingDao,
        TaxAdapter $taxAdapter
    ) {
        $this->taxRatesDao = $taxRatesDao;
        $this->taxMappingDao = $taxMappingDao;
        $this->taxAdapter = $taxAdapter;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Calculates taxes for Square order data.
     * 
     * Wraps calculateSquareTaxes and returns tax summary data.
     * 
     * @param array $orderData Square order data
     * @return array Tax summary
     * @throws TaxCalculationException on calculation failure
     */
    public function calculateTax(array $orderData): array
    {
        $result = $this->calculateSquareTaxes($orderData);

        $totalTax = 0.0;
        foreach (($result['tax_calculations'] ?? []) as $calc) {
            $totalTax += (float)($calc['amount'] ?? 0);
        }

        return [
            'tax_amount' => $totalTax,
            'tax_rate' => $this->getEffectiveTaxRate($result['tax_details'] ?? [])
        ];
    }

    /**
     * Gets the effective tax rate from tax detail data.
     * 
     * @param array $taxDetails Tax detail entries
     * @return float Effective tax rate
     */
    private function getEffectiveTaxRate(array $taxDetails): float
    {
        $rate = 0.0;
        foreach ($taxDetails as $detail) {
            $rate += (float)($detail['percentage'] ?? 0);
        }

        return $rate;
    }

    /**
     * Calculates taxes for Square data.
     * 
     * @param array $squareData Square data with tax information
     * @return array Tax calculation data
     * @throws TaxCalculationException on calculation failure
     */
    public function calculateSquareTaxes(array $squareData): array
    {
        try {
            // Validate Square data
            $this->validateSquareData($squareData);
            
            // Calculate base total
            $baseTotal = $this->calculateBaseTotal($squareData['line_items'] ?? []);
            
            // Calculate taxes
            $taxCalculations = $this->calculateTaxes($squareData['taxes'] ?? [], $baseTotal);
            
            // Determine if tax is included
            $taxIncluded = $this->isTaxIncluded($squareData);
            
            // Calculate final total
            $total = $taxIncluded ? $baseTotal : $baseTotal + array_sum(array_column($taxCalculations, 'amount'));
            
            return [
                'base_total' => $baseTotal,
                'tax_included' => $taxIncluded,
                'total' => $total,
                'tax_calculations' => $taxCalculations,
                'tax_details' => $this->formatTaxDetails($taxCalculations)
            ];
            
        } catch (\Exception $e) {
            throw new TaxCalculationException("Failed to calculate taxes: " . $e->getMessage());
        }
    }

    /**
     * Maps FA tax data to Square format.
     * 
     * @param array $faTaxData FA tax data
     * @return array Square tax data
     * @throws TaxMappingException on mapping failure
     */
    public function mapFATaxToSquare(array $faTaxData): array
    {
        try {
            // Validate FA tax data
            $this->validateFATaxData($faTaxData);
            
            // Get mapping if exists
            $mapping = $this->taxMappingDao->getMappingByFaId($faTaxData['tax_type_id']);
            
            if ($mapping) {
                // Use existing mapping
                return $this->taxAdapter->convertFATaxToSquare($faTaxData, $mapping);
            } else {
                // Create new mapping
                $squareTax = $this->taxAdapter->convertFATaxToSquare($faTaxData, null);
                
                // Create mapping
                $mappingId = $this->createTaxMapping([
                    'fa_tax_id' => $faTaxData['tax_type_id'],
                    'square_tax_id' => $squareTax['tax_rate_id'],
                    'mapping_data' => json_encode($faTaxData),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                $squareTax['mapping_id'] = $mappingId;
                return $squareTax;
            }
            
        } catch (\Exception $e) {
            throw new TaxMappingException("Failed to map FA tax to Square: " . $e->getMessage());
        }
    }

    /**
     * Maps Square tax data to FA format.
     * 
     * @param array $squareTaxData Square tax data
     * @return array FA tax data
     * @throws TaxMappingException on mapping failure
     */
    public function mapSquareTaxToFA(array $squareTaxData): array
    {
        try {
            // Validate Square tax data
            $this->validateSquareTaxData($squareTaxData);
            
            // Get mapping if exists
            $mapping = $this->taxMappingDao->getMappingBySquareId($squareTaxData['tax_rate_id']);
            
            if ($mapping) {
                // Use existing mapping
                return $this->taxAdapter->convertSquareTaxToFA($squareTaxData, $mapping);
            } else {
                // Find closest FA tax rate
                $faTax = $this->findClosestFATaxRate($squareTaxData);
                
                if ($faTax) {
                    // Create mapping
                    $mappingId = $this->createTaxMapping([
                        'fa_tax_id' => $faTax['tax_type_id'],
                        'square_tax_id' => $squareTaxData['tax_rate_id'],
                        'mapping_data' => json_encode($squareTaxData),
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $faTax['mapping_id'] = $mappingId;
                    return $faTax;
                } else {
                    // Create new FA tax rate
                    $faTax = $this->createNewFATaxRate($squareTaxData);
                    
                    // Create mapping
                    $mappingId = $this->createTaxMapping([
                        'fa_tax_id' => $faTax['tax_type_id'],
                        'square_tax_id' => $squareTaxData['tax_rate_id'],
                        'mapping_data' => json_encode($squareTaxData),
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    $faTax['mapping_id'] = $mappingId;
                    return $faTax;
                }
            }
            
        } catch (\Exception $e) {
            throw new TaxMappingException("Failed to map Square tax to FA: " . $e->getMessage());
        }
    }

    /**
     * Gets tax rate by Square tax ID.
     * 
     * @param string $squareTaxId Square tax ID
     * @return array|null Tax rate data or null if not found
     */
    public function getTaxRateBySquareId(string $squareTaxId): ?array
    {
        $mapping = $this->taxMappingDao->getMappingBySquareId($squareTaxId);
        if ($mapping) {
            return $this->taxRatesDao->getTaxRateById($mapping['fa_tax_id']);
        }
        return null;
    }

    /**
     * Gets tax rate by FA tax type ID.
     * 
     * @param int $faTaxTypeId FA tax type ID
     * @return array|null Tax rate data or null if not found
     */
    public function getTaxRateByFaId(int $faTaxTypeId): ?array
    {
        return $this->taxRatesDao->getTaxRateById($faTaxTypeId);
    }

    /**
     * Creates tax rate mapping.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     * @throws TaxMappingException on creation failure
     */
    public function createTaxMapping(array $mappingData): int
    {
        try {
            // Validate mapping data
            $this->validateMappingData($mappingData);
            
            return $this->taxMappingDao->insertMapping($mappingData);
            
        } catch (\Exception $e) {
            throw new TaxMappingException("Failed to create tax mapping: " . $e->getMessage());
        }
    }

    /**
     * Gets tax calculation statistics.
     * 
     * @return array Statistics array
     */
    public function getTaxStatistics(): array
    {
        // Total tax calculations
        $totalSql = "SELECT COUNT(*) as total FROM {$this->getTaxCalculationsTableName()}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Tax calculations by type
        $typeSql = "SELECT type, COUNT(*) as count FROM {$this->getTaxCalculationsTableName()} 
                   GROUP BY type ORDER BY count DESC";
        $typeResult = \db_query($typeSql);
        $byType = [];
        if ($typeResult !== false) {
            while ($row = \db_fetch_assoc($typeResult)) {
                if ($row !== false) {
                    $byType[$row['type']] = (int)$row['count'];
                }
            }
        }
        
        // Recent tax calculations
        $recentSql = "SELECT COUNT(*) as recent FROM {$this->getTaxCalculationsTableName()} 
                     WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        // Mapping statistics
        $mappingSql = "SELECT COUNT(*) as mappings FROM {$this->getTaxMappingsTableName()}";
        $mappingResult = \db_query($mappingSql);
        $mappings = 0;
        if ($mappingResult !== false) {
            $row = \db_fetch_assoc($mappingResult);
            $mappings = (int)($row['mappings'] ?? 0);
        }
        
        return [
            'total_calculations' => $total,
            'by_type' => $byType,
            'recent_calculations' => $recent,
            'total_mappings' => $mappings,
        ];
    }

    /**
     * Validates Square data.
     * 
     * @param array $squareData Square data
     * @throws TaxCalculationException on validation failure
     */
    private function validateSquareData(array $squareData): void
    {
        if (empty($squareData)) {
            throw new TaxCalculationException("Square data is required");
        }
        
        if (!isset($squareData['line_items']) || !is_array($squareData['line_items'])) {
            throw new TaxCalculationException("Line items are required");
        }
    }

    /**
     * Validates FA tax data.
     * 
     * @param array $faTaxData FA tax data
     * @throws TaxMappingException on validation failure
     */
    private function validateFATaxData(array $faTaxData): void
    {
        if (empty($faTaxData)) {
            throw new TaxMappingException("FA tax data is required");
        }
        
        if (!isset($faTaxData['tax_type_id']) || !is_numeric($faTaxData['tax_type_id'])) {
            throw new TaxMappingException("FA tax type ID is required");
        }
        
        if (!isset($faTaxData['rate']) || !is_numeric($faTaxData['rate'])) {
            throw new TaxMappingException("Tax rate is required");
        }
    }

    /**
     * Validates Square tax data.
     * 
     * @param array $squareTaxData Square tax data
     * @throws TaxMappingException on validation failure
     */
    private function validateSquareTaxData(array $squareTaxData): void
    {
        if (empty($squareTaxData)) {
            throw new TaxMappingException("Square tax data is required");
        }
        
        if (empty($squareTaxData['tax_rate_id'])) {
            throw new TaxMappingException("Square tax rate ID is required");
        }
        
        if (!isset($squareTaxData['percentage']) && !isset($squareTaxData['amount'])) {
            throw new TaxMappingException("Either percentage or amount is required");
        }
    }

    /**
     * Validates mapping data.
     * 
     * @param array $mappingData Mapping data
     * @throws TaxMappingException on validation failure
     */
    private function validateMappingData(array $mappingData): void
    {
        if (empty($mappingData)) {
            throw new TaxMappingException("Mapping data is required");
        }
        
        if (!isset($mappingData['fa_tax_id']) || !is_numeric($mappingData['fa_tax_id'])) {
            throw new TaxMappingException("FA tax ID is required");
        }
        
        if (empty($mappingData['square_tax_id'])) {
            throw new TaxMappingException("Square tax ID is required");
        }
    }

    /**
     * Calculates base total from line items.
     * 
     * @param array $lineItems Line items
     * @return float Base total
     */
    private function calculateBaseTotal(array $lineItems): float
    {
        $total = 0;
        
        foreach ($lineItems as $item) {
            if (isset($item['base_price_money']['amount'])) {
                $total += $item['base_price_money']['amount'] / 100; // Convert cents to decimal
            }
        }
        
        return $total;
    }

    /**
     * Calculates taxes.
     * 
     * @param array $taxes Tax data
     * @param float $baseTotal Base total
     * @return array Tax calculations
     */
    private function calculateTaxes(array $taxes, float $baseTotal): array
    {
        $calculations = [];
        
        foreach ($taxes as $tax) {
            if (isset($tax['tax_rate_id']) && isset($tax['applied_money']['amount'])) {
                $calculations[] = [
                    'tax_rate_id' => $tax['tax_rate_id'],
                    'amount' => $tax['applied_money']['amount'] / 100, // Convert cents to decimal
                    'percentage' => $this->getTaxPercentage($tax, $baseTotal),
                    'type' => 'square'
                ];
            }
        }
        
        return $calculations;
    }

    /**
     * Gets tax percentage.
     * 
     * @param array $tax Tax data
     * @param float $baseTotal Base total
     * @return float Tax percentage
     */
    private function getTaxPercentage(array $tax, float $baseTotal): float
    {
        if (isset($tax['percentage'])) {
            return (float)$tax['percentage'];
        }
        
        if (isset($tax['applied_money']['amount']) && $baseTotal > 0) {
            return ($tax['applied_money']['amount'] / 100) / $baseTotal * 100;
        }
        
        return 0;
    }

    /**
     * Determines if tax is included.
     * 
     * @param array $squareData Square data
     * @return bool Tax included status
     */
    private function isTaxIncluded(array $squareData): bool
    {
        return $squareData['tax_included'] ?? false;
    }

    /**
     * Formats tax details.
     * 
     * @param array $taxCalculations Tax calculations
     * @return array Formatted tax details
     */
    private function formatTaxDetails(array $taxCalculations): array
    {
        $details = [];
        
        foreach ($taxCalculations as $calc) {
            $details[] = [
                'tax_rate_id' => $calc['tax_rate_id'],
                'amount' => $calc['amount'],
                'percentage' => $calc['percentage'],
                'type' => $calc['type']
            ];
        }
        
        return $details;
    }

    /**
     * Finds closest FA tax rate.
     * 
     * @param array $squareTax Square tax data
     * @return array|null FA tax rate or null if not found
     */
    private function findClosestFATaxRate(array $squareTax): ?array
    {
        $squarePercentage = isset($squareTax['percentage']) ? (float)$squareTax['percentage'] : 0;
        
        // Get all FA tax rates
        $faTaxRates = $this->taxRatesDao->getAllTaxRates();
        
        // Find closest match
        $closestMatch = null;
        $smallestDifference = PHP_FLOAT_MAX;
        
        foreach ($faTaxRates as $taxRate) {
            $difference = abs($taxRate['rate'] - $squarePercentage);
            
            if ($difference < $smallestDifference && $difference <= 0.1) { // Within 0.1%
                $smallestDifference = $difference;
                $closestMatch = $taxRate;
            }
        }
        
        return $closestMatch;
    }

    /**
     * Creates new FA tax rate.
     * 
     * @param array $squareTax Square tax data
     * @return array New FA tax rate
     */
    private function createNewFATaxRate(array $squareTax): array
    {
        $taxData = [
            'name' => $squareTax['name'] ?? 'Square Tax',
            'rate' => isset($squareTax['percentage']) ? (float)$squareTax['percentage'] : 0,
            'tax_type_name' => $squareTax['name'] ?? 'Square Tax Rate',
            'tax_type_code' => 'SQ_' . $squareTax['tax_rate_id'],
            'inactive' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $taxId = $this->taxRatesDao->insertTaxRate($taxData);
        
        return array_merge($taxData, ['tax_type_id' => $taxId]);
    }

    /**
     * Gets tax calculations table name.
     * 
     * @return string Table name
     */
    private function getTaxCalculationsTableName(): string
    {
        return $this->tablePrefix . 'tax_calculations';
    }

    /**
     * Gets tax mappings table name.
     * 
     * @return string Table name
     */
    private function getTaxMappingsTableName(): string
    {
        return $this->tablePrefix . 'tax_mappings';
    }
}