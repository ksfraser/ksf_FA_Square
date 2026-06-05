#!/usr/bin/env php
<?php
/**
 * Test Runner for Transaction Correction Facility
 * 
 * This script runs all tests for the Transaction Correction Facility
 * and generates a comprehensive test report.
 * 
 * @UML Note: Test automation script
 * @BABOK Related: Quality assurance, Testing, Validation
 */

declare(strict_types=1);

require_once __DIR__ . '/tests/Unit/TransactionCorrectionServiceTest.php';
require_once __DIR__ . '/tests/Integration/TransactionCorrectionIntegrationTest.php';

use PHPUnit\Framework\TestCase;
use Tests\Unit\TransactionCorrectionServiceTest as UnitTest;
use Tests\Integration\TransactionCorrectionIntegrationTest as IntegrationTest;

class TestRunner
{
    private array $testResults = [];
    private array $testStats = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'duration' => 0
    ];
    
    public function runAllTests(): void
    {
        echo "=== Transaction Correction Facility Test Runner ===\n";
        echo "Starting comprehensive test suite...\n\n";
        
        // Run unit tests
        $this->runUnitTests();
        
        // Run integration tests
        $this->runIntegrationTests();
        
        // Generate report
        $this->generateReport();
        
        echo "=== Test Runner Complete ===\n";
    }
    
    private function runUnitTests(): void
    {
        echo "Running Unit Tests...\n";
        
        $testCases = [
            'testCorrectDebtorAssignmentSquareTransaction' => 'Square transaction correction',
            'testCorrectDebtorAssignmentFaTransaction' => 'FA transaction correction',
            'testCorrectDebtorAssignmentWithAttachments' => 'Correction with attachments',
            'testInvalidTransactionId' => 'Invalid transaction ID validation',
            'testInvalidDebtorId' => 'Invalid debtor ID validation',
            'testCorrectionDisabled' => 'Correction disabled validation',
            'testTransactionTooOld' => 'Transaction age validation',
            'testDetermineTransactionSourceSquare' => 'Square source detection',
            'testDetermineTransactionSourceSquareImport' => 'Square import source detection',
            'testDetermineTransactionSourceFaGeneric' => 'FA generic source detection',
            'testCloneTransactionCart' => 'Cart cloning functionality',
            'testExtractAttachmentsFromCart' => 'Attachment extraction',
            'testCorrectionsLogging' => 'Logging functionality',
            'testInterfaceImplementation' => 'Interface compliance',
            'testServiceConfiguration' => 'Configuration management',
            'testTransactionHistoryTracking' => 'History tracking',
            'testCorrectionWithEmptyCorrectionData' => 'Empty correction data',
            'testCorrectionWithComplexCorrectionData' => 'Complex correction data'
        ];
        
        foreach ($testCases as $method => $description) {
            $this->runSingleTest($method, $description, 'unit');
        }
        
        echo "Unit Tests Complete\n\n";
    }
    
    private function runIntegrationTests(): void
    {
        echo "Running Integration Tests...\n";
        
        $testCases = [
            'testCompleteSquareTransactionCorrectionWorkflow' => 'Complete Square workflow',
            'testCompleteFaTransactionCorrectionWorkflow' => 'Complete FA workflow',
            'testCorrectionWithMultipleAttachments' => 'Multiple attachments',
            'testCorrectionErrorHandling' => 'Error handling',
            'testCorrectionWithTransactionGapDetection' => 'Gap detection',
            'testCorrectionWithComplexCorrectionData' => 'Complex data',
            'testCorrectionWithPaymentMethodChange' => 'Payment method change',
            'testCorrectionMultipleTimes' => 'Multiple corrections',
            'testCorrectionWithDateRange' => 'Date range',
            'testCorrectionPerformance' => 'Performance testing'
        ];
        
        foreach ($testCases as $method => $description) {
            $this->runSingleTest($method, $description, 'integration');
        }
        
        echo "Integration Tests Complete\n\n";
    }
    
    private function runSingleTest(string $method, string $description, string $type): void
    {
        $startTime = microtime(true);
        
        try {
            switch ($type) {
                case 'unit':
                    $test = new UnitTest();
                    break;
                case 'integration':
                    $test = new IntegrationTest();
                    break;
                default:
                    throw new \Exception("Unknown test type: $type");
            }
            
            // Set up the test
            $test->setUp();
            
            // Run the test
            $test->$method();
            
            // Tear down the test
            $test->tearDown();
            
            $result = [
                'status' => 'passed',
                'description' => $description,
                'type' => $type,
                'method' => $method,
                'duration' => microtime(true) - $startTime
            ];
            
            $this->testStats['passed']++;
            
        } catch (\Exception $e) {
            $result = [
                'status' => 'failed',
                'description' => $description,
                'type' => $type,
                'method' => $method,
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime
            ];
            
            $this->testStats['failed']++;
        }
        
        $this->testResults[] = $result;
        $this->testStats['total']++;
        $this->testStats['duration'] += $result['duration'];
        
        echo "  {$type}: {$description} - {$result['status']}";
        if ($result['status'] === 'failed') {
            echo " ({$result['error']})";
        }
        echo "\n";
    }
    
    private function generateReport(): void
    {
        echo "Generating Test Report...\n";
        
        $report = [
            'summary' => $this->testStats,
            'results' => $this->testResults,
            'recommendations' => $this->generateRecommendations()
        ];
        
        // Save report to file
        $reportFile = __DIR__ . '/test_report_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        
        // Display summary
        $this->displaySummary($report);
        
        // Display recommendations
        $this->displayRecommendations($report['recommendations']);
        
        echo "Report saved to: {$reportFile}\n";
    }
    
    private function displaySummary(array $report): void
    {
        echo "\n=== Test Summary ===\n";
        echo "Total Tests: {$report['summary']['total']}\n";
        echo "Passed: {$report['summary']['passed']}\n";
        echo "Failed: {$report['summary']['failed']}\n";
        echo "Skipped: {$report['summary']['skipped']}\n";
        echo "Total Duration: " . round($report['summary']['duration'], 2) . " seconds\n";
        echo "Average Duration: " . round($report['summary']['duration'] / $report['summary']['total'], 2) . " seconds\n";
        
        $passRate = ($report['summary']['passed'] / $report['summary']['total']) * 100;
        echo "Pass Rate: " . round($passRate, 2) . "%\n";
        
        echo "\n";
        
        // Display failed tests
        if ($report['summary']['failed'] > 0) {
            echo "=== Failed Tests ===\n";
            foreach ($report['results'] as $result) {
                if ($result['status'] === 'failed') {
                    echo "  {$result['type']}: {$result['description']}\n";
                    echo "    Error: {$result['error']}\n";
                    echo "    Duration: " . round($result['duration'], 2) . " seconds\n\n";
                }
            }
        }
    }
    
    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        if ($this->testStats['failed'] > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Quality Assurance',
                'message' => 'Fix failing tests before proceeding to production'
            ];
        }
        
        if ($this->testStats['passed'] / $this->testStats['total'] < 0.9) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Test Coverage',
                'message' => 'Increase test coverage to 90% or higher'
            ];
        }
        
        if ($this->testStats['duration'] / $this->testStats['total'] > 1.0) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Performance',
                'message' => 'Optimize test performance - average test duration should be < 1 second'
            ];
        }
        
        if ($this->testStats['failed'] === 0) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'Enhancement',
                'message' => 'Consider adding more comprehensive test cases'
            ];
        }
        
        return $recommendations;
    }
    
    private function displayRecommendations(array $recommendations): void
    {
        if (empty($recommendations)) {
            echo "No recommendations - all tests passed!\n";
            return;
        }
        
        echo "=== Recommendations ===\n";
        foreach ($recommendations as $recommendation) {
            echo "[{$recommendation['priority']}] {$recommendation['category']}: {$recommendation['message']}\n";
        }
        echo "\n";
    }
}

// Run the test runner
$runner = new TestRunner();
$runner->runAllTests();