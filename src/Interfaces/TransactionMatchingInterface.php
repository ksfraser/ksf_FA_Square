<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Interfaces;

/**
 * Transaction Matching Interface
 * 
 * Defines the contract for transaction matching services.
 * 
 * @UML Note: Interface in ProjectDocs/UML.md
 */
interface TransactionMatchingInterface
{
    /**
     * Matches transactions.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransactions FA transactions data
     * @return array Match results
     */
    public function matchTransactions(array $squareTransaction, array $faTransactions): array;

    /**
     * Processes match approval.
     * 
     * @param string $matchId Match ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveMatch(string $matchId, string $approverId, array $approvalData = []): array;

    /**
     * Processes match rejection.
     * 
     * @param string $matchId Match ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectMatch(string $matchId, string $rejecterId, string $reason, array $rejectionData = []): array;

    /**
     * Gets matching queue.
     * 
     * @param array $filters Filter parameters
     * @return array Matching queue
     */
    public function getMatchingQueue(array $filters = []): array;

    /**
     * Gets matching history.
     * 
     * @param array $filters Filter parameters
     * @return array Matching history
     */
    public function getMatchingHistory(array $filters = []): array;

    /**
     * Gets match statistics.
     * 
     * @return array Match statistics
     */
    public function getMatchingStatistics(): array;

    /**
     * Generates match report.
     * 
     * @param array $filters Filter parameters
     * @return array Match report
     */
    public function generateMatchReport(array $filters = []): array;

    /**
     * Updates match rules.
     * 
     * @param array $rules Match rules
     * @return array Update results
     */
    public function updateMatchRules(array $rules): array;

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
     * Clears matching queue.
     */
    public function clearMatchingQueue(): void;

    /**
     * Clears matching history.
     */
    public function clearMatchingHistory(): void;
}