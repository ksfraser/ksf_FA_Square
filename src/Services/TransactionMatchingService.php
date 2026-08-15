<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Transaction Matching Service
 * 
 * Handles intelligent transaction matching between Square and FA.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class TransactionMatchingService
{
    private array $config;
    private array $matchingQueue = [];
    private array $matchingHistory = [];
    private array $matchRules = [];
    private array $matchConfidence = [];
    const MATCH_TYPE_EXACT = 'exact';
    const MATCH_TYPE_FUZZY = 'fuzzy';
    const MATCH_TYPE_PARTIAL = 'partial';
    const MATCH_TYPE_UNMATCHED = 'unmatched';
    const MATCH_STATUS_PENDING = 'pending';
    const MATCH_STATUS_MATCHED = 'matched';
    const MATCH_STATUS_REVIEW = 'review';
    const MATCH_STATUS_REJECTED = 'rejected';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_matching' => true,
            'auto_approve_threshold' => 0.95,
            'review_threshold' => 0.80,
            'match_timeout' => 24 * 60 * 60, // 24 hours
            'enable_fuzzy_matching' => true,
            'enable_partial_matching' => true,
            'log_matches' => true,
            'matching_log_file' => sys_get_temp_dir() . '/matching.log'
        ], $config);
        
        $this->initializeMatchRules();
    }

    /**
     * Matches transactions.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransactions FA transactions data
     * @return array Match results
     */
    public function matchTransactions(array $squareTransaction, array $faTransactions): array
    {
        try {
            // Validate transaction data
            $this->validateTransactionData($squareTransaction, $faTransactions);
            
            // Find potential matches
            $potentialMatches = $this->findPotentialMatches($squareTransaction, $faTransactions);
            
            // Calculate match confidence
            $matchConfidence = $this->calculateMatchConfidence($squareTransaction, $potentialMatches);
            
            // Create match entry
            $matchEntry = $this->createMatchEntry($squareTransaction, $potentialMatches, $matchConfidence);
            
            // Determine match status
            $matchStatus = $this->determineMatchStatus($matchConfidence);
            
            // Update match entry with status
            $matchEntry['status'] = $matchStatus;
            
            // Add to matching queue
            $this->addToMatchingQueue($matchEntry);
            
            // Log match
            if ($this->config['log_matches']) {
                $this->logMatch($matchEntry);
            }
            
            return $matchEntry;
        } catch (\Exception $e) {
            throw new \Exception("Transaction matching failed: " . $e->getMessage());
        }
    }

    /**
     * Processes match approval.
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
            $this->removeFromMatchingQueue($matchId);
            $this->addToMatchingHistory($match);
            
            return $approvalResult;
        } catch (\Exception $e) {
            throw new \Exception("Match approval failed: " . $e->getMessage());
        }
    }

    /**
     * Processes match rejection.
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
            $match['status'] = self::MATCH_STATUS_REJECTED;
            $match['rejected_by'] = $rejecterId;
            $match['rejected_at'] = time();
            $match['rejection_reason'] = $reason;
            $match['rejection_data'] = $rejectionData;
            
            // Process rejection
            $rejectionResult = $this->processMatchRejection($match);
            
            // Remove from queue and add to history
            $this->removeFromMatchingQueue($matchId);
            $this->addToMatchingHistory($match);
            
            return $rejectionResult;
        } catch (\Exception $e) {
            throw new \Exception("Match rejection failed: " . $e->getMessage());
        }
    }

    /**
     * Gets matching queue.
     * 
     * @param array $filters Filter parameters
     * @return array Matching queue
     */
    public function getMatchingQueue(array $filters = []): array
    {
        $filteredQueue = $this->matchingQueue;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['status'] == $filters['status']);
        }
        
        if (isset($filters['confidence'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['confidence'] >= $filters['confidence']);
        }
        
        if (isset($filters['match_type'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['match_type'] == $filters['match_type']);
        }
        
        if (isset($filters['date_from'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['created_at'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $filteredQueue = array_filter($filteredQueue, fn($m) => $m['created_at'] <= $filters['date_to']);
        }
        
        return array_values($filteredQueue);
    }

    /**
     * Gets matching history.
     * 
     * @param array $filters Filter parameters
     * @return array Matching history
     */
    public function getMatchingHistory(array $filters = []): array
    {
        $filteredHistory = $this->matchingHistory;
        
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
     * Gets match statistics.
     * 
     * @return array Match statistics
     */
    public function getMatchingStatistics(): array
    {
        $stats = [
            'total_matches' => count($this->matchingHistory),
            'pending_matches' => count(array_filter($this->matchingQueue, fn($m) => $m['status'] === self::MATCH_STATUS_PENDING)),
            'review_matches' => count(array_filter($this->matchingQueue, fn($m) => $m['status'] === self::MATCH_STATUS_REVIEW)),
            'matched_transactions' => count(array_filter($this->matchingHistory, fn($h) => $h['status'] === self::MATCH_STATUS_MATCHED)),
            'rejected_transactions' => count(array_filter($this->matchingHistory, fn($h) => $h['status'] === self::MATCH_STATUS_REJECTED)),
            'average_confidence' => $this->calculateAverageConfidence(),
            'match_success_rate' => $this->calculateMatchSuccessRate(),
            'matches_by_type' => $this->countMatchesByType(),
            'matches_by_day' => $this->countMatchesByDay()
        ];
        
        return $stats;
    }

    /**
     * Generates match report.
     * 
     * @param array $filters Filter parameters
     * @return array Match report
     */
    public function generateMatchReport(array $filters = []): array
    {
        $report = [
            'generated_at' => time(),
            'filters' => $filters,
            'queue_summary' => $this->getQueueSummary($filters),
            'history_summary' => $this->getHistorySummary($filters),
            'statistics' => $this->getMatchingStatistics(),
            'recommendations' => $this->generateRecommendations(),
            'performance_metrics' => $this->getPerformanceMetrics()
        ];
        
        return $report;
    }

    /**
     * Updates match rules.
     * 
     * @param array $rules Match rules
     * @return array Update results
     */
    public function updateMatchRules(array $rules): array
    {
        try {
            $this->matchRules = array_merge($this->matchRules, $rules);
            
            return [
                'success' => true,
                'rules_updated' => count($rules),
                'message' => 'Match rules updated successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Match rules update failed: " . $e->getMessage());
        }
    }

    /**
     * Validates transaction data.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransactions FA transactions data
     * @throws \Exception on validation failure
     */
    private function validateTransactionData(array $squareTransaction, array $faTransactions): void
    {
        if (empty($squareTransaction)) {
            throw new \Exception("Square transaction data is required");
        }
        
        if (empty($faTransactions)) {
            throw new \Exception("FA transactions data is required");
        }
        
        if (!isset($squareTransaction['id'])) {
            throw new \Exception("Square transaction ID is required");
        }
        
        if (!isset($squareTransaction['amount'])) {
            throw new \Exception("Square transaction amount is required");
        }
        
        if (!isset($squareTransaction['created_at'])) {
            throw new \Exception("Square transaction timestamp is required");
        }
        
        if (!isset($squareTransaction['customer'])) {
            throw new \Exception("Square transaction customer is required");
        }
    }

    /**
     * Creates match entry.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $potentialMatches Potential matches
     * @param array $matchConfidence Match confidence
     * @return array Match entry
     */
    private function createMatchEntry(array $squareTransaction, array $potentialMatches, array $matchConfidence): array
    {
        $entry = [
            'match_id' => uniqid('match_'),
            'square_transaction_id' => $squareTransaction['id'],
            'square_transaction_data' => $squareTransaction,
            'potential_matches' => $potentialMatches,
            'match_type' => $matchConfidence['type'],
            'confidence' => $matchConfidence['score'],
            'match_details' => $matchConfidence['details'],
            'created_at' => time(),
            'status' => self::MATCH_STATUS_PENDING
        ];
        
        return $entry;
    }

    /**
     * Finds potential matches.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransactions FA transactions data
     * @return array Potential matches
     */
    private function findPotentialMatches(array $squareTransaction, array $faTransactions): array
    {
        $potentialMatches = [];
        
        foreach ($faTransactions as $faTransaction) {
            $matchScore = $this->calculateIndividualMatchScore($squareTransaction, $faTransaction);
            
            if ($matchScore > 0.1) { // Minimum threshold for potential match
                $potentialMatches[] = [
                    'fa_transaction_id' => $faTransaction['id'],
                    'fa_transaction_data' => $faTransaction,
                    'match_score' => $matchScore,
                    'match_details' => $this->getMatchDetails($squareTransaction, $faTransaction)
                ];
            }
        }
        
        // Sort by match score
        usort($potentialMatches, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
        
        return $potentialMatches;
    }

    /**
     * Calculates match confidence.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $potentialMatches Potential matches
     * @return array Match confidence
     */
    private function calculateMatchConfidence(array $squareTransaction, array $potentialMatches): array
    {
        if (empty($potentialMatches)) {
            return [
                'type' => self::MATCH_TYPE_UNMATCHED,
                'score' => 0.0,
                'details' => 'No potential matches found'
            ];
        }
        
        $bestMatch = $potentialMatches[0];
        $matchScore = $bestMatch['match_score'];
        
        // Determine match type
        $matchType = $this->determineMatchType($matchScore);
        
        return [
            'type' => $matchType,
            'score' => $matchScore,
            'details' => $bestMatch['match_details']
        ];
    }

    /**
     * Determines match status.
     * 
     * @param array $matchConfidence Match confidence
     * @return string Match status
     */
    private function determineMatchStatus(array $matchConfidence): string
    {
        $score = $matchConfidence['score'];
        
        if ($score >= $this->config['auto_approve_threshold']) {
            return self::MATCH_STATUS_MATCHED;
        } elseif ($score >= $this->config['review_threshold']) {
            return self::MATCH_STATUS_REVIEW;
        } else {
            return self::MATCH_STATUS_PENDING;
        }
    }

    /**
     * Calculates individual match score.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransaction FA transaction data
     * @return float Match score
     */
    private function calculateIndividualMatchScore(array $squareTransaction, array $faTransaction): float
    {
        $score = 0.0;
        $maxScore = 0.0;
        
        // Amount match
        $amountScore = $this->calculateAmountMatch($squareTransaction['amount'], $faTransaction['amount']);
        $score += $amountScore * 0.4; // 40% weight
        $maxScore += 0.4;
        
        // Customer match
        $customerScore = $this->calculateCustomerMatch($squareTransaction['customer'], $faTransaction['customer']);
        $score += $customerScore * 0.3; // 30% weight
        $maxScore += 0.3;
        
        // Date match
        $dateScore = $this->calculateDateMatch($squareTransaction['created_at'], $faTransaction['created_at']);
        $score += $dateScore * 0.2; // 20% weight
        $maxScore += 0.2;
        
        // Reference match
        $referenceScore = $this->calculateReferenceMatch($squareTransaction, $faTransaction);
        $score += $referenceScore * 0.1; // 10% weight
        $maxScore += 0.1;
        
        return $maxScore > 0 ? $score / $maxScore : 0.0;
    }

    /**
     * Calculates amount match score.
     * 
     * @param float $squareAmount Square transaction amount
     * @param float $faAmount FA transaction amount
     * @return float Match score
     */
    private function calculateAmountMatch(float $squareAmount, float $faAmount): float
    {
        $difference = abs($squareAmount - $faAmount);
        $tolerance = max($squareAmount, $faAmount) * 0.01; // 1% tolerance
        
        if ($difference <= $tolerance) {
            return 1.0;
        } else {
            return max(0.0, 1.0 - ($difference / $tolerance));
        }
    }

    /**
     * Calculates customer match score.
     * 
     * @param array $squareCustomer Square transaction customer
     * @param array $faCustomer FA transaction customer
     * @return float Match score
     */
    private function calculateCustomerMatch(array $squareCustomer, array $faCustomer): float
    {
        // Exact match
        if ($squareCustomer['id'] == $faCustomer['id']) {
            return 1.0;
        }
        
        // Name match (with fuzzy matching)
        $nameScore = $this->calculateNameMatch($squareCustomer['name'], $faCustomer['name']);
        
        // Email match
        $emailScore = $this->calculateEmailMatch($squareCustomer['email'] ?? '', $faCustomer['email'] ?? '');
        
        // Phone match
        $phoneScore = $this->calculatePhoneMatch($squareCustomer['phone_number'] ?? '', $faCustomer['phone_number'] ?? '');
        
        return ($nameScore + $emailScore + $phoneScore) / 3;
    }

    /**
     * Calculates date match score.
     * 
     * @param int $squareDate Square transaction date
     * @param int $faDate FA transaction date
     * @return float Match score
     */
    private function calculateDateMatch(int $squareDate, int $faDate): float
    {
        $difference = abs($squareDate - $faDate);
        $tolerance = 24 * 60 * 60; // 24 hours
        
        if ($difference <= $tolerance) {
            return 1.0;
        } else {
            return max(0.0, 1.0 - ($difference / $tolerance));
        }
    }

    /**
     * Calculates reference match score.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransaction FA transaction data
     * @return float Match score
     */
    private function calculateReferenceMatch(array $squareTransaction, array $faTransaction): float
    {
        // Check for reference number match
        if (isset($squareTransaction['reference_number']) && isset($faTransaction['reference_number'])) {
            if ($squareTransaction['reference_number'] == $faTransaction['reference_number']) {
                return 1.0;
            }
        }
        
        // Check for order ID match
        if (isset($squareTransaction['order_id']) && isset($faTransaction['order_id'])) {
            if ($squareTransaction['order_id'] == $faTransaction['order_id']) {
                return 1.0;
            }
        }
        
        return 0.0;
    }

    /**
     * Calculates name match score (fuzzy matching).
     * 
     * @param string $squareName Square customer name
     * @param string $faName FA customer name
     * @return float Match score
     */
    private function calculateNameMatch(string $squareName, string $faName): float
    {
        // Normalize names
        $normalizedSquare = $this->normalizeName($squareName);
        $normalizedFa = $this->normalizeName($faName);
        
        // Exact match
        if ($normalizedSquare == $normalizedFa) {
            return 1.0;
        }
        
        // Similarity score
        $similarity = similar_text($normalizedSquare, $normalizedFa) / max(strlen($normalizedSquare), strlen($normalizedFa));
        
        return $similarity;
    }

    /**
     * Calculates email match score.
     * 
     * @param string $squareEmail Square customer email
     * @param string $faEmail FA customer email
     * @return float Match score
     */
    private function calculateEmailMatch(string $squareEmail, string $faEmail): float
    {
        if (empty($squareEmail) || empty($faEmail)) {
            return 0.0;
        }
        
        // Exact match
        if ($squareEmail == $faEmail) {
            return 1.0;
        }
        
        // Domain match
        $squareDomain = explode('@', $squareEmail)[1] ?? '';
        $faDomain = explode('@', $faEmail)[1] ?? '';
        
        if ($squareDomain == $faDomain) {
            return 0.8;
        }
        
        return 0.0;
    }

    /**
     * Calculates phone match score.
     * 
     * @param string $squarePhone Square customer phone
     * @param string $faPhone FA customer phone
     * @return float Match score
     */
    private function calculatePhoneMatch(string $squarePhone, string $faPhone): float
    {
        if (empty($squarePhone) || empty($faPhone)) {
            return 0.0;
        }
        
        // Normalize phone numbers
        $normalizedSquare = $this->normalizePhone($squarePhone);
        $normalizedFa = $this->normalizePhone($faPhone);
        
        // Exact match
        if ($normalizedSquare == $normalizedFa) {
            return 1.0;
        }
        
        // Partial match (last 7 digits)
        if (substr($normalizedSquare, -7) == substr($normalizedFa, -7)) {
            return 0.7;
        }
        
        return 0.0;
    }

    /**
     * Normalizes name for comparison.
     * 
     * @param string $name Name to normalize
     * @return string Normalized name
     */
    private function normalizeName(string $name): string
    {
        // Remove extra whitespace, convert to lowercase
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * Normalizes phone number for comparison.
     * 
     * @param string $phone Phone number to normalize
     * @return string Normalized phone number
     */
    private function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Determines match type.
     * 
     * @param float $matchScore Match score
     * @return string Match type
     */
    private function determineMatchType(float $matchScore): string
    {
        if ($matchScore >= $this->config['auto_approve_threshold']) {
            return self::MATCH_TYPE_EXACT;
        } elseif ($matchScore >= $this->config['review_threshold']) {
            return self::MATCH_TYPE_FUZZY;
        } else {
            return self::MATCH_TYPE_PARTIAL;
        }
    }

    /**
     * Gets match details.
     * 
     * @param array $squareTransaction Square transaction data
     * @param array $faTransaction FA transaction data
     * @return array Match details
     */
    private function getMatchDetails(array $squareTransaction, array $faTransaction): array
    {
        return [
            'amount_difference' => $squareTransaction['amount'] - $faTransaction['amount'],
            'date_difference' => $squareTransaction['created_at'] - $faTransaction['created_at'],
            'customer_match' => $squareTransaction['customer']['id'] == $faTransaction['customer']['id'] ? 'exact' : 'partial',
            'reference_match' => isset($squareTransaction['reference_number']) && isset($faTransaction['reference_number']) ? 
                ($squareTransaction['reference_number'] == $faTransaction['reference_number'] ? 'exact' : 'none') : 'none'
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
            'fa_transaction_id' => $match['potential_matches'][0]['fa_transaction_id'],
            'approved_by' => $match['approved_by'],
            'approved_at' => $match['approved_at'],
            'confidence' => $match['confidence'],
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
            'rejected_by' => $match['rejected_by'],
            'rejected_at' => $match['rejected_at'],
            'reason' => $match['rejection_reason'],
            'confidence' => $match['confidence'],
            'message' => 'Match rejected successfully'
        ];
    }

    /**
     * Initializes match rules.
     */
    private function initializeMatchRules(): void
    {
        $this->matchRules = [
            'amount_tolerance' => 0.01, // 1%
            'date_tolerance' => 24 * 60 * 60, // 24 hours
            'customer_similarity_threshold' => 0.8,
            'email_domain_weight' => 0.3,
            'phone_number_weight' => 0.2,
            'reference_number_weight' => 0.3
        ];
    }

    /**
     * Adds to matching queue.
     * 
     * @param array $match Match data
     */
    private function addToMatchingQueue(array $match): void
    {
        $this->matchingQueue[] = $match;
    }

    /**
     * Removes from matching queue.
     * 
     * @param string $matchId Match ID
     */
    private function removeFromMatchingQueue(string $matchId): void
    {
        $this->matchingQueue = array_filter($this->matchingQueue, fn($m) => $m['match_id'] != $matchId);
    }

    /**
     * Adds to matching history.
     * 
     * @param array $match Match data
     */
    private function addToMatchingHistory(array $match): void
    {
        $this->matchingHistory[] = $match;
    }

    /**
     * Finds match by ID.
     * 
     * @param string $matchId Match ID
     * @return array|null Match or null
     */
    private function findMatchById(string $matchId): ?array
    {
        foreach ($this->matchingQueue as $match) {
            if ($match['match_id'] == $matchId) {
                return $match;
            }
        }
        return null;
    }

    /**
     * Logs match.
     * 
     * @param array $match Match data
     */
    private function logMatch(array $match): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Match ID: %s, Square: %s, FA: %s, Type: %s, Confidence: %.2f, Status: %s\n",
            date('Y-m-d H:i:s'),
            'MATCH',
            $match['match_id'],
            $match['square_transaction_id'],
            $match['potential_matches'][0]['fa_transaction_id'] ?? 'none',
            $match['match_type'],
            $match['confidence'],
            $match['status']
        );
        
        file_put_contents($this->config['matching_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Calculates average confidence.
     * 
     * @return float Average confidence
     */
    private function calculateAverageConfidence(): float
    {
        if (empty($this->matchingHistory)) {
            return 0.0;
        }
        
        $totalConfidence = array_sum(array_column($this->matchingHistory, 'confidence'));
        return $totalConfidence / count($this->matchingHistory);
    }

    /**
     * Calculates match success rate.
     * 
     * @return float Success rate
     */
    private function calculateMatchSuccessRate(): float
    {
        if (empty($this->matchingHistory)) {
            return 0.0;
        }
        
        $successfulMatches = count(array_filter($this->matchingHistory, fn($h) => $h['status'] === self::MATCH_STATUS_MATCHED));
        return $successfulMatches / count($this->matchingHistory);
    }

    /**
     * Counts matches by type.
     * 
     * @return array Counts by type
     */
    private function countMatchesByType(): array
    {
        $counts = [];
        
        foreach ($this->matchingHistory as $match) {
            $type = $match['match_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Counts matches by day.
     * 
     * @return array Counts by day
     */
    private function countMatchesByDay(): array
    {
        $counts = [];
        
        foreach ($this->matchingHistory as $match) {
            $day = date('Y-m-d', $match['created_at']);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Gets queue summary.
     * 
     * @param array $filters Filter parameters
     * @return array Queue summary
     */
    private function getQueueSummary(array $filters = []): array
    {
        $queue = $this->getMatchingQueue($filters);
        
        return [
            'total_matches' => count($queue),
            'pending_matches' => count(array_filter($queue, fn($m) => $m['status'] === self::MATCH_STATUS_PENDING)),
            'review_matches' => count(array_filter($queue, fn($m) => $m['status'] === self::MATCH_STATUS_REVIEW)),
            'average_confidence' => count($queue) > 0 ? array_sum(array_column($queue, 'confidence')) / count($queue) : 0.0
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
        $history = $this->getMatchingHistory($filters);
        
        return [
            'total_matches' => count($history),
            'matched_transactions' => count(array_filter($history, fn($h) => $h['status'] === self::MATCH_STATUS_MATCHED)),
            'rejected_transactions' => count(array_filter($history, fn($h) => $h['status'] === self::MATCH_STATUS_REJECTED)),
            'average_confidence' => count($history) > 0 ? array_sum(array_column($history, 'confidence')) / count($history) : 0.0
        ];
    }

    /**
     * Generates recommendations.
     * 
     * @return array Recommendations
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        // Queue recommendations
        $pendingMatches = count(array_filter($this->matchingQueue, fn($m) => $m['status'] === self::MATCH_STATUS_PENDING));
        if ($pendingMatches > 100) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'queue_management',
                'message' => 'High number of pending matches. Consider reviewing matching algorithms.'
            ];
        }
        
        // Confidence recommendations
        $avgConfidence = $this->calculateAverageConfidence();
        if ($avgConfidence < 0.8) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'confidence_improvement',
                'message' => 'Low average confidence. Consider adjusting matching rules.'
            ];
        }
        
        // Success rate recommendations
        $successRate = $this->calculateMatchSuccessRate();
        if ($successRate < 0.9) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'success_rate',
                'message' => 'Low match success rate. Consider reviewing match criteria.'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Gets performance metrics.
     * 
     * @return array Performance metrics
     */
    private function getPerformanceMetrics(): array
    {
        return [
            'average_processing_time' => $this->calculateAverageProcessingTime(),
            'throughput' => $this->calculateThroughput(),
            'accuracy' => $this->calculateAccuracy(),
            'latency' => $this->calculateLatency()
        ];
    }

    /**
     * Calculates average processing time.
     * 
     * @return float Average processing time in seconds
     */
    private function calculateAverageProcessingTime(): float
    {
        // This would be implemented with actual timing logic
        return 2.5;
    }

    /**
     * Calculates throughput.
     * 
     * @return float Throughput per hour
     */
    private function calculateThroughput(): float
    {
        // This would be implemented with actual throughput calculation
        return 150.0;
    }

    /**
     * Calculates accuracy.
     * 
     * @return float Accuracy percentage
     */
    private function calculateAccuracy(): float
    {
        // This would be implemented with actual accuracy calculation
        return 0.95;
    }

    /**
     * Calculates latency.
     * 
     * @return float Latency in milliseconds
     */
    private function calculateLatency(): float
    {
        // This would be implemented with actual latency calculation
        return 150.0;
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
     * Clears matching queue.
     */
    public function clearMatchingQueue(): void
    {
        $this->matchingQueue = [];
    }

    /**
     * Clears matching history.
     */
    public function clearMatchingHistory(): void
    {
        $this->matchingHistory = [];
    }
}