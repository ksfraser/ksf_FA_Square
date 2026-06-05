<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Ksfraser\Frontaccounting\SquareUp\Services\TransactionCorrectionService;
use Ksfraser\Frontaccounting\SquareUp\Interfaces\TransactionCorrectionInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\TransactionException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\DebtorException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\CorrectionException;

class TransactionCorrectionServiceTest extends TestCase
{
    private TransactionCorrectionService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create service with test configuration
        $this->service = new TransactionCorrectionService([
            'enable_correction' => true,
            'log_corrections' => true,
            'correction_log_file' => '/tmp/test_corrections.log',
            'max_transaction_age' => 30 * 24 * 60 * 60, // 30 days
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test log file
        if (file_exists('/tmp/test_corrections.log')) {
            unlink('/tmp/test_corrections.log');
        }
        
        parent::tearDown();
    }

    public function testCorrectDebtorAssignmentSquareTransaction(): void
    {
        // Arrange
        $transactionId = 1001;
        $newDebtorId = 2002;
        $correctionData = [
            'reason' => 'Customer name change',
            'payment_method' => 'credit_card'
        ];
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 1001,
            'invoice_id' => 1,
            'payment_id' => 1,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 2, 'price' => 100],
                ['item_id' => 2, 'quantity' => 1, 'price' => 200]
            ],
            'total_amount' => 400,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Mock the getTransactionDetails method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Act
        $result = $this->service->correctDebtorAssignment($transactionId, $newDebtorId, $correctionData);
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('clone_void', $result['method']);
        $this->assertEquals($transactionId, $result['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $result['new_transaction']['debtor_id']);
        $this->assertEquals($correctionData, $result['correction_data']);
        
        // Verify transaction source was detected
        $this->assertEquals('square_staging', $result['original_transaction']['source']);
    }

    public function testCorrectDebtorAssignmentFaTransaction(): void
    {
        // Arrange
        $transactionId = 2001;
        $newDebtorId = 3003;
        $correctionData = [
            'reason' => 'Account reclassification',
            'payment_method' => 'cash'
        ];
        
        $transaction = [
            'id' => $transactionId,
            'type' => 'sales',
            'debtor_id' => 2001,
            'invoice_id' => 2,
            'payment_id' => null,
            'cart_items' => [
                ['item_id' => 1, 'quantity' => 3, 'price' => 150],
                ['item_id' => 2, 'quantity' => 2, 'price' => 250]
            ],
            'total_amount' => 950,
            'created_at' => time(),
            'status' => 'posted',
            'source' => 'fa_generic'
        ];
        
        // Mock the getTransactionDetails method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Act
        $result = $this->service->correctFaTransaction($transactionId, $newDebtorId, $correctionData);
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('fa_clone_void', $result['method']);
        $this->assertEquals($transactionId, $result['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $result['new_transaction']['debtor_id']);
        $this->assertEquals($correctionData, $result['correction_data']);
        
        // Verify transaction source was detected
        $this->assertEquals('fa_generic', $result['original_transaction']['source']);
    }

    public function testCorrectDebtorAssignmentWithAttachments(): void
    {
        // Arrange
        $transactionId = 3001;
        $newDebtorId = 4004;
        $correctionData = [
            'reason' => 'Customer data correction',
            'payment_method' => 'bank_transfer'
        ];
        
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
                            'filename' => 'contract.pdf',
                            'file_path' => '/path/to/contract.pdf',
                            'file_size' => 1024000,
                            'mime_type' => 'application/pdf',
                            'description' => 'Sales contract'
                        ]
                    ]
                ]
            ],
            'total_amount' => 300,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        // Mock the getTransactionDetails method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Act
        $result = $this->service->correctDebtorAssignment($transactionId, $newDebtorId, $correctionData);
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals($transactionId, $result['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $result['new_transaction']['debtor_id']);
        
        // Verify attachments were cloned
        $newCartItems = $result['new_transaction']['cart_items'];
        $this->assertCount(1, $newCartItems);
        $this->assertArrayHasKey('attachments', $newCartItems[0]);
        $this->assertCount(1, $newCartItems[0]['attachments']);
        
        // Verify attachment cloning metadata
        $clonedAttachment = $newCartItems[0]['attachments'][0];
        $this->assertArrayHasKey('original_transaction_id', $clonedAttachment);
        $this->assertArrayHasKey('cloned_at', $clonedAttachment);
        $this->assertArrayHasKey('reference_type', $clonedAttachment);
    }

    public function testInvalidTransactionId(): void
    {
        $this->expectException(DebtorException::class);
        $this->expectExceptionMessage("Invalid transaction ID");
        
        $this->service->correctDebtorAssignment(0, 1002);
    }

    public function testInvalidDebtorId(): void
    {
        $this->expectException(DebtorException::class);
        $this->expectExceptionMessage("Invalid debtor ID");
        
        $transactionId = 1001;
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        $this->service->correctDebtorAssignment($transactionId, 0);
    }

    public function testCorrectionDisabled(): void
    {
        $this->expectException(CorrectionException::class);
        $this->expectExceptionMessage("Transaction correction is disabled");
        
        $service = new TransactionCorrectionService([
            'enable_correction' => false,
            'log_corrections' => true,
            'correction_log_file' => '/tmp/test_corrections.log',
        ]);
        
        $service->correctDebtorAssignment(1001, 1002);
    }

    public function testTransactionTooOld(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage("Transaction cannot be corrected");
        
        $oldTimestamp = time() - 35 * 24 * 60 * 60; // 35 days ago
        $transaction = [
            'id' => 1001,
            'created_at' => $oldTimestamp,
            'status' => 'processed'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, 1001);
        
        $this->service->correctDebtorAssignment(1001, 1002);
    }

    public function testDetermineTransactionSourceSquare(): void
    {
        $transaction = [
            'id' => 1001,
            'source' => 'square'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineTransactionSource');
        $method->setAccessible(true);
        
        $source = $method->invoke($this->service, $transaction);
        $this->assertEquals('square_staging', $source);
    }

    public function testDetermineTransactionSourceSquareImport(): void
    {
        $transaction = [
            'id' => 1001,
            'import_id' => 'square_import_123'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineTransactionSource');
        $method->setAccessible(true);
        
        $source = $method->invoke($this->service, $transaction);
        $this->assertEquals('square_import', $source);
    }

    public function testDetermineTransactionSourceFaGeneric(): void
    {
        $transaction = [
            'id' => 1001,
            'type' => 'sales'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineTransactionSource');
        $method->setAccessible(true);
        
        $source = $method->invoke($this->service, $transaction);
        $this->assertEquals('fa_generic', $source);
    }

    public function testCloneTransactionCart(): void
    {
        $transaction = [
            'id' => 1001,
            'cart_items' => [
                [
                    'item_id' => 1,
                    'quantity' => 2,
                    'price' => 100,
                    'attachments' => [
                        [
                            'id' => 'att_001',
                            'filename' => 'contract.pdf',
                            'file_path' => '/path/to/contract.pdf'
                        ]
                    ]
                ]
            ]
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('cloneTransactionCart');
        $method->setAccessible(true);
        
        $clonedCart = $method->invoke($this->service, $transaction);
        
        $this->assertCount(1, $clonedCart);
        $this->assertEquals(1001, $clonedCart[0]['original_transaction_id']);
        $this->assertArrayHasKey('attachments', $clonedCart[0]);
        $this->assertCount(1, $clonedCart[0]['attachments']);
    }

    public function testExtractAttachmentsFromCart(): void
    {
        $cartItems = [
            [
                'item_id' => 1,
                'quantity' => 2,
                'price' => 100,
                'attachments' => [
                    [
                        'id' => 'att_001',
                        'filename' => 'contract.pdf'
                    ]
                ]
            ],
            [
                'item_id' => 2,
                'quantity' => 1,
                'price' => 200
            ]
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractAttachmentsFromCart');
        $method->setAccessible(true);
        
        $attachments = $method->invoke($this->service, $cartItems);
        
        $this->assertCount(1, $attachments);
        $this->assertEquals('att_001', $attachments[0]['id']);
        $this->assertEquals('contract.pdf', $attachments[0]['filename']);
    }

    public function testCorrectionsLogging(): void
    {
        // Clear any existing log
        if (file_exists('/tmp/test_corrections.log')) {
            unlink('/tmp/test_corrections.log');
        }
        
        $transactionId = 1001;
        $newDebtorId = 1002;
        $correctionData = ['reason' => 'Test correction'];
        
        $transaction = [
            'id' => $transactionId,
            'debtor_id' => 1001,
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Perform correction
        $this->service->correctDebtorAssignment($transactionId, $newDebtorId, $correctionData);
        
        // Check log file was created
        $this->assertFileExists('/tmp/test_corrections.log');
        
        // Read log content
        $logContent = file_get_contents('/tmp/test_corrections.log');
        
        // Verify log contains expected information
        $this->assertStringContainsString('SUCCESS', $logContent);
        $this->assertStringContainsString('Source: square_staging', $logContent);
        $this->assertStringContainsString((string)$transactionId, $logContent);
        $this->assertStringContainsString((string)$newDebtorId, $logContent);
        $this->assertStringContainsString('clone_void', $logContent);
    }

    public function testInterfaceImplementation(): void
    {
        $this->assertInstanceOf(TransactionCorrectionInterface::class, $this->service);
    }

    public function testServiceConfiguration(): void
    {
        $config = [
            'enable_correction' => true,
            'log_corrections' => false,
            'correction_log_file' => '/tmp/test_corrections.log',
            'max_transaction_age' => 60 * 24 * 60 * 60, // 60 days
        ];
        
        $service = new TransactionCorrectionService($config);
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        
        $actualConfig = $property->getValue($service);
        
        $this->assertEquals($config['enable_correction'], $actualConfig['enable_correction']);
        $this->assertEquals($config['log_corrections'], $actualConfig['log_corrections']);
        $this->assertEquals($config['max_transaction_age'], $actualConfig['max_transaction_age']);
    }

    public function testTransactionHistoryTracking(): void
    {
        $transactionId = 1001;
        $newDebtorId = 1002;
        
        $transaction = [
            'id' => $transactionId,
            'debtor_id' => 1001,
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Perform correction
        $result = $this->service->correctDebtorAssignment($transactionId, $newDebtorId);
        
        $property = $reflection->getProperty('transactionHistory');
        $property->setAccessible(true);
        $history = $property->getValue($this->service);
        
        $this->assertCount(1, $history);
        $this->assertEquals($transactionId, $history[0]['original_transaction_id']);
        $this->assertEquals(newDebtorId, $history[0]['new_debtor_id']);
        $this->assertEquals('clone_void', $history[0]['correction_method']);
        $this->assertEquals('completed', $history[0]['status']);
    }

    public function testCorrectionWithEmptyCorrectionData(): void
    {
        $transactionId = 1001;
        $newDebtorId = 1002;
        
        $transaction = [
            'id' => $transactionId,
            'debtor_id' => 1001,
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Perform correction without correction data
        $result = $this->service->correctDebtorAssignment($transactionId, $newDebtorId);
        
        $this->assertTrue($result['success']);
        $this->assertEquals($transactionId, $result['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $result['new_transaction']['debtor_id']);
        $this->assertEquals([], $result['correction_data']);
    }

    public function testCorrectionWithComplexCorrectionData(): void
    {
        $transactionId = 1001;
        $newDebtorId = 1002;
        $complexCorrectionData = [
            'reason' => 'Customer moved to different business unit',
            'payment_method' => 'credit_card',
            'reference_number' => 'REF-12345',
            'notes' => 'Updated debtor assignment due to organizational change',
            'effective_date' => date('Y-m-d'),
            'operator' => 'admin_user',
            'department' => 'sales'
        ];
        
        $transaction = [
            'id' => $transactionId,
            'debtor_id' => 1001,
            'total_amount' => 200,
            'created_at' => time(),
            'status' => 'processed',
            'source' => 'square'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTransactionDetails');
        $method->setAccessible(true);
        $method->invoke($this->service, $transactionId);
        
        // Perform correction with complex data
        $result = $this->service->correctDebtorAssignment($transactionId, $newDebtorId, $complexCorrectionData);
        
        $this->assertTrue($result['success']);
        $this->assertEquals($transactionId, $result['original_transaction']['id']);
        $this->assertEquals($newDebtorId, $result['new_transaction']['debtor_id']);
        $this->assertEquals($complexCorrectionData, $result['correction_data']);
    }
}