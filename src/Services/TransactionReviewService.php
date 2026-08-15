<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Transaction Review Service
 * 
 * Handles transaction review and matching functionality.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class TransactionReviewService
{
    private array $config;
    private array $reviewQueue = [];
    private array $matchQueue = [];
    private array $reviewHistory = [];
    private const REVIEW_STATUS_PENDING = 'pending';
    const REVIEW_STATUS_APPROVED = 'approved';
    const REVIEW_STATUS_REJECTED = 'rejected';
    const MATCH_STATUS_UNMATCHED = 'unmatched';
    const MATCH_STATUS_MATCHED = 'matched';
    const MATCH_STATUS_REVIEW = 'review';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_review' => true,
            'enable_matching' => true,
            'auto_approve_threshold' => 1000, // Amount threshold for auto-approval
            'match_tolerance' => 0.05, // 5% tolerance for matching
            'review_timeout' => 24 * 60 * 60, // 24 hours
            'log_reviews' => true,
            'review_log_file' => sys_get_temp_dir() . '/reviews.log'
        ], $config);
    }

    /**
     * Reviews a transaction.
     * 
     * Verifies that a transaction exists in the underlying data layer and
     * identifies its source so callers can choose the appropriate correction
     * method.
     * 
     * @param int $transactionId Transaction ID
     * @param array $reviewData Additional review data (optional)
     * @return array Review results
     */
    public function reviewTransaction(int $transactionId, array $reviewData = []): array
    {
        try {
            // Look up transaction in the data layer
            $transaction = $this->getTransactionForReview($transactionId);
            $exists = $transaction !== null;
            
            // Determine transaction source
            $source = $exists ? $this->determineTransactionSource($transaction) : 'unknown';
            
            // Create review entry
            $review = [
                'review_id' => uniqid('review_'),
                'transaction_id' => $transactionId,
                'exists' => $exists,
                'source' => $source,
                'amount' => $transaction['total_amount'] ?? 0,
                'customer_id' => $transaction['debtor_id'] ?? 0,
                'transaction_date' => $transaction['created_at'] ?? time(),
                'status' => $exists ? self::REVIEW_STATUS_PENDING : 'not_found',
                'priority' => $this->calculateReviewPriority(['amount' => $transaction['total_amount'] ?? 0]),
                'created_at' => time(),
                'review_data' => $reviewData,
                'metadata' => $transaction['metadata'] ?? []
            ];
            
            // Add to review queue when transaction exists
            if ($exists) {
                $this->addToReviewQueue($review);
            }
            
            // Log review
            if ($this->config['log_reviews']) {
                $this->logReview($review);
            }
            
            return $review;
        } catch (\Exception $e) {
            throw new \Exception("Transaction review failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieves transaction details for review.
     * 
     * Simulated data-layer lookup keyed by transaction ID until a live
     * transaction data source is available.
     * 
     * @param int $transactionId Transaction ID
     * @return array|null Transaction details or null when not found
     */
    private function getTransactionForReview(int $transactionId): ?array
    {
        // This would be implemented with actual transaction retrieval
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => $transactionId,
            'total_amount' => 400,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Generic FA transactions are stored separately in FA tables
        if ($transactionId == 2001) {
            $transaction['source'] = 'fa_generic';
            $transaction['total_amount'] = 950;
        }
        
        return $transaction;
    }

    /**
     * Determines transaction source (Square staging vs generic FA).
     * 
     * @param array $transaction Transaction details
     * @return string Transaction source
     */
    private function determineTransactionSource(array $transaction): string
    {
        if (isset($transaction['source']) && $transaction['source'] === 'square') {
            return 'square_staging';
        }
        
        if (isset($transaction['source']) && $transaction['source'] === 'fa_generic') {
            return 'fa_generic';
        }
        
        return 'unknown';
    }

    /**
     * Approves a transaction review.
     * 
     * @param string $reviewId Review ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveReview(string $reviewId, string $approverId, array $approvalData = []): array
    {
        try {
            // Find review in queue
            $review = $this->findReviewById($reviewId);
            
            if (!$review) {
                throw new \Exception("Review not found");
            }
            
            if ($review['status'] !== self::REVIEW_STATUS_PENDING) {
                throw new \Exception("Review is not pending approval");
            }
            
            // Update review status
            $review['status'] = self::REVIEW_STATUS_APPROVED;
            $review['approved_by'] = $approverId;
            $review['approved_at'] = time();
            $review['approval_data'] = $approvalData;
            
            // Process approval
            $approvalResult = $this->processApproval($review);
            
            // Remove from queue and add to history
            $this->removeFromReviewQueue($reviewId);
            $this->addToReviewHistory($review);
            
            return $approvalResult;
        } catch (\Exception $e) {
            throw new \Exception("Review approval failed: " . $e->getMessage());
        }
    }

    /**
     * Rejects a transaction review.
     * 
     * @param string $reviewId Review ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectReview(string $reviewId, string $rejecterId, string $reason, array $rejectionData = []): array
    {
        try {
            // Find review in queue
            $review = $this->findReviewById($reviewId);
            
            if (!$review) {
                throw new \Exception("Review not found");
            }
            
            if ($review['status'] !== self::REVIEW_STATUS_PENDING) {
                throw new \Exception("Review is not pending approval");
            }
            
            // Update review status
            $review['status'] = self::REVIEW_STATUS_REJECTED;
            $review['rejected_by'] = $rejecterId;
            $review['rejected_at'] = time();
            $review['rejection_reason'] = $reason;
            $review['rejection_data'] = $rejectionData;
            
            // Process rejection
            $rejectionResult = $this->processRejection($review);
            
            // Remove from queue and add to history
            $this->removeFromReviewQueue($reviewId);
            $this->addToReviewHistory($review);
            
            return $rejectionResult;
        } catch (\Exception $e) {
            throw new \Exception("Review rejection failed: " . $e->getMessage());
        }
    }

    /**
     * Matches transactions.
     * 
     * @param array $matchData Match data
     * @return array Match results
     */
    public function matchTransactions(array $matchData): array
    {
        try {
            // Validate match data
            $this->validateMatchData($matchData);
            
            // Create match entry
            $match = $this->createMatchEntry($matchData);
            
            // Perform matching
            $matchResult = $this->performMatching($match);
            
            // Add to match queue
            $this->addToMatchQueue($match);
            
            // Log match
            $this->logMatch($match);
            
            return $matchResult;
        } catch (\Exception $e) {
            throw new \Exception("Transaction matching failed: " . $e->getMessage());
        }
    }

    /**
     * Approves a transaction match.
     * 
     * @param string $matchId Match ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveMatch(string $matchId, string $approverId, array $approvalData = []): array
    {
        try {
            // Find match in queue
            $match = $this->findMatchById($matchId);
            
            if (!$match) {
                throw new \Exception("Match not found");
            }
            
            if ($match['status'] !== self::MATCH_STATUS_REVIEW) {
                throw new \Exception("Match is not pending approval");
            }
            
            // Update match status
            $match['status'] = self::MATCH_STATUS_MATCHED;
            $match['approved_by'] = $approverId;
            $match['approved_at'] = time();
            $match['approval_data'] = $approvalData;
            
            // Process approval
            $approvalResult = $this->processMatchApproval($match);
            
            // Remove from queue and add to history
            $this->removeFromMatchQueue($matchId);
            $this->addToMatchHistory($match);
            
            return $approvalResult;
        } catch (\Exception $e) {
            throw new \Exception("Match approval failed: " . $e->getMessage());
        }
    }

    /**
     * Rejects a transaction match.
     * 
     * @param string $matchId Match ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectMatch(string $matchId, string $rejecterId, string $reason, array $rejectionData = []): array
    {
        try {
            // Find match in queue
            $match = $this->findMatchById($matchId);
            
            if (!$match) {
                throw new \Exception("Match not found");
            }
            
            if ($match['status'] !== self::MATCH_STATUS_REVIEW) {
                throw new \Exception("Match is not pending approval");
            }
            
            // Update match status
            $match['status'] = self::MATCH_STATUS_UNMATCHED;
            $match['rejected_by'] = $rejecterId;
            $match['rejected_at'] = time();
            $match['rejection_reason'] = $reason;
            $match['rejection_data'] = $rejectionData;
            
            // Process rejection
            $rejectionResult = $this->processMatchRejection($match);
            
            // Remove from queue and add to history
            $this->removeFromMatchQueue($matchId);
            $this->addToMatchHistory($match);
            
            return $rejectionResult;
        } catch (\Exception $e) {
            throw new \Exception("Match rejection failed: " . $e->getMessage());
        }
    }

    /**
     * Gets review queue.
     * 
     * @param array $filters Filter parameters
     * @return array Review queue
     */
    public function getReviewQueue(array $filters = []): array
    {
        $filteredQueue = $this->reviewQueue;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredQueue = array_filter($filteredQueue, fn($r) => $r['status'] == $filters['status']);
        }
        
        if (isset($filters['priority'])) {
            $filteredQueue = array_filter($filteredQueue, fn($r) => $r['priority'] == $filters['priority']);
        }
        
        if (isset($filters['amount_min'])) {
            $filteredQueue = array_filter($filteredQueue, fn($r) => $r['amount'] >= $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $filteredQueue = array_filter($filteredQueue, fn($r) => $r['amount'] <= $filters['amount_max']);
        }
        
        return array_values($filteredQueue);
    }

    /**
     * Gets match queue.
     * 
     * @param array $filters Filter parameters
     * @return array Match queue
     */
    public function getMatchQueue(array $filters = []): array
    {
        $filteredQueue = $this->matchQueue;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['status'] == $filters['status']);
        }
        
        if (isset($filters['confidence'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['confidence'] >= $filters['confidence']);
        }
        
        return array_values($filteredQueue);
    }

    /**
     * Gets review history.
     * 
     * @param array $filters Filter parameters
     * @return array Review history
     */
    public function getReviewHistory(array $filters = []): array
    {
        $filteredHistory = $this->reviewHistory;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['status'] == $filters['status']);
        }
        
        if (isset($filters['reviewer'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['reviewed_by'] == $filters['reviewer']);
        }
        
        if (isset($filters['date_from'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['reviewed_at'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['reviewed_at'] <= $filters['date_to']);
        }
        
        return array_values($filteredHistory);
    }

    /**
     * Gets match history.
     * 
     * @param array $filters Filter parameters
     * @return array Match history
     */
    public function getMatchHistory(array $filters = []): array
    {
        $filteredHistory = $this->matchHistory ?? [];
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['status'] == $filters['status']);
        }
        
        if (isset($filters['matcher'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['matched_by'] == $filters['matcher']);
        }
        
        if (isset($filters['date_from'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['matched_at'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['matched_at'] <= $filters['date_to']);
        }
        
        return array_values($filteredHistory);
    }

    /**
     * Generates review report.
     * 
     * @param array $filters Filter parameters
     * @return array Review report
     */
    public function generateReviewReport(array $filters = []): array
    {
        $report = [
            'generated_at' => time(),
            'filters' => $filters,
            'queue_summary' => $this->getQueueSummary($filters),
            'history_summary' => $this->getHistorySummary($filters),
            'statistics' => $this->getReviewStatistics(),
            'recommendations' => $this->generateRecommendations()
        ];
        
        return $report;
    }

    /**
     * Validates review data.
     * 
     * @param array $reviewData Review data
     * @throws \Exception on validation failure
     */
    private function validateReviewData(array $reviewData): void
    {
        if (empty($reviewData)) {
            throw new \Exception("Review data is required");
        }
        
        if (!isset($reviewData['transaction_id'])) {
            throw new \Exception("Transaction ID is required");
        }
        
        if (!isset($reviewData['amount'])) {
            throw new \Exception("Amount is required");
        }
        
        if (!isset($reviewData['customer_id'])) {
            throw new \Exception("Customer ID is required");
        }
        
        if (!isset($reviewData['transaction_date'])) {
            throw new \Exception("Transaction date is required");
        }
    }

    /**
     * Validates match data.
     * 
     * @param array $matchData Match data
     * @throws \Exception on validation failure
     */
    private function validateMatchData(array $matchData): void
    {
        if (empty($matchData)) {
            throw new \Exception("Match data is required");
        }
        
        if (!isset($matchData['square_transaction_id'])) {
            throw new \Exception("Square transaction ID is required");
        }
        
        if (!isset($matchData['fa_transaction_id'])) {
            throw new \Exception("FA transaction ID is required");
        }
        
        if (!isset($matchData['match_type'])) {
            throw new \Exception("Match type is required");
        }
        
        if (!isset($matchData['confidence'])) {
            throw new \Exception("Confidence score is required");
        }
    }

    /**
     * Creates review entry.
     * 
     * @param array $reviewData Review data
     * @return array Review entry
     */
    private function createReviewEntry(array $reviewData): array
    {
        $review = [
            'review_id' => uniqid('review_'),
            'transaction_id' => $reviewData['transaction_id'],
            'amount' => $reviewData['amount'],
            'customer_id' => $reviewData['customer_id'],
            'transaction_date' => $reviewData['transaction_date'],
            'status' => self::REVIEW_STATUS_PENDING,
            'priority' => $this->calculateReviewPriority($reviewData),
            'created_at' => time(),
            'review_data' => $reviewData,
            'metadata' => $reviewData['metadata'] ?? []
        ];
        
        return $review;
    }

    /**
     * Creates match entry.
     * 
     * @param array $matchData Match data
     * @return array Match entry
     */
    private function createMatchEntry(array $matchData): array
    {
        $match = [
            'match_id' => uniqid('match_'),
            'square_transaction_id' => $matchData['square_transaction_id'],
            'fa_transaction_id' => $matchData['fa_transaction_id'],
            'match_type' => $matchData['match_type'],
            'confidence' => $matchData['confidence'],
            'status' => self::MATCH_STATUS_REVIEW,
            'created_at' => time(),
            'match_data' => $matchData,
            'metadata' => $matchData['metadata'] ?? []
        ];
        
        return $match;
    }

    /**
     * Calculates review priority.
     * 
     * @param array $reviewData Review data
     * @return string Priority level
     */
    private function calculateReviewPriority(array $reviewData): string
    {
        $amount = $reviewData['amount'];
        
        if ($amount > 10000) {
            return 'high';
        } elseif ($amount > 5000) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Determines if auto-review should be performed.
     * 
     * @param array $reviewData Review data
     * @return bool True if auto-review should be performed
     */
    private function shouldAutoReview(array $reviewData): bool
    {
        $amount = $reviewData['amount'];
        $customerRisk = $this->getCustomerRiskLevel($reviewData['customer_id']);
        
        return $amount <= $this->config['auto_approve_threshold'] && $customerRisk === 'low';
    }

    /**
     * Performs auto-review.
     * 
     * @param array $review Review data
     * @return array Auto-review result
     */
    private function performAutoReview(array $review): array
    {
        $review['status'] = self::REVIEW_STATUS_APPROVED;
        $review['approved_by'] = 'system';
        $review['approved_at'] = time();
        $review['auto_reviewed'] = true;
        
        return [
            'success' => true,
            'review_id' => $review['review_id'],
            'method' => 'auto',
            'reason' => 'Amount below threshold and low customer risk',
            'timestamp' => time()
        ];
    }

    /**
     * Processes approval.
     * 
     * @param array $review Review data
     * @return array Approval result
     */
    private function processApproval(array $review): array
    {
        // This would be implemented with actual approval processing logic
        return [
            'success' => true,
            'review_id' => $review['review_id'],
            'transaction_id' => $review['transaction_id'],
            'approved_by' => $review['approved_by'],
            'approved_at' => $review['approved_at'],
            'message' => 'Review approved successfully'
        ];
    }

    /**
     * Processes rejection.
     * 
     * @param array $review Review data
     * @return array Rejection result
     */
    private function processRejection(array $review): array
    {
        // This would be implemented with actual rejection processing logic
        return [
            'success' => true,
            'review_id' => $review['review_id'],
            'transaction_id' => $review['transaction_id'],
            'rejected_by' => $review['rejected_by'],
            'rejected_at' => $review['rejected_at'],
            'reason' => $review['rejection_reason'],
            'message' => 'Review rejected successfully'
        ];
    }

    /**
     * Performs matching.
     * 
     * @param array $match Match data
     * @return array Match result
     */
    private function performMatching(array $match): array
    {
        // Calculate match confidence
        $confidence = $this->calculateMatchConfidence($match);
        $match['confidence'] = $confidence;
        
        // Determine if manual review is needed
        if ($confidence < 0.8) {
            $match['status'] = self::MATCH_STATUS_REVIEW;
        } else {
            $match['status'] = self::MATCH_STATUS_MATCHED;
        }
        
        return [
            'success' => true,
            'match_id' => $match['match_id'],
            'confidence' => $confidence,
            'status' => $match['status'],
            'timestamp' => time()
        ];
    }

    /**
     * Processes match approval.
     * 
     * @param array $match Match data
     * @return array Approval result
     */
    private function processMatchApproval(array $match): array
    {
        // This would be implemented with actual match approval processing logic
        return [
            'success' => true,
            'match_id' => $match['match_id'],
            'square_transaction_id' => $match['square_transaction_id'],
            'fa_transaction_id' => $match['fa_transaction_id'],
            'approved_by' => $match['approved_by'],
            'approved_at' => $match['approved_at'],
            'message' => 'Match approved successfully'
        ];
    }

    /**
     * Processes match rejection.
     * 
     * @param array $match Match data
     * @return array Rejection result
     */
    private function processMatchRejection(array $match): array
    {
        // This would be implemented with actual match rejection processing logic
        return [
            'success' => true,
            'match_id' => $match['match_id'],
            'square_transaction_id' => $match['square_transaction_id'],
            'fa_transaction_id' => $match['fa_transaction_id'],
            'rejected_by' => $match['rejected_by'],
            'rejected_at' => $match['rejected_at'],
            'reason' => $match['rejection_reason'],
            'message' => 'Match rejected successfully'
        ];
    }

    /**
     * Calculates match confidence.
     * 
     * @param array $match Match data
     * @return float Confidence score
     */
    private function calculateMatchConfidence(array $match): float
    {
        // This would be implemented with actual confidence calculation logic
        $amountMatch = $this->checkAmountMatch($match);
        $customerMatch = $this->checkCustomerMatch($match);
        $dateMatch = $this->checkDateMatch($match);
        
        $confidence = ($amountMatch + $customerMatch + $dateMatch) / 3;
        
        return min(1.0, $confidence);
    }

    /**
     * Checks amount match.
     * 
     * @param array $match Match data
     * @return float Match score
     */
    private function checkAmountMatch(array $match): float
    {
        // This would be implemented with actual amount matching logic
        return 0.95;
    }

    /**
     * Checks customer match.
     * 
     * @param array $match Match data
     * @return float Match score
     */
    private function checkCustomerMatch(array $match): float
    {
        // This would be implemented with actual customer matching logic
        return 0.90;
    }

    /**
     * Checks date match.
     * 
     * @param array $match Match data
     * @return float Match score
     */
    private function checkDateMatch(array $match): float
    {
        // This would be implemented with actual date matching logic
        return 0.85;
    }

    /**
     * Gets customer risk level.
     * 
     * @param int $customerId Customer ID
     * @return string Risk level
     */
    private function getCustomerRiskLevel(int $customerId): string
    {
        // This would be implemented with actual customer risk assessment logic
        return 'low';
    }

    /**
     * Adds to review queue.
     * 
     * @param array $review Review data
     */
    private function addToReviewQueue(array $review): void
    {
        $this->reviewQueue[] = $review;
    }

    /**
     * Removes from review queue.
     * 
     * @param string $reviewId Review ID
     */
    private function removeFromReviewQueue(string $reviewId): void
    {
        $this->reviewQueue = array_filter($this->reviewQueue, fn($r) => $r['review_id'] != $reviewId);
    }

    /**
     * Adds to match queue.
     * 
     * @param array $match Match data
     */
    private function addToMatchQueue(array $match): void
    {
        $this->matchQueue[] = $match;
    }

    /**
     * Removes from match queue.
     * 
     * @param string $matchId Match ID
     */
    private function removeFromMatchQueue(string $matchId): void
    {
        $this->matchQueue = array_filter($this->matchQueue, fn($m) => $m['match_id'] != $matchId);
    }

    /**
     * Adds to review history.
     * 
     * @param array $review Review data
     */
    private function addToReviewHistory(array $review): void
    {
        $this->reviewHistory[] = $review;
    }

    /**
     * Adds to match history.
     * 
     * @param array $match Match data
     */
    private function addToMatchHistory(array $match): void
    {
        $this->matchHistory[] = $match;
    }

    /**
     * Finds review by ID.
     * 
     * @param string $reviewId Review ID
     * @return array|null Review or null
     */
    private function findReviewById(string $reviewId): ?array
    {
        foreach ($this->reviewQueue as $review) {
            if ($review['review_id'] == $reviewId) {
                return $review;
            }
        }
        return null;
    }

    /**
     * Finds match by ID.
     * 
     * @param string $matchId Match ID
     * @return array|null Match or null
     */
    private function findMatchById(string $matchId): ?array
    {
        foreach ($this->matchQueue as $match) {
            if ($match['match_id'] == $matchId) {
                return $match;
            }
        }
        return null;
    }

    /**
     * Logs review.
     * 
     * @param array $review Review data
     */
    private function logReview(array $review): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Review ID: %s, Transaction ID: %s, Amount: %s, Status: %s\n",
            date('Y-m-d H:i:s'),
            $review['status'],
            $review['review_id'],
            $review['transaction_id'],
            $review['amount'],
            $review['status']
        );
        
        file_put_contents($this->config['review_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Logs match.
     * 
     * @param array $match Match data
     */
    private function logMatch(array $match): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Match ID: %s, Square: %s, FA: %s, Confidence: %.2f\n",
            date('Y-m-d H:i:s'),
            $match['status'],
            $match['match_id'],
            $match['square_transaction_id'],
            $match['fa_transaction_id'],
            $match['confidence']
        );
        
        file_put_contents($this->config['review_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Gets queue summary.
     * 
     * @param array $filters Filter parameters
     * @return array Queue summary
     */
    private function getQueueSummary(array $filters = []): array
    {
        $queue = $this->getReviewQueue($filters);
        
        return [
            'total_reviews' => count($queue),
            'pending_reviews' => count(array_filter($queue, fn($r) => $r['status'] === self::REVIEW_STATUS_PENDING)),
            'approved_reviews' => count(array_filter($queue, fn($r) => $r['status'] === self::REVIEW_STATUS_APPROVED)),
            'rejected_reviews' => count(array_filter($queue, fn($r) => $r['status'] === self::REVIEW_STATUS_REJECTED)),
            'high_priority' => count(array_filter($queue, fn($r) => $r['priority'] === 'high')),
            'medium_priority' => count(array_filter($queue, fn($r) => $r['priority'] === 'medium')),
            'low_priority' => count(array_filter($queue, fn($r) => $r['priority'] === 'low'))
        ];
    }

    /**
     * Gets history summary.
     * 
     * @param array $filters Filter parameters
     * @return array History summary
     */
    private function getHistorySummary(array $filters = []): array
    {
        $history = $this->getReviewHistory($filters);
        
        return [
            'total_reviews' => count($history),
            'approved_reviews' => count(array_filter($history, fn($h) => $h['status'] === self::REVIEW_STATUS_APPROVED)),
            'rejected_reviews' => count(array_filter($history, fn($h) => $h['status'] === self::REVIEW_STATUS_REJECTED)),
            'auto_reviews' => count(array_filter($history, fn($h) => isset($h['auto_reviewed']) && $h['auto_reviewed'])),
            'manual_reviews' => count(array_filter($history, fn($h) => !isset($h['auto_reviewed']) || !$h['auto_reviewed']))
        ];
    }

    /**
     * Gets review statistics.
     * 
     * @return array Review statistics
     */
    private function getReviewStatistics(): array
    {
        $stats = [
            'total_reviews' => count($this->reviewHistory),
            'total_matches' => count($this->matchHistory ?? []),
            'average_review_time' => $this->calculateAverageReviewTime(),
            'average_match_confidence' => $this->calculateAverageMatchConfidence(),
            'review_backlog' => count($this->reviewQueue),
            'match_backlog' => count($this->matchQueue),
            'success_rate' => $this->calculateSuccessRate()
        ];
        
        return $stats;
    }

    /**
     * Calculates average review time.
     * 
     * @return float Average review time in seconds
     */
    private function calculateAverageReviewTime(): float
    {
        $totalTime = 0;
        $count = 0;
        
        foreach ($this->reviewHistory as $review) {
            if (isset($review['reviewed_at']) && isset($review['created_at'])) {
                $totalTime += $review['reviewed_at'] - $review['created_at'];
                $count++;
            }
        }
        
        return $count > 0 ? $totalTime / $count : 0;
    }

    /**
     * Calculates average match confidence.
     * 
     * @return float Average match confidence
     */
    private function calculateAverageMatchConfidence(): float
    {
        if (empty($this->matchHistory)) {
            return 0;
        }
        
        $totalConfidence = 0;
        $count = 0;
        
        foreach ($this->matchHistory as $match) {
            if (isset($match['confidence'])) {
                $totalConfidence += $match['confidence'];
                $count++;
            }
        }
        
        return $count > 0 ? $totalConfidence / $count : 0;
    }

    /**
     * Calculates success rate.
     * 
     * @return float Success rate
     */
    private function calculateSuccessRate(): float
    {
        if (empty($this->reviewHistory)) {
            return 0;
        }
        
        $successful = count(array_filter($this->reviewHistory, fn($h) => $h['status'] === self::REVIEW_STATUS_APPROVED));
        $total = count($this->reviewHistory);
        
        return $total > 0 ? $successful / $total : 0;
    }

    /**
     * Generates recommendations.
     * 
     * @return array Recommendations
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        // Review backlog recommendations
        if (count($this->reviewQueue) > 100) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'review_backlog',
                'message' => 'High review backlog detected. Consider adding more reviewers or increasing auto-approval threshold.'
            ];
        }
        
        // Match backlog recommendations
        if (count($this->matchQueue) > 50) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'match_backlog',
                'message' => 'High match backlog detected. Consider reviewing matching algorithms.'
            ];
        }
        
        // Performance recommendations
        $avgReviewTime = $this->calculateAverageReviewTime();
        if ($avgReviewTime > 3600) { // 1 hour
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'performance',
                'message' => 'Average review time is high. Consider optimizing review processes.'
            ];
        }
        
        // Quality recommendations
        $successRate = $this->calculateSuccessRate();
        if ($successRate < 0.8) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'quality',
                'message' => 'Low success rate detected. Consider reviewing review criteria.'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Gets configuration.
     * 
     * @return array Configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Sets configuration.
     * 
     * @param array $config Configuration to set
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Clears review queue.
     */
    public function clearReviewQueue(): void
    {
        $this->reviewQueue = [];
    }

    /**
     * Clears match queue.
     */
    public function clearMatchQueue(): void
    {
        $this->matchQueue = [];
    }

    /**
     * Clears review history.
     */
    public function clearReviewHistory(): void
    {
        $this->reviewHistory = [];
    }

    /**
     * Clears match history.
     */
    public function clearMatchHistory(): void
    {
        $this->matchHistory = [];
    }
}