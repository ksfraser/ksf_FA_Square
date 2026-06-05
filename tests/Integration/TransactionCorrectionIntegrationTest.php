<?php
declare(strict_types=1);

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use Ksfraser\Frontaccounting\SquareUp\Services\TransactionCorrectionService;
use Ksfraser\Frontaccounting\SquareUp\Services\TransactionReviewService;
use Ksfraser\Frontaccounting\SquareUp\Services\TransactionHistoryService;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\TransactionException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\DebtorException;

class TransactionCorrectionIntegrationTest extends TestCase
{
    private TransactionCorrectionService $correctionService;
    private TransactionReviewService $reviewService;
    private TransactionHistoryService $historyService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize services for integration testing
        $this->correctionService = new TransactionCorrectionService([
            'enable_correction' => true,
            'log_corrections' => true,
            'correction_log_file' => '/tmp/integration_corrections.log',
            'max_transaction_age' => 30 * 24 * 60 * 60, // 30 days
        ]);
        
        $this->reviewService = new TransactionReviewService([
            'enable_review' => true,
            'review_log_file' => '/tmp/integration_reviews.log',
        ]);
        
        $this->historyService = new TransactionHistoryService([
            'enable_history' => true,
            'history_log_file' => '/tmp/integration_history.log',
            'gap_detection' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test log files
        $logFiles = [
            '/tmp/integration_corrections.log',
            '/tmp/integration_reviews.log',
            '/tmp/integration_history.log'
        ];
        
        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        parent::tearDown();
    }

    public function testCompleteSquareTransactionCorrectionWorkflow(): void
    {
        // Step 1: Create a test Square transaction
        $transactionId = 1001;
        $newDebtorId = 2002;
        $correctionData = [
            'reason' => 'Customer name change',
            'payment_method' => 'credit_card',
            'reference_number' => 'REF-12345'
        ];
        
        $squareTransaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 1001,
            'invoice_id' => 1,
            'payment_id' => 1,
            'cart_items' => [
                [
                    'item_id' => 1,
                    'quantity' => 2,
                    'price' => 100,
                    'attachments' => [
                        [
                            'id' => 'att_001',
                            'filename' => 'invoice.pdf',
                            'file_path' => '/path/to/invoice.pdf',
                            'file_size' => 2048000,
                            'mime_type' => 'application/pdf',
                            'description' => 'Sales invoice'
                        ]
                    ]
                ]
            ],
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square',
            'import_date' => date('Y-m-d H:i:s')
        ];
        
        // Step 2: Verify transaction exists in review service
        $reviewResult = $this->reviewService->reviewTransaction($transactionId);
        $this->assertTrue($reviewResult['exists']);
        $this->assertEquals('square_staging', $reviewResult['source']);
        
        // Step 3: Perform correction
        $correctionResult = $this->correctionService->correctDebtorAssignment(
            $transactionId, 
            $newDebtorId, 
            $correctionData
        );
        
        // Step 4: Verify correction result
        $this->assertTrue($correctionResult['success']);
        $this->assertEquals('clone_void', $correctionResult['method']);
        $this->assertEquals($transactionId, $correctionResult['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $correctionResult['new_transaction']['debtor_id']);
        $this->assertEquals($correctionData, $correctionResult['correction_data']);
        
        // Step 5: Verify transaction history
        $historyResult = $this->historyService->getTransactionHistory($transactionId);
        $this->assertCount(1, $historyResult['corrections']);
        $this->assertEquals($transactionId, $historyResult['corrections'][0]['original_transaction_id']);
        $this->assertEquals($newDebtorId, $historyResult['corrections'][0]['new_debtor_id']);
        
        // Step 6: Verify logging
        $this->assertFileExists('/tmp/integration_corrections.log');
        $logContent = file_get_contents('/tmp/integration_corrections.log');
        $this->assertStringContainsString('SUCCESS', $logContent);
        $this->assertStringContainsString('Source: square_staging', $logContent);
        $this->assertStringContainsString((string)$transactionId, $logContent);
        $this->assertStringContainsString((string)$newDebtorId, $logContent);
    }

    public function testCompleteFaTransactionCorrectionWorkflow(): void
    {
        // Step 1: Create a test FA transaction
        $transactionId = 2001;
        $newDebtorId = 3003;
        $correctionData = [
            'reason' => 'Account reclassification',
            'payment_method' => 'cash',
            'reference_number' => 'FA-REF-12345'
        ];
        
        $faTransaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 2001,
            'invoice_id' => 2,
            'payment_id' => null,
            'cart_items' => [
                [
                    'item_id' => 1,
                    'quantity' => 3,
                    'price' => 150,
                    'attachments' => [
                        [
                            'id' => 'fa_att_001',
                            'filename' => 'contract.docx',
                            'file_path' => '/path/to/contract.docx',
                            'file_size' => 1024000,
                            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'description' => 'Sales contract'
                        ]
                    ]
                ]
            ],
            'total_amount' => 450,
            'created_at' => time(),
            'status' => 'posted',
            'source' => 'fa_generic'
        ];
        
        // Step 2: Verify transaction exists in review service
        $reviewResult = $this->reviewService->reviewTransaction($transactionId);
        $this->assertTrue($reviewResult['exists']);
        $this->assertEquals('fa_generic', $reviewResult['source']);
        
        // Step 3: Perform FA correction
        $correctionResult = $this->correctionService->correctFaTransaction(
            $transactionId, 
            $newDebtorId, 
            $correctionData
        );
        
        // Step 4: Verify correction result
        $this->assertTrue($correctionResult['success']);
        $this->assertEquals('fa_clone_void', $correctionResult['method']);
        $this->assertEquals($transactionId, $correctionResult['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $correctionResult['new_transaction']['debtor_id']);
        $this->assertEquals($correctionData, $correctionResult['correction_data']);
        
        // Step 5: Verify transaction history
        $historyResult = $this->historyService->getTransactionHistory($transactionId);
        $this->assertCount(1, $historyResult['corrections']);
        $this->assertEquals($transactionId, $historyResult['corrections'][0]['original_transaction_id']);
        $this->assertEquals($newDebtorId, $historyResult['corrections'][0]['new_debtor_id']);
        
        // Step 6: Verify logging
        $this->assertFileExists('/tmp/integration_corrections.log');
        $logContent = file_get_contents('/tmp/integration_corrections.log');
        $this->assertStringContainsString('SUCCESS', $logContent);
        $this->assertStringContainsString('Source: fa_generic', $logContent);
        $this->assertStringContainsString((string)$transactionId, $logContent);
        $this->assertStringContainsString((string)$newDebtorId, $logContent);
    }

    public function testCorrectionWithMultipleAttachments(): void
    {
        // Step 1: Create transaction with multiple attachments
        $transactionId = 3001;
        $newDebtorId = 4004;
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 3001,
            'invoice_id' => 3,
            'payment_id' => 3,
            'cart_items' => [
                [
                    'item_id' => 1,
                    'quantity' => 1,
                    'price' => 300,
                    'attachments' => [
                        [
                            'id' => 'att_001',
                            'filename' => 'invoice.pdf',
                            'file_path' => '/path/to/invoice.pdf',
                            'file_size' => 2048000,
                            'mime_type' => 'application/pdf',
                            'description' => 'Sales invoice'
                        ],
                        [
                            'id' => 'att_002',
                            'filename' => 'delivery_note.pdf',
                            'file_path' => '/path/to/delivery_note.pdf',
                            'file_size' => 1024000,
                            'mime_type' => 'application/pdf',
                            'description' => 'Delivery note'
                        ]
                    ]
                ],
                [
                    'item_id' => 2,
                    'quantity' => 1,
                    'price' => 200,
                    'attachments' => [
                        [
                            'id' => 'att_003',
                            'filename' => 'warranty.pdf',
                            'file_path' => '/path/to/warranty.pdf',
                            'file_size' => 512000,
                            'mime_type' => 'application/pdf',
                            'description' => 'Warranty certificate'
                        ]
                    ]
                ]
            ],
            'total_amount' => 500,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Step 2: Perform correction
        $correctionResult = $this->correctionService->correctDebtorAssignment(
            $transactionId, 
            $newDebtorId
        );
        
        // Step 3: Verify attachments were cloned properly
        $newCartItems = $correctionResult['new_transaction']['cart_items'];
        $this->assertCount(2, $newCartItems);
        
        // Verify first item attachments
        $item1Attachments = $newCartItems[0]['attachments'];
        $this->assertCount(2, $item1Attachments);
        $this->assertEquals('att_001', $item1Attachments[0]['cloned_from']);
        $this->assertEquals('att_002', $item1Attachments[1]['cloned_from']);
        
        // Verify second item attachments
        $item2Attachments = $newCartItems[1]['attachments'];
        $this->assertCount(1, $item2Attachments);
        $this->assertEquals('att_003', $item2Attachments[0]['cloned_from']);
        
        // Step 4: Verify all attachments have metadata
        foreach ($newCartItems as $item) {
            if (isset($item['attachments'])) {
                foreach ($item['attachments'] as $attachment) {
                    $this->assertArrayHasKey('original_transaction_id', $attachment);
                    $this->assertArrayHasKey('cloned_at', $attachment);
                    $this->assertArrayHasKey('reference_type', $attachment);
                    $this->assertEquals('attachment_clone', $attachment['reference_type']);
                }
            }
        }
    }

    public function testCorrectionErrorHandling(): void
    {
        // Test invalid transaction ID
        $this->expectException(DebtorException::class);
        $this->expectExceptionMessage("Invalid transaction ID");
        
        $this->correctionService->correctDebtorAssignment(0, 1002);
        
        // Test invalid debtor ID
        $this->expectException(DebtorException::class);
        $this->expectExceptionMessage("Invalid debtor ID");
        
        $this->correctionService->correctDebtorAssignment(1001, 0);
    }

    public function testCorrectionWithTransactionGapDetection(): void
    {
        // Step 1: Create first transaction
        $transactionId1 = 4001;
        $newDebtorId1 = 5002;
        
        $transaction1 = [
            'id' => $transactionId1,
            'type' => 'sales',
            'debtor_id' => 4001,
            'invoice_id' => 4,
            'payment_id' => 4,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 1, 'price' => 100]
            ],
            'total_amount' => 100,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Step 2: Create second transaction with different timing
        $transactionId2 = 4002;
        $newDebtorId2 = 5003;
        
        $transaction2 = [
            'id' => $transactionId2,
            'type' => 'sales',
            'debtor_id' => 4002,
            'invoice_id' => 5,
            'payment_id' => 5,
            'cart_items' => [
                ['item_id' => 2, 'quantity' => 1, 'price' => 200]
            ],
            'total_amount' => 200,
            'created_at' => time() + 3600, // 1 hour later
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Step 3: Perform corrections
        $result1 = $this->correctionService->correctDebtorAssignment($transactionId1, $newDebtorId1);
        $result2 = $this->correctionService->correctDebtorAssignment($transactionId2, $newDebtorId2);
        
        // Step 4: Verify gap detection
        $history = $this->historyService->getTransactionHistory($transactionId1);
        $this->assertNotEmpty($history['corrections']);
        
        $history2 = $this->historyService->getTransactionHistory($transactionId2);
        $this->assertNotEmpty($history2['corrections']);
        
        // Step 5: Verify no gaps detected in corrections
        $gapResult = $this->historyService->detectTransactionGaps($transactionId1, $transactionId2);
        $this->assertEquals(0, $gapResult['gap_count']);
    }

    public function testCorrectionWithComplexCorrectionData(): void
    {
        // Step 1: Create transaction with complex correction data
        $transactionId = 5001;
        $newDebtorId = 6002;
        $complexCorrectionData = [
            'reason' => 'Customer moved to different business unit',
            'payment_method' => 'credit_card',
            'reference_number' => 'REF-12345',
            'notes' => 'Updated debtor assignment due to organizational change',
            'effective_date' => date('Y-m-d'),
            'operator' => 'admin_user',
            'department' => 'sales',
            'cost_center' => 'sales_west',
            'project_code' => 'PROJ-001',
            'tax_code' => 'TAX-001',
            'discount_percent' => 0,
            'shipping_amount' => 0,
            'handling_amount' => 0,
            'insurance_amount' => 0,
            'custom_fields' => [
                'field1' => 'value1',
                'field2' => 'value2'
            ]
        ];
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 5001,
            'invoice_id' => 6,
            'payment_id' => 6,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 1, 'price' => 100]
            ],
            'total_amount' => 100,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Step 2: Perform correction with complex data
        $result = $this->correctionService->correctDebtorAssignment(
            $transactionId, 
            $newDebtorId, 
            $complexCorrectionData
        );
        
        // Step 3: Verify complex data was preserved
        $this->assertTrue($result['success']);
        $this->assertEquals($complexCorrectionData, $result['correction_data']);
        
        // Step 4: Verify custom fields were preserved
        $this->assertEquals('value1', $result['correction_data']['custom_fields']['field1']);
        $this->assertEquals('value2', $result['correction_data']['custom_fields']['field2']);
    }

    public function testCorrectionWithPaymentMethodChange(): void
    {
        // Step 1: Create transaction
        $transactionId = 6001;
        $newDebtorId = 7002;
        $correctionData = [
            'reason' => 'Payment method change',
            'payment_method' => 'bank_transfer'
        ];
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 6001,
            'invoice_id' => 7,
            'payment_id' => 7,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 1, 'price' => 150]
            ],
            'total_amount' => 150,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square',
            'payment_method' => 'credit_card'
        ];
        
        // Step 2: Perform correction with payment method change
        $result = $this->correctionService->correctDebtorAssignment(
            $transactionId, 
            $newDebtorId, 
            $correctionData
        );
        
        // Step 3: Verify payment method was updated
        $this->assertTrue($result['success']);
        $this->assertEquals($correctionData['payment_method'], $result['new_transaction']['payment_method']);
        
        // Step 4: Verify new payment was created
        $this->assertNotNull($result['new_transaction']['payment_id']);
        $this->assertEquals($correctionData['payment_method'], $result['new_payment']['payment_method']);
    }

    public function testCorrectionMultipleTimes(): void
    {
        // Step 1: Create transaction
        $transactionId = 7001;
        $newDebtorId1 = 8002;
        $newDebtorId2 = 8003;
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 7001,
            'invoice_id' => 8,
            'payment_id' => 8,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 1, 'price' => 200]
            ],
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Step 2: Perform first correction
        $result1 = $this->correctionService->correctDebtorAssignment($transactionId, $newDebtorId1);
        $this->assertTrue($result1['success']);
        
        // Step 3: Perform second correction on the new transaction
        $newTransactionId = $result1['new_transaction']['id'];
        $result2 = $this->correctionService->correctDebtorAssignment($newTransactionId, $newDebtorId2);
        $this->assertTrue($result2['success']);
        
        // Step 4: Verify both corrections were logged
        $history = $this->historyService->getTransactionHistory($transactionId);
        $this->assertCount(2, $history['corrections']);
        
        // Step 5: Verify the chain of corrections
        $this->assertEquals($transactionId, $history['corrections'][0]['original_transaction_id']);
        $this->assertEquals($newDebtorId1, $history['corrections'][0]['new_debtor_id']);
        
        $this->assertEquals($newTransactionId, $history['corrections'][1]['original_transaction_id']);
        $this->assertEquals($newDebtorId2, $history['corrections'][1]['new_debtor_id']);
    }

    public function testCorrectionWithDateRange(): void
    {
        // Step 1: Create multiple transactions with different dates
        $currentDate = date('Y-m-d');
        $transactions = [];
        
        for ($i = 1; $i <= 3; $i++) {
            $transactionId = 8000 + $i;
            $newDebtorId = 9000 + $i;
            
            $transaction = [
                'id' => $transactionId,
                'type' => 'sales',
                'debtor_id' => 8000 + $i,
                'invoice_id' => 8 + $i,
                'payment_id' => 8 + $i,
                'cart_items' => [
                    ['item_id' => $i, 'quantity' => 1, 'price' => 100 * $i]
                ],
                'total_amount' => 100 * $i,
                'created_at' => strtotime($currentDate . " -{$i} days"),
                'status' => 'processed',
                'source' => 'square'
            ];
            
            $transactions[$transactionId] = $transaction;
            
            // Perform correction
            $this->correctionService->correctDebtorAssignment($transactionId, $newDebtorId);
        }
        
        // Step 2: Verify date range detection
        $history = $this->historyService->getTransactionHistory(8001);
        $this->assertNotEmpty($history['corrections']);
        
        // Step 3: Verify all transactions were corrected
        foreach ($transactions as $transactionId => $transaction) {
            $correctionHistory = $this->historyService->getTransactionHistory($transactionId);
            $this->assertCount(1, $correctionHistory['corrections']);
        }
        
        // Step 4: Verify date range coverage
        $dateRange = $this->historyService->getDateRangeCoverage();
        $this->assertNotEmpty($dateRange);
        $this->assertArrayHasKey('start_date', $dateRange);
        $this->assertArrayHasKey('end_date', $dateRange);
        $this->assertArrayHasKey('transaction_count', $dateRange);
    }

    public function testCorrectionPerformance(): void
    {
        // Step 1: Create multiple transactions for performance testing
        $transactionCount = 10;
        $correctionResults = [];
        
        for ($i = 1; $i <= $transactionCount; $i++) {
            $transactionId = 9000 + $i;
            $newDebtorId = 10000 + $i;
            
            $transaction = [
                'id' => $transactionId,
                'type' => 'sales',
                'debtor_id' => 9000 + $i,
                'invoice_id' => 10 + $i,
                'payment_id' => 10 + $i,
                'cart_items' => [
                    ['item_id' => $i, 'quantity' => 1, 'price' => 100 * $i]
                ],
                'total_amount' => 100 * $i,
                'created_at' => time(),
                'status' => 'processed',
                'source' => 'square'
            ];
            
            $startTime = microtime(true);
            
            // Perform correction
            $result = $this->correctionService->correctDebtorAssignment($transactionId, $newDebtorId);
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            $correctionResults[] = [
                'transaction_id' => $transactionId,
                'success' => $result['success'],
                'execution_time' => $executionTime,
                'memory_usage' => memory_get_usage()
            ];
        }
        
        // Step 2: Verify all corrections were successful
        foreach ($correctionResults as $result) {
            $this->assertTrue($result['success']);
        }
        
        // Step 3: Verify performance metrics
        $totalTime = array_sum(array_column($correctionResults, 'execution_time'));
        $avgTime = $totalTime / $transactionCount;
        
        // Average should be less than 1 second per correction
        $this->assertLessThan(1.0, $avgTime);
        
        // Memory usage should be reasonable
        $maxMemoryUsage = max(array_column($correctionResults, 'memory_usage'));
        $this->assertLessThan(50 * 1024 * 1024, $maxMemoryUsage); // Less than 50MB
        
        // Step 4: Log performance summary
        $performanceSummary = [
            'total_transactions' => $transactionCount,
            'total_execution_time' => $totalTime,
            'average_execution_time' => $avgTime,
            'max_memory_usage' => $maxMemoryUsage,
            'timestamp' => time()
        ];
        
        $this->assertNotEmpty($performanceSummary);
    }
}