<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Transaction History Service
 * 
 * Handles transaction history tracking and gap detection.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class TransactionHistoryService
{
    private array $config;
    private array $transactionHistory = [];
    private array $gapDetection = [];
    private array $importHistory = [];
    private const MAX_HISTORY_RETENTION_DAYS = 365;
    const GAP_STATUS_UNDETECTED = 'undetected';
    const GAP_STATUS_DETECTED = 'detected';
    const GAP_STATUS_INVESTIGATING = 'investigating';
    const GAP_STATUS_RESOLVED = 'resolved';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_history_tracking' => true,
            'enable_gap_detection' => true,
            'max_history_retention_days' => self::MAX_HISTORY_RETENTION_DAYS,
            'gap_detection_threshold' => 24 * 60 * 60, // 24 hours
            'gap_tolerance' => 5 * 60, // 5 minutes
            'log_imports' => true,
            'history_log_file' => sys_get_temp_dir() . '/history.log'
        ], $config);
    }

    /**
     * Gets the full correction history for a transaction.
     * 
     * Follows the correction chain from the original transaction through any
     * subsequent corrections performed on the corrected transactions.
     * 
     * @param int $transactionId Transaction ID
     * @return array Correction history
     */
    public function getTransactionHistory(int $transactionId): array
    {
        $records = $this->readCorrectionRecords();
        $corrections = [];
        $currentId = $transactionId;
        $visited = [];
        
        while (true) {
            if (in_array($currentId, $visited, true)) {
                break;
            }
            $visited[] = $currentId;
            
            $next = null;
            foreach ($records as $record) {
                if ((int)$record['original_id'] === $currentId) {
                    $corrections[] = $this->correctionToEntry($record);
                    $next = (int)$record['new_id'];
                    break;
                }
            }
            
            if ($next === null) {
                break;
            }
            
            $currentId = $next;
        }
        
        return [
            'transaction_id' => $transactionId,
            'corrections' => $corrections,
            'correction_count' => count($corrections),
            'timestamp' => time()
        ];
    }

    /**
     * Detects gaps between two transactions' correction history.
     * 
     * @param int $fromTransactionId Start transaction ID
     * @param int $toTransactionId End transaction ID
     * @return array Gap detection results
     */
    public function detectTransactionGaps(int $fromTransactionId, int $toTransactionId): array
    {
        $fromHistory = $this->getTransactionHistory($fromTransactionId);
        $toHistory = $this->getTransactionHistory($toTransactionId);
        
        $gapCount = 0;
        if (empty($fromHistory['corrections']) || empty($toHistory['corrections'])) {
            $gapCount = 1;
        }
        
        return [
            'from_transaction_id' => $fromTransactionId,
            'to_transaction_id' => $toTransactionId,
            'gap_count' => $gapCount,
            'from_correction_count' => $fromHistory['correction_count'],
            'to_correction_count' => $toHistory['correction_count'],
            'timestamp' => time()
        ];
    }

    /**
     * Gets correction coverage over the tracked date range.
     * 
     * @return array Date range coverage
     */
    public function getDateRangeCoverage(): array
    {
        $records = $this->readCorrectionRecords();
        
        if (empty($records)) {
            return [
                'start_date' => null,
                'end_date' => null,
                'transaction_count' => 0,
                'timestamp' => time()
            ];
        }
        
        $timestamps = array_column($records, 'timestamp');
        $minTimestamp = min($timestamps);
        $maxTimestamp = max($timestamps);
        
        return [
            'start_date' => date('Y-m-d', $minTimestamp),
            'end_date' => date('Y-m-d', $maxTimestamp),
            'transaction_count' => count($records),
            'timestamp' => time()
        ];
    }

    /**
     * Reads all correction records from the shared correction ledger.
     * 
     * @return array Correction records
     */
    private function readCorrectionRecords(): array
    {
        $path = sys_get_temp_dir() . '/ksf_corrections.jsonl';
        
        if (!file_exists($path)) {
            return [];
        }
        
        $records = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        
        return $records;
    }

    /**
     * Maps a correction ledger record to a history entry.
     * 
     * @param array $record Correction ledger record
     * @return array Correction history entry
     */
    private function correctionToEntry(array $record): array
    {
        $timestamp = (int)($record['timestamp'] ?? time());
        
        return [
            'original_transaction_id' => (int)$record['original_id'],
            'new_debtor_id' => (int)$record['new_debtor'],
            'corrected_transaction_id' => (int)$record['new_id'],
            'method' => $record['method'] ?? 'unknown',
            'source' => $record['source'] ?? 'unknown',
            'success' => (bool)($record['success'] ?? true),
            'timestamp' => $timestamp,
            'correction_date' => date('Y-m-d H:i:s', $timestamp)
        ];
    }

    /**
     * Records transaction history.
     * 
     * @param array $transactionData Transaction data
     * @return array Record result
     */
    public function recordTransactionHistory(array $transactionData): array
    {
        try {
            // Validate transaction data
            $this->validateTransactionData($transactionData);
            
            // Create history entry
            $historyEntry = $this->createHistoryEntry($transactionData);
            
            // Add to history
            $this->addToHistory($historyEntry);
            
            // Log record
            $this->logRecord($historyEntry);
            
            return $historyEntry;
        } catch (\Exception $e) {
            throw new \Exception("Transaction history recording failed: " . $e->getMessage());
        }
    }

    /**
     * Detects import gaps.
     * 
     * @param array $importData Import data
     * @return array Gap detection results
     */
    public function detectImportGaps(array $importData): array
    {
        try {
            // Get import date range
            $dateRange = $this->getImportDateRange($importData);
            
            // Get existing import history
            $existingImports = $this->getExistingImports($dateRange);
            
            // Detect gaps
            $gaps = $this->findDateGaps($dateRange, $existingImports);
            
            // Create gap entries
            $gapEntries = $this->createGapEntries($gaps, $importData);
            
            // Add to gap detection
            foreach ($gapEntries as $gapEntry) {
                $this->addToGapDetection($gapEntry);
            }
            
            return [
                'success' => true,
                'date_range' => $dateRange,
                'existing_imports' => $existingImports,
                'gaps_detected' => count($gaps),
                'gap_entries' => $gapEntries,
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            throw new \Exception("Gap detection failed: " . $e->getMessage());
        }
    }

    /**
     * Records import history.
     * 
     * @param array $importData Import data
     * @return array Record result
     */
    public function recordImportHistory(array $importData): array
    {
        try {
            // Validate import data
            $this->validateImportData($importData);
            
            // Create import entry
            $importEntry = $this->createImportEntry($importData);
            
            // Add to import history
            $this->addToImportHistory($importEntry);
            
            // Log import
            if ($this->config['log_imports']) {
                $this->logImport($importEntry);
            }
            
            return $importEntry;
        } catch (\Exception $e) {
            throw new \Exception("Import history recording failed: " . $e->getMessage());
        }
    }

    /**
     * Gets transaction history by date range.
     * 
     * @param array $dateRange Date range
     * @param array $filters Filter parameters
     * @return array Transaction history
     */
    public function getTransactionHistoryByDateRange(array $dateRange, array $filters = []): array
    {
        $startTime = $dateRange['start'];
        $endTime = $dateRange['end'];
        
        $filteredHistory = [];
        
        foreach ($this->transactionHistory as $entry) {
            if ($entry['timestamp'] >= $startTime && $entry['timestamp'] <= $endTime) {
                $include = true;
                
                // Apply filters
                if (isset($filters['transaction_type'])) {
                    $include = $include && $entry['transaction_type'] == $filters['transaction_type'];
                }
                
                if (isset($filters['customer_id'])) {
                    $include = $include && $entry['customer_id'] == $filters['customer_id'];
                }
                
                if (isset($filters['status'])) {
                    $include = $include && $entry['status'] == $filters['status'];
                }
                
                if ($include) {
                    $filteredHistory[] = $entry;
                }
            }
        }
        
        return $filteredHistory;
    }

    /**
     * Gets import gaps.
     * 
     * @param array $filters Filter parameters
     * @return array Import gaps
     */
    public function getImportGaps(array $filters = []): array
    {
        $filteredGaps = $this->gapDetection;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredGaps = array_filter($filteredGaps, function ($g) use ($filters) {
                return $g['status'] == $filters['status'];
            });
        }
        
        if (isset($filters['priority'])) {
            $filteredGaps = array_filter($filteredGaps, function ($g) use ($filters) {
                return $g['priority'] == $filters['priority'];
            });
        }
        
        if (isset($filters['date_from'])) {
            $filteredGaps = array_filter($filteredGaps, function ($g) use ($filters) {
                return $g['start_time'] >= $filters['date_from'];
            });
        }
        
        if (isset($filters['date_to'])) {
            $filteredGaps = array_filter($filteredGaps, function ($g) use ($filters) {
                return $g['end_time'] <= $filters['date_to'];
            });
        }
        
        return array_values($filteredGaps);
    }

    /**
     * Gets import history.
     * 
     * @param array $filters Filter parameters
     * @return array Import history
     */
    public function getImportHistory(array $filters = []): array
    {
        $filteredHistory = $this->importHistory;
        
        // Apply filters
        if (isset($filters['import_type'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['import_type'] == $filters['import_type']);
        }
        
        if (isset($filters['source'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['source'] == $filters['source']);
        }
        
        if (isset($filters['status'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['status'] == $filters['status']);
        }
        
        if (isset($filters['date_from'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['timestamp'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['timestamp'] <= $filters['date_to']);
        }
        
        return array_values($filteredHistory);
    }

    /**
     * Updates gap status.
     * 
     * @param string $gapId Gap ID
     * @param string $status New status
     * @param array $updateData Update data
     * @return array Update result
     */
    public function updateGapStatus(string $gapId, string $status, array $updateData = []): array
    {
        try {
            // Find gap
            $gap = $this->findGapById($gapId);
            
            if (!$gap) {
                throw new \Exception("Gap not found");
            }
            
            // Update status
            $gap['status'] = $status;
            $gap['updated_at'] = time();
            
            if (!empty($updateData)) {
                $gap['update_data'] = $updateData;
            }
            
            // Update priority if needed
            if ($status === self::GAP_STATUS_RESOLVED) {
                $gap['priority'] = 'low';
            }
            
            return [
                'success' => true,
                'gap_id' => $gapId,
                'status' => $status,
                'updated_at' => $gap['updated_at'],
                'message' => 'Gap status updated successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Gap status update failed: " . $e->getMessage());
        }
    }

    /**
     * Generates history report.
     * 
     * @param array $filters Filter parameters
     * @return array History report
     */
    public function generateHistoryReport(array $filters = []): array
    {
        $report = [
            'generated_at' => time(),
            'filters' => $filters,
            'transaction_summary' => $this->getTransactionSummary($filters),
            'import_summary' => $this->getImportSummary($filters),
            'gap_summary' => $this->getGapSummary($filters),
            'statistics' => $this->getHistoryStatistics(),
            'recommendations' => $this->generateRecommendations()
        ];
        
        return $report;
    }

    /**
     * Validates transaction data.
     * 
     * @param array $transactionData Transaction data
     * @throws \Exception on validation failure
     */
    private function validateTransactionData(array $transactionData): void
    {
        if (empty($transactionData)) {
            throw new \Exception("Transaction data is required");
        }
        
        if (!isset($transactionData['transaction_id'])) {
            throw new \Exception("Transaction ID is required");
        }
        
        if (!isset($transactionData['transaction_type'])) {
            throw new \Exception("Transaction type is required");
        }
        
        if (!isset($transactionData['timestamp'])) {
            throw new \Exception("Timestamp is required");
        }
    }

    /**
     * Validates import data.
     * 
     * @param array $importData Import data
     * @throws \Exception on validation failure
     */
    private function validateImportData(array $importData): void
    {
        if (empty($importData)) {
            throw new \Exception("Import data is required");
        }
        
        if (!isset($importData['import_id'])) {
            throw new \Exception("Import ID is required");
        }
        
        if (!isset($importData['import_type'])) {
            throw new \Exception("Import type is required");
        }
        
        if (!isset($importData['source'])) {
            throw new \Exception("Source is required");
        }
        
        if (!isset($importData['start_time'])) {
            throw new \Exception("Start time is required");
        }
        
        if (!isset($importData['end_time'])) {
            throw new \Exception("End time is required");
        }
    }

    /**
     * Creates history entry.
     * 
     * @param array $transactionData Transaction data
     * @return array History entry
     */
    private function createHistoryEntry(array $transactionData): array
    {
        $entry = [
            'history_id' => uniqid('history_'),
            'transaction_id' => $transactionData['transaction_id'],
            'transaction_type' => $transactionData['transaction_type'],
            'timestamp' => $transactionData['timestamp'],
            'data' => $transactionData,
            'metadata' => $transactionData['metadata'] ?? [],
            'created_at' => time()
        ];
        
        return $entry;
    }

    /**
     * Creates import entry.
     * 
     * @param array $importData Import data
     * @return array Import entry
     */
    private function createImportEntry(array $importData): array
    {
        $entry = [
            'import_id' => $importData['import_id'],
            'import_type' => $importData['import_type'],
            'source' => $importData['source'],
            'start_time' => $importData['start_time'],
            'end_time' => $importData['end_time'],
            'status' => $importData['status'] ?? 'completed',
            'record_count' => $importData['record_count'] ?? 0,
            'error_count' => $importData['error_count'] ?? 0,
            'data' => $importData,
            'metadata' => $importData['metadata'] ?? [],
            'created_at' => time()
        ];
        
        return $entry;
    }

    /**
     * Creates gap entry.
     * 
     * @param array $gap Gap data
     * @param array $importData Import data
     * @return array Gap entry
     */
    private function createGapEntry(array $gap, array $importData): array
    {
        $priority = $this->calculateGapPriority($gap);
        
        $entry = [
            'gap_id' => uniqid('gap_'),
            'import_id' => $importData['import_id'],
            'start_time' => $gap['start'],
            'end_time' => $gap['end'],
            'duration' => $gap['end'] - $gap['start'],
            'priority' => $priority,
            'status' => self::GAP_STATUS_DETECTED,
            'detected_at' => time(),
            'gap_data' => $gap,
            'import_data' => $importData,
            'metadata' => $importData['metadata'] ?? []
        ];
        
        return $entry;
    }

    /**
     * Creates gap entries.
     * 
     * @param array $gaps Gap data
     * @param array $importData Import data
     * @return array Gap entries
     */
    private function createGapEntries(array $gaps, array $importData): array
    {
        $entries = [];
        
        foreach ($gaps as $gap) {
            $entry = $this->createGapEntry($gap, $importData);
            $entries[] = $entry;
        }
        
        return $entries;
    }

    /**
     * Calculates gap priority.
     * 
     * @param array $gap Gap data
     * @return string Priority level
     */
    private function calculateGapPriority(array $gap): string
    {
        $duration = $gap['end'] - $gap['start'];
        
        if ($duration > 7 * 24 * 60 * 60) { // 7 days
            return 'critical';
        } elseif ($duration > 24 * 60 * 60) { // 1 day
            return 'high';
        } elseif ($duration > 6 * 60 * 60) { // 6 hours
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Gets import date range.
     * 
     * @param array $importData Import data
     * @return array Date range
     */
    private function getImportDateRange(array $importData): array
    {
        return [
            'start' => $importData['start_time'],
            'end' => $importData['end_time']
        ];
    }

    /**
     * Gets existing imports.
     * 
     * @param array $dateRange Date range
     * @return array Existing imports
     */
    private function getExistingImports(array $dateRange): array
    {
        $existing = [];
        
        foreach ($this->importHistory as $import) {
            if ($this->importsOverlap($import, $dateRange)) {
                $existing[] = $import;
            }
        }
        
        return $existing;
    }

    /**
     * Finds date gaps.
     * 
     * @param array $dateRange Date range
     * @param array $existingImports Existing imports
     * @return array Gaps
     */
    private function findDateGaps(array $dateRange, array $existingImports): array
    {
        $gaps = [];
        
        // Sort imports by start time
        usort($existingImports, fn($a, $b) => $a['start_time'] <=> $b['start_time']);
        
        // Check for gaps between imports
        for ($i = 0; $i < count($existingImports) - 1; $i++) {
            $current = $existingImports[$i];
            $next = $existingImports[$i + 1];
            
            $gapStart = $current['end_time'];
            $gapEnd = $next['start_time'];
            
            // Check if gap exists and is significant
            if ($gapEnd - $gapStart > $this->config['gap_tolerance']) {
                $gaps[] = [
                    'start' => $gapStart,
                    'end' => $gapEnd,
                    'duration' => $gapEnd - $gapStart
                ];
            }
        }
        
        // Check for gaps before first import
        if (!empty($existingImports)) {
            $firstImport = $existingImports[0];
            if ($firstImport['start_time'] - $dateRange['start'] > $this->config['gap_tolerance']) {
                $gaps[] = [
                    'start' => $dateRange['start'],
                    'end' => $firstImport['start_time'],
                    'duration' => $firstImport['start_time'] - $dateRange['start']
                ];
            }
        }
        
        // Check for gaps after last import
        if (!empty($existingImports)) {
            $lastImport = end($existingImports);
            if ($dateRange['end'] - $lastImport['end_time'] > $this->config['gap_tolerance']) {
                $gaps[] = [
                    'start' => $lastImport['end_time'],
                    'end' => $dateRange['end'],
                    'duration' => $dateRange['end'] - $lastImport['end_time']
                ];
            }
        }
        
        return $gaps;
    }

    /**
     * Checks if imports overlap.
     * 
     * @param array $import1 Import 1
     * @param array $import2 Import 2
     * @return bool True if imports overlap
     */
    private function importsOverlap(array $import1, array $import2): bool
    {
        return $import1['start_time'] < $import2['end'] && $import1['end_time'] > $import2['start'];
    }

    /**
     * Adds to history.
     * 
     * @param array $entry History entry
     */
    private function addToHistory(array $entry): void
    {
        $this->transactionHistory[] = $entry;
        
        // Clean old history
        $this->cleanOldHistory();
    }

    /**
     * Adds to import history.
     * 
     * @param array $entry Import entry
     */
    private function addToImportHistory(array $entry): void
    {
        $this->importHistory[] = $entry;
    }

    /**
     * Adds to gap detection.
     * 
     * @param array $entry Gap entry
     */
    private function addToGapDetection(array $entry): void
    {
        $this->gapDetection[] = $entry;
        
        // Log gap detection
        $this->logGapDetection($entry);
    }

    /**
     * Finds gap by ID.
     * 
     * @param string $gapId Gap ID
     * @return array|null Gap or null
     */
    private function findGapById(string $gapId): ?array
    {
        foreach ($this->gapDetection as $gap) {
            if ($gap['gap_id'] == $gapId) {
                return $gap;
            }
        }
        return null;
    }

    /**
     * Logs record.
     * 
     * @param array $entry History entry
     */
    private function logRecord(array $entry): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Transaction ID: %s, Type: %s, Timestamp: %s\n",
            date('Y-m-d H:i:s'),
            'RECORD',
            $entry['transaction_id'],
            $entry['transaction_type'],
            date('Y-m-d H:i:s', $entry['timestamp'])
        );
        
        file_put_contents($this->config['history_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Logs import.
     * 
     * @param array $entry Import entry
     */
    private function logImport(array $entry): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Import ID: %s, Type: %s, Source: %s, Records: %d, Errors: %d\n",
            date('Y-m-d H:i:s'),
            'IMPORT',
            $entry['import_id'],
            $entry['import_type'],
            $entry['source'],
            $entry['record_count'],
            $entry['error_count']
        );
        
        file_put_contents($this->config['history_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Logs gap detection.
     * 
     * @param array $entry Gap entry
     */
    private function logGapDetection(array $entry): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Gap ID: %s, Start: %s, End: %s, Duration: %s, Priority: %s\n",
            date('Y-m-d H:i:s'),
            'GAP_DETECTED',
            $entry['gap_id'],
            date('Y-m-d H:i:s', $entry['start_time']),
            date('Y-m-d H:i:s', $entry['end_time']),
            $entry['duration'],
            $entry['priority']
        );
        
        file_put_contents($this->config['history_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Gets transaction summary.
     * 
     * @param array $filters Filter parameters
     * @return array Transaction summary
     */
    private function getTransactionSummary(array $filters = []): array
    {
        $history = $this->getTransactionHistoryByDateRange([
            'start' => time() - 30 * 24 * 60 * 60, // 30 days ago
            'end' => time()
        ], $filters);
        
        return [
            'total_transactions' => count($history),
            'transactions_by_type' => $this->countByType($history, 'transaction_type'),
            'transactions_by_status' => $this->countByStatus($history),
            'transactions_by_day' => $this->countByDay($history, 'timestamp'),
            'average_transactions_per_day' => count($history) / 30
        ];
    }

    /**
     * Gets import summary.
     * 
     * @param array $filters Filter parameters
     * @return array Import summary
     */
    private function getImportSummary(array $filters = []): array
    {
        $history = $this->getImportHistory($filters);
        
        return [
            'total_imports' => count($history),
            'imports_by_type' => $this->countByType($history, 'import_type'),
            'imports_by_source' => $this->countByType($history, 'source'),
            'imports_by_status' => $this->countByStatus($history),
            'imports_by_day' => $this->countByDay($history, 'timestamp'),
            'total_records_processed' => array_sum(array_column($history, 'record_count')),
            'total_errors' => array_sum(array_column($history, 'error_count'))
        ];
    }

    /**
     * Gets gap summary.
     * 
     * @param array $filters Filter parameters
     * @return array Gap summary
     */
    private function getGapSummary(array $filters = []): array
    {
        $gaps = $this->getImportGaps($filters);
        
        return [
            'total_gaps' => count($gaps),
            'gaps_by_status' => $this->countByStatus($gaps),
            'gaps_by_priority' => $this->countByType($gaps, 'priority'),
            'gaps_by_day' => $this->countByDay($gaps, 'detected_at'),
            'total_gap_duration' => array_sum(array_column($gaps, 'duration')),
            'average_gap_duration' => count($gaps) > 0 ? array_sum(array_column($gaps, 'duration')) / count($gaps) : 0
        ];
    }

    /**
     * Gets history statistics.
     * 
     * @return array History statistics
     */
    private function getHistoryStatistics(): array
    {
        return [
            'total_transactions' => count($this->transactionHistory),
            'total_imports' => count($this->importHistory),
            'total_gaps' => count($this->gapDetection),
            'active_gaps' => count(array_filter($this->gapDetection, fn($g) => $g['status'] === self::GAP_STATUS_DETECTED)),
            'resolved_gaps' => count(array_filter($this->gapDetection, fn($g) => $g['status'] === self::GAP_STATUS_RESOLVED)),
            'history_retention_days' => $this->config['max_history_retention_days']
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
        
        // Gap recommendations
        $activeGaps = count(array_filter($this->gapDetection, fn($g) => $g['status'] === self::GAP_STATUS_DETECTED));
        if ($activeGaps > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'gap_management',
                'message' => 'Active gaps detected. Consider investigating and resolving these gaps.'
            ];
        }
        
        // Import recommendations
        $recentImports = $this->getImportHistory([
            'date_from' => time() - 7 * 24 * 60 * 60, // 7 days ago
            'date_to' => time()
        ]);
        
        if (count($recentImports) === 0) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'import_frequency',
                'message' => 'No recent imports detected. Consider reviewing import schedule.'
            ];
        }
        
        // History retention recommendations
        if ($this->config['max_history_retention_days'] > 365) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'retention_policy',
                'message' => 'History retention period is longer than 1 year. Consider optimizing storage.'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Counts by type.
     * 
     * @param array $data Data to count
     * @param string $typeField Type field
     * @return array Counts by type
     */
    private function countByType(array $data, string $typeField): array
    {
        $counts = [];
        
        foreach ($data as $item) {
            $type = $item[$typeField] ?? 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Counts by status.
     * 
     * @param array $data Data to count
     * @return array Counts by status
     */
    private function countByStatus(array $data): array
    {
        $counts = [];
        
        foreach ($data as $item) {
            $status = $item['status'] ?? 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Counts by day.
     * 
     * @param array $data Data to count
     * @param string $dateField Date field
     * @return array Counts by day
     */
    private function countByDay(array $data, string $dateField): array
    {
        $counts = [];
        
        foreach ($data as $item) {
            $day = date('Y-m-d', $item[$dateField]);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Cleans old history.
     */
    private function cleanOldHistory(): void
    {
        $cutoffTime = time() - ($this->config['max_history_retention_days'] * 24 * 60 * 60);
        
        // Clean transaction history
        $this->transactionHistory = array_filter($this->transactionHistory, fn($h) => $h['timestamp'] >= $cutoffTime);
        
        // Clean import history
        $this->importHistory = array_filter($this->importHistory, fn($h) => $h['timestamp'] >= $cutoffTime);
        
        // Clean gap detection
        $this->gapDetection = array_filter($this->gapDetection, fn($g) => $g['detected_at'] >= $cutoffTime);
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
     * Clears transaction history.
     */
    public function clearTransactionHistory(): void
    {
        $this->transactionHistory = [];
    }

    /**
     * Clears import history.
     */
    public function clearImportHistory(): void
    {
        $this->importHistory = [];
    }

    /**
     * Clears gap detection.
     */
    public function clearGapDetection(): void
    {
        $this->gapDetection = [];
    }
}