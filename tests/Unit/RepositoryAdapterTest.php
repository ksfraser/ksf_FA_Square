<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\ImportStaging\Contracts\TransactionRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Contracts\CustomerRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Contracts\PaymentRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Contracts\LineItemRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Contracts\AuditLogRepositoryInterface;
use ksfraser\FrontAccounting\Square\Staging\TransactionRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\CustomerRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\PaymentRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\LineItemRepositoryAdapter;
use ksfraser\FrontAccounting\Square\Staging\AuditLogRepositoryAdapter;
/**
 * Verify that Square adapter classes implement ISU repository interfaces.
 *
 * These adapters allow ISU's StagingService to work with Square's
 * proprietary staging tables through the standard interface contract.
 *
 * @BABOK Related: FR-SQUARE-ISU-ADAPTER
 */
class RepositoryAdapterTest extends TestCase
{
    /**
     * @test
     * All Square adapter classes exist and implement the correct ISU interface.
     *
     * @coversNothing
     */
    public function adaptersImplementIsuInterfaces(): void
    {
        $prefix = '0_test_';
        $tablePrefix = '0_test_';

        $this->assertInstanceOf(
            TransactionRepositoryInterface::class,
            new TransactionRepositoryAdapter($prefix)
        );

        $this->assertInstanceOf(
            CustomerRepositoryInterface::class,
            new CustomerRepositoryAdapter($prefix)
        );

        $this->assertInstanceOf(
            PaymentRepositoryInterface::class,
            new PaymentRepositoryAdapter($prefix)
        );

        $this->assertInstanceOf(
            LineItemRepositoryInterface::class,
            new LineItemRepositoryAdapter($tablePrefix)
        );

        $this->assertInstanceOf(
            AuditLogRepositoryInterface::class,
            new AuditLogRepositoryAdapter($tablePrefix)
        );
    }

    /**
     * @test
     * TransactionRepositoryAdapter maps ISU StagingTransaction to Square columns.
     *
     * Verifies that an ISU StagingTransaction model round-trips through the adapter.
     */
    public function transactionAdapterMapsFieldsCorrectly(): void
    {
        $adapter = new TransactionRepositoryAdapter('0_test_');

        $tx = new \ksfraser\FrontAccounting\ImportStaging\Models\StagingTransaction('square_api');
        $tx->setSourceTransactionId('TXN-001');
        $tx->setSourceOrderId('ORD-001');
        $tx->setSourcePaymentId('PAY-001');
        $tx->setTotalAmount(100.50);
        $tx->setTaxAmount(13.07);
        $tx->setTipAmount(5.00);
        $tx->setDiscountAmount(10.00);
        $tx->setCurrency('USD');
        $tx->setCustomerName('John Doe');

        $mapped = $adapter->toSquareRow($tx);

        $this->assertEquals('square_api', $mapped['source']);
        $this->assertEquals('TXN-001', $mapped['transaction_id']);
        $this->assertEquals('ORD-001', $mapped['square_order_id']);
        $this->assertEquals('PAY-001', $mapped['payment_id']);
        $this->assertEquals(100.50, $mapped['total_collected']);
        $this->assertEquals(13.07, $mapped['tax']);
        $this->assertEquals(5.00, $mapped['tip']);
        $this->assertEquals(10.00, $mapped['discounts']);
        $this->assertEquals('USD', $mapped['currency'] ?? $mapped['source'] ?? 'USD');
        $this->assertEquals('John Doe', $mapped['customer_name']);
    }

    /**
     * @test
     * TransactionRepositoryAdapter reverse-maps Square row to ISU model.
     */
    public function transactionAdapterReverseMapsCorrectly(): void
    {
        $adapter = new TransactionRepositoryAdapter('0_test_');

        $squareRow = [
            'id' => 42,
            'transaction_id' => 'TXN-002',
            'square_order_id' => 'ORD-002',
            'payment_id' => 'PAY-002',
            'Date' => '2025-01-15',
            'total_collected' => '250.00',
            'tax' => '32.50',
            'tip' => '10.00',
            'discounts' => '5.00',
            'source' => 'api',
            'customer_name' => 'Jane Smith',
            'Customer_id' => 123,
            'status' => 'staged',
            'fa_invoice_no' => null,
            'fa_debtor_no' => null,
            'created_at' => '2025-01-15 10:30:00',
            'updated_at' => '2025-01-15 10:30:00',
        ];

        $model = $adapter->toStagingTransaction($squareRow);

        $this->assertEquals(42, $model->getId());
        $this->assertEquals('TXN-002', $model->getSourceTransactionId());
        $this->assertEquals('ORD-002', $model->getSourceOrderId());
        $this->assertEquals('PAY-002', $model->getSourcePaymentId());
        $this->assertEquals(250.00, $model->getTotalAmount());
        $this->assertEquals(32.50, $model->getTaxAmount());
        $this->assertEquals(10.00, $model->getTipAmount());
        $this->assertEquals(5.00, $model->getDiscountAmount());
        $this->assertEquals('Jane Smith', $model->getCustomerName());
        $this->assertEquals('staged', $model->getStatus());
    }

    /**
     * @test
     * LineItemRepositoryAdapter can be instantiated and is a LineItemRepositoryInterface.
     */
    public function lineItemAdapterIsInstantiable(): void
    {
        $adapter = new LineItemRepositoryAdapter('0_test_');
        $this->assertInstanceOf(LineItemRepositoryInterface::class, $adapter);
    }
}
