<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

/**
 * Tax Service Interface
 * 
 * Defines the contract for tax management services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.01 - Tax Calculation, FR-06.02 - Tax Mapping
 */
interface TaxServiceInterface
{
    /**
     * Calculates taxes for Square data.
     * 
     * @param array $squareData Square data with tax information
     * @return array Tax calculation data
     * @throws TaxCalculationException on calculation failure
     */
    public function calculateSquareTaxes(array $squareData): array;

    /**
     * Maps FA tax data to Square format.
     * 
     * @param array $faTaxData FA tax data
     * @return array Square tax data
     * @throws TaxMappingException on mapping failure
     */
    public function mapFATaxToSquare(array $faTaxData): array;

    /**
     * Maps Square tax data to FA format.
     * 
     * @param array $squareTaxData Square tax data
     * @return array FA tax data
     * @throws TaxMappingException on mapping failure
     */
    public function mapSquareTaxToFA(array $squareTaxData): array;

    /**
     * Gets tax rate by Square tax ID.
     * 
     * @param string $squareTaxId Square tax ID
     * @return array|null Tax rate data or null if not found
     */
    public function getTaxRateBySquareId(string $squareTaxId): ?array;

    /**
     * Gets tax rate by FA tax type ID.
     * 
     * @param int $faTaxTypeId FA tax type ID
     * @return array|null Tax rate data or null if not found
     */
    public function getTaxRateByFaId(int $faTaxTypeId): ?array;

    /**
     * Creates tax rate mapping.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     * @throws TaxMappingException on creation failure
     */
    public function createTaxMapping(array $mappingData): int;

    /**
     * Gets tax calculation statistics.
     * 
     * @return array Statistics array
     */
    public function getTaxStatistics(): array;
}