<?php
declare(strict_types=1);

/**
 * Transaction History Interface
 * 
 * Defines the contract for transaction history services.
 * 
 * @UML Note: Interface in ProjectDocs/UML.md
 */
interface TransactionHistoryInterface
{
    /**
     * Records transaction history.
     * 
     * @param array $transactionData Transaction data
     * @return array Record result
     */
    public function recordTransactionHistory(array $transactionData): array;

    /**
     * Detects import gaps.
     * 
     * @param array $importData Import data
     * @return array Gap detection results
     */
    public function detectImportGaps(array $importData): array;

    /**
     * Records import history.
     * 
     * @param array $importData Import data
     * @return array Record result
     */
    public function recordImportHistory(array $importData): array;

    /**
     * Gets transaction history by date range.
     * 
     * @param array $dateRange Date range
     * @param array $filters Filter parameters
     * @return array Transaction history
     */
    public function getTransactionHistoryByDateRange(array $dateRange, array $filters = []): array;

    /**
     * Gets import gaps.
     * 
     * @param array $filters Filter parameters
     * @return array Import gaps
     */
    public function getImportGaps(array $filters = []): array;

    /**
     * Gets import history.
     * 
     * @param array $filters Filter parameters
     * @return array Import history
     */
    public function getImportHistory(array $filters = []): array;

    /**
     * Updates gap status.
     * 
     * @param string $gapId Gap ID
     * @param string $status New status
     * @param array $updateData Update data
     * @return array Update result
     */
    public function updateGapStatus(string $gapId, string $status, array $updateData = []): array;

    /**
     * Generates history report.
     * 
     * @param array $filters Filter parameters
     * @return array History report
     */
    public function generateHistoryReport(array $filters = []): array;

    /**
     * Gets history statistics.
     * 
     * @return array History statistics
     */
    public function getHistoryStatistics(): array;

    /**
     * Gets configuration.
     * 
     * @return array Configuration
     */
    public function getConfig(): array;

    /**
     * Sets configuration.
     * 
     * @param array $config Configuration to set
     */
    public function setConfig(array $config): void;

    /**
     * Clears transaction history.
     */
    public function clearTransactionHistory(): void;

    /**
     * Clears import history.
     */
    public function clearImportHistory(): void;

    /**
     * Clears gap detection.
     */
    public function clearGapDetection(): void;
}