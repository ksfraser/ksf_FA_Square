<?php
declare(strict_types=1);

/**
 * Integration tests for Square ISU Repository Adapters.
 *
 * Runs inside the ksf-fa container against the real MariaDB.
 * Tests CRUD operations for all 5 adapter classes.
 *
 * Usage:
 *   podman exec ksf-fa php /var/www/html/modules/ksf_FA_Square/tests/Integration/RepositoryAdapterIntegrationTest.php
 *
 * @BABOK Related: FR-SQUARE-ISU-ADAPTER
 */

// Bootstrap DB functions and autoloaders
require_once '/var/www/html/modules/ksf_FA_Square/tests/Integration/bootstrap_db.php';
require_once '/var/www/html/modules/ksf_FA_ImportStagingProcessing/vendor/autoload.php';
require_once '/var/www/html/modules/ksf_FA_Square/vendor/autoload.php';

use ksfraser\FrontAccounting\ImportStaging\Models\StagingTransaction;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingCustomer;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingPayment;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingLineItem;
use ksfraser\FrontAccounting\Square\Staging\TransactionRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\CustomerRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\PaymentRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\LineItemRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\AuditLogRepositoryAdapter;

$passed = 0;
$failed = 0;
$errors = [];

function assert_test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        $msg = "  FAIL  {$name}" . ($detail ? " — {$detail}" : '');
        $errors[] = $msg;
        echo "{$msg}\n";
    }
}

function cleanup_rows(string $table, string $where): void
{
    $sql = "DELETE FROM {$table} WHERE {$where}";
    @\db_query($sql);
}

echo "=== Repository Adapter Integration Tests ===\n\n";

// --- Test 1: TransactionRepositoryAdapter ---
echo "1. TransactionRepositoryAdapter\n";

$adapter = new TransactionRepositoryAdapter('0_');

$tx = new StagingTransaction('integration_test');
$tx->setSourceTransactionId('INT-TXN-' . uniqid());
$tx->setSourceOrderId('INT-ORD-' . uniqid());
$tx->setSourcePaymentId('INT-PAY-' . uniqid());
$tx->setTotalAmount(123.45);
$tx->setTaxAmount(16.05);
$tx->setTipAmount(5.00);
$tx->setDiscountAmount(10.00);
$tx->setCurrency('CAD');
$tx->setCustomerName('Integration Test Customer');
$tx->setCustomerEmail('test@example.com');

$id = $adapter->insert($tx);
assert_test('insert returns positive ID', $id > 0, "got {$id}");
assert_test('inserted ID is valid', $id > 0);

$found = $adapter->findById($id);
assert_test('findById returns model', $found !== null);
assert_test('findById matches source_transaction_id', $found->getSourceTransactionId() === $tx->getSourceTransactionId());
assert_test('findById matches total_amount', $found->getTotalAmount() === 123.45);
assert_test('findById matches tax_amount', $found->getTaxAmount() === 16.05);
assert_test('findById matches tip_amount', $found->getTipAmount() === 5.00);
assert_test('findById matches customer_name', $found->getCustomerName() === 'Integration Test Customer');
assert_test('findById matches status (default)', $found->getStatus() === 'staged');

$bySource = $adapter->findBySource('integration_test', $tx->getSourceTransactionId());
assert_test('findBySource returns model', $bySource !== null);
assert_test('findBySource matches ID', $bySource !== null && $bySource->getId() === $id);

$adapter->updateStatus($id, 'validated');
$updated = $adapter->findById($id);
assert_test('updateStatus changes status', $updated !== null && $updated->getStatus() === 'validated');

$adapter->updateFaReference($id, 99901, 500);
$ref = $adapter->findById($id);
assert_test('updateFaReference sets invoice_no', $ref !== null && $ref->getFaInvoiceNo() === 99901);
assert_test('updateFaReference sets debtor_no', $ref !== null && $ref->getFaDebtorNo() === 500);

$counts = $adapter->countByStatus();
assert_test('countByStatus returns array', is_array($counts));
assert_test('countByStatus has staged key', array_key_exists('staged', $counts) || array_key_exists('validated', $counts));

$queue = $adapter->getQueueForProcessing(null, 5);
assert_test('getQueueForProcessing returns array', is_array($queue));

cleanup_rows('0_staging_transactions', "source = 'integration_test'");

// --- Test 2: CustomerRepositoryAdapter ---
echo "\n2. CustomerRepositoryAdapter\n";

$custAdapter = new CustomerRepositoryAdapter('0_');

$customer = new StagingCustomer('integration_test');
$customer->setSourceCustomerId('INT-CUST-' . uniqid());
$customer->setName('Test Customer Ltd');
$customer->setEmail('customer@example.com');
$customer->setPhone('555-0123');
$customer->setCity('Testville');
$customer->setProvince('ON');
$customer->setPostalCode('A1B2C3');
$customer->setCountry('CA');

$custId = $custAdapter->insert($customer);
assert_test('customer insert returns positive ID', $custId > 0);

$foundCust = $custAdapter->findById($custId);
assert_test('customer findById returns model', $foundCust !== null);
assert_test('customer findById matches name', $foundCust !== null && $foundCust->getName() === 'Test Customer Ltd');
assert_test('customer findById matches email', $foundCust !== null && $foundCust->getEmail() === 'customer@example.com');
assert_test('customer findById matches phone', $foundCust !== null && $foundCust->getPhone() === '555-0123');
assert_test('customer findById matches city', $foundCust !== null && $foundCust->getCity() === 'Testville');

$byEmail = $custAdapter->findByEmail('customer@example.com');
assert_test('findByEmail returns results', count($byEmail) > 0);

$custAdapter->updateStatus($custId, 'processed');
$updatedCust = $custAdapter->findById($custId);
assert_test('customer updateStatus works', $updatedCust !== null && $updatedCust->getStatus() === 'processed');

$counts = $custAdapter->countByStatus();
assert_test('customer countByStatus returns array', is_array($counts));

cleanup_rows('0_staging_customers', "source = 'integration_test'");

// --- Test 3: PaymentRepositoryAdapter ---
echo "\n3. PaymentRepositoryAdapter\n";

$payAdapter = new PaymentRepositoryAdapter('0_');

$payment = new StagingPayment('integration_test');
$payment->setSourcePaymentId('INT-PAY-' . uniqid());
$payment->setSourceTransactionId('INT-TXN-' . uniqid());
$payment->setAmount(250.00);
$payment->setCurrency('USD');
$payment->setFee(7.50);
$payment->setNetAmount(242.50);
$payment->setPaymentMethod('card');
$payment->setPaymentDate(new \DateTimeImmutable('2025-06-15'));
$payment->setCardBrand('VISA');
$payment->setPanSuffix('4242');
$payment->setStatus('staged');

$payId = $payAdapter->insert($payment);
assert_test('payment insert returns positive ID', $payId > 0);

$foundPay = $payAdapter->findById($payId);
assert_test('payment findById returns model', $foundPay !== null);
assert_test('payment findById matches amount', $foundPay !== null && $foundPay->getAmount() === 250.00);
assert_test('payment findById matches currency', $foundPay !== null && $foundPay->getCurrency() === 'USD');
assert_test('payment findById matches fee', $foundPay !== null && $foundPay->getFee() === 7.50);
assert_test('payment findById matches net_amount', $foundPay !== null && $foundPay->getNetAmount() === 242.50);
assert_test('payment findById matches payment_method', $foundPay !== null && $foundPay->getPaymentMethod() === 'card');
assert_test('payment findById matches card_brand', $foundPay !== null && $foundPay->getCardBrand() === 'VISA');
assert_test('payment findById matches pan_suffix', $foundPay !== null && $foundPay->getPanSuffix() === '4242');

$payAdapter->updateStatus($payId, 'reconciled', 0.95);
$updatedPay = $payAdapter->findById($payId);
assert_test('payment updateStatus changes status', $updatedPay !== null && $updatedPay->getStatus() === 'reconciled');
assert_test('payment updateStatus sets confidence', $updatedPay !== null && $updatedPay->getMatchConfidence() === 0.95);

$payCounts = $payAdapter->countByStatus();
assert_test('payment countByStatus returns array', is_array($payCounts));

cleanup_rows('0_staging_payments', "source = 'integration_test'");

// --- Test 4: LineItemRepositoryAdapter ---
echo "\n4. LineItemRepositoryAdapter\n";

$liAdapter = new LineItemRepositoryAdapter('0_');

// First create a parent transaction
$parentTx = new StagingTransaction('integration_test');
$parentTx->setSourceTransactionId('INT-LI-TXN-' . uniqid());
$parentTx->setTotalAmount(100.00);
$parentTxId = $adapter->insert($parentTx);
assert_test('parent transaction created for line items', $parentTxId > 0);

$item = new StagingLineItem();
$item->setStagingTransactionId($parentTxId);
$item->setSource('integration_test');
$item->setSourceId('INT-LI-' . uniqid());
$item->setLineNumber(1);
$item->setSku('SKU-TEST-001');
$item->setName('Test Widget');
$item->setDescription('A test widget');
$item->setItemType('product');
$item->setQuantity(3.0);
$item->setUnitPrice(25.00);
$item->setTaxAmount(4.88);
$item->setTaxPercent(7.0);
$item->setTotalAmount(79.88);
$item->setCurrency('CAD');
$item->setStatus('staged');
$item->setAttributes(['color' => 'blue', 'size' => 'large']);

$liId = $liAdapter->insert($item);
assert_test('line item insert returns positive ID', $liId > 0);

$byTxn = $liAdapter->findByTransactionId($parentTxId);
assert_test('findByTransactionId returns results', count($byTxn) > 0);
assert_test('findByTransactionId matches name', count($byTxn) > 0 && $byTxn[0]->getName() === 'Test Widget');
assert_test('findByTransactionId matches sku', count($byTxn) > 0 && $byTxn[0]->getSku() === 'SKU-TEST-001');
assert_test('findByTransactionId loads attributes', count($byTxn) > 0 && isset($byTxn[0]->getAttributes()['color']));
assert_test('line item attributes value correct', count($byTxn) > 0 && $byTxn[0]->getAttributes()['color'] === 'blue');

$bySource = $liAdapter->findBySource('integration_test');
assert_test('findBySource returns results', count($bySource) > 0);

$liAdapter->updateStatus($liId, 'processed');
$liAdapter->deleteByTransactionId($parentTxId);
$afterDelete = $liAdapter->findByTransactionId($parentTxId);
assert_test('deleteByTransactionId removes items', count($afterDelete) === 0);

cleanup_rows('0_staging_transactions', "source = 'integration_test'");

// --- Test 5: AuditLogRepositoryAdapter ---
echo "\n5. AuditLogRepositoryAdapter\n";

$logAdapter = new AuditLogRepositoryAdapter('0_');

$logId = $logAdapter->log('transaction', $id, 'imported', 'integration_test', ['source' => 'square_api']);
assert_test('audit log returns positive ID', $logId > 0);

$byRecord = $logAdapter->findByRecord('transaction', $id);
assert_test('findByRecord returns results', count($byRecord) > 0);

$recent = $logAdapter->getRecent(5);
assert_test('getRecent returns array', is_array($recent));

$actionCounts = $logAdapter->countByAction();
assert_test('countByAction returns array', is_array($actionCounts));

cleanup_rows('0_staging_log', "source = 'integration_test'");

// --- Summary ---
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "{$e}\n";
    }
    exit(1);
}

echo "All tests passed!\n";
exit(0);
