<?php
declare(strict_types=1);

/**
 * Transaction Review Interface
 * 
 * Defines the contract for transaction review services.
 * 
 * @UML Note: Interface in ProjectDocs/UML.md
 */
interface TransactionReviewInterface
{
    /**
     * Reviews a transaction.
     * 
     * @param array $reviewData Review data
     * @return array Review results
     */
    public function reviewTransaction(array $reviewData): array;

    /**
     * Approves a transaction review.
     * 
     * @param string $reviewId Review ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveReview(string $reviewId, string $approverId, array $approvalData = []): array;

    /**
     * Rejects a transaction review.
     * 
     * @param string $reviewId Review ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectReview(string $reviewId, string $rejecterId, string $reason, array $rejectionData = []): array;

    /**
     * Matches transactions.
     * 
     * @param array $matchData Match data
     * @return array Match results
     */
    public function matchTransactions(array $matchData): array;

    /**
     * Approves a transaction match.
     * 
     * @param string $matchId Match ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveMatch(string $matchId, string $approverId, array $approvalData = []): array;

    /**
     * Rejects a transaction match.
     * 
     * @param string $matchId Match ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectMatch(string $matchId, string $rejecterId, string $reason, array $rejectionData = []): array;

    /**
     * Gets review queue.
     * 
     * @param array $filters Filter parameters
     * @return array Review queue
     */
    public function getReviewQueue(array $filters = []): array;

    /**
     * Gets match queue.
     * 
     * @param array $filters Filter parameters
     * @return array Match queue
     */
    public function getMatchQueue(array $filters = []): array;

    /**
     * Gets review history.
     * 
     * @param array $filters Filter parameters
     * @return array Review history
     */
    public function getReviewHistory(array $filters = []): array;

    /**
     * Gets match history.
     * 
     * @param array $filters Filter parameters
     * @return array Match history
     */
    public function getMatchHistory(array $filters = []): array;

    /**
     * Generates review report.
     * 
     * @param array $filters Filter parameters
     * @return array Review report
     */
    public function generateReviewReport(array $filters = []): array;

    /**
     * Gets review statistics.
     * 
     * @return array Review statistics
     */
    public function getReviewStatistics(): array;

    /**
     * Clears review queue.
     */
    public function clearReviewQueue(): void;

    /**
     * Clears match queue.
     */
    public function clearMatchQueue(): void;

    /**
     * Clears review history.
     */
    public function clearReviewHistory(): void;

    /**
     * Clears match history.
     */
    public function clearMatchHistory(): void;
}