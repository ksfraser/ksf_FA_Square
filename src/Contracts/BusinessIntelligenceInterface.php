<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

/**
 * Business Intelligence Interface
 * 
 * Defines the contract for business intelligence services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.01 - Sales Analytics, FR-08.02 - Customer Analytics
 */
interface BusinessIntelligenceInterface
{
    /**
     * Gets sales analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Sales analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getSalesAnalytics(array $filters): array;

    /**
     * Gets customer analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Customer analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getCustomerAnalytics(array $filters): array;

    /**
     * Gets inventory analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Inventory analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getInventoryAnalytics(array $filters): array;

    /**
     * Gets financial analytics data.
     * 
     * @param array $filters Filter parameters
     * @return array Financial analytics data
     * @throws AnalyticsException on processing failure
     */
    public function getFinancialAnalytics(array $filters): array;

    /**
     * Gets performance metrics.
     * 
     * @param array $filters Filter parameters
     * @return array Performance metrics
     * @throws AnalyticsException on processing failure
     */
    public function getPerformanceMetrics(array $filters): array;

    /**
     * Generates custom report.
     * 
     * @param array $reportData Report data
     * @return array Report results
     * @throws ReportGenerationException on generation failure
     */
    public function generateCustomReport(array $reportData): array;
}