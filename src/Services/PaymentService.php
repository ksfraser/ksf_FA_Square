<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Ksfraser\Frontaccounting\SquareUp\Contracts\PaymentServiceInterface;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentsDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMappingDAO;
use Ksfraser\Frontaccounting\SquareUp\Services\PaymentAdapter;
use Ksfraser\Frontaccounting\SquareUp\Services\CustomerService;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\PaymentProcessingException;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\PaymentMappingException;

use Ksfraser\Frontaccounting\SquareUp\Exceptions\RefundProcessingException;
/**
 * Payment Service
 * 
 * Handles payment reconciliation between Square and FrontAccounting.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing, FR-07.02 - Payment Reconciliation
 */
class PaymentService implements PaymentServiceInterface
{
    private PaymentsDAO $paymentsDao;
    private PaymentAdapter $paymentAdapter;
    private CustomerService $customerService;
    private PaymentMappingDAO $paymentMappingDao;
    private string $tablePrefix;

    public function __construct(
        PaymentsDAO $paymentsDao,
        PaymentAdapter $paymentAdapter,
        CustomerService $customerService,
        PaymentMappingDAO $paymentMappingDao
    ) {
        $this->paymentsDao = $paymentsDao;
        $this->paymentAdapter = $paymentAdapter;
        $this->customerService = $customerService;
        $this->paymentMappingDao = $paymentMappingDao;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Records a Square payment in FA.
     * 
     * @param array $squarePayment Square payment data
     * @return int Payment ID
     * @throws PaymentProcessingException on processing failure
     */
    public function recordSquarePayment(array $squarePayment): int
    {
        try {
            // Validate Square payment data
            $this->validateSquarePayment($squarePayment);
            
            // Get or create customer
            $customer = $this->customerService->matchCustomer($squarePayment['customer_email'] ?? '');
            if (!$customer) {
                throw new PaymentProcessingException("Customer not found for payment");
            }
            
            // Convert to FA payment format
            $faPayment = $this->paymentAdapter->convertToFAPayment($squarePayment, $customer);
            
            // Record payment in FA
            $paymentId = $this->paymentsDao->insertPayment($faPayment);
            
            // Create mapping
            $this->paymentMappingDao->createMapping([
                'square_payment_id' => $squarePayment['id'],
                'fa_payment_id' => $paymentId,
                'mapping_data' => json_encode($squarePayment),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Log payment event
            $this->logPaymentEvent([
                'fa_payment_id' => $paymentId,
                'square_payment_id' => $squarePayment['id'],
                'event_type' => 'recorded',
                'amount' => $faPayment['amount'],
                'currency' => $faPayment['currency'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            return $paymentId;
            
        } catch (\Exception $e) {
            throw new PaymentProcessingException("Failed to record Square payment: " . $e->getMessage());
        }
    }

    /**
     * Processes a Square refund.
     * 
     * @param array $squareRefund Square refund data
     * @return int Refund ID
     * @throws RefundProcessingException on processing failure
     */
    public function processSquareRefund(array $squareRefund): int
    {
        try {
            // Validate Square refund data
            $this->validateSquareRefund($squareRefund);
            
            // Get original payment
            $originalPayment = $this->getPaymentBySquareId($squareRefund['payment_id']);
            if (!$originalPayment) {
                throw new RefundProcessingException("Original payment not found for refund");
            }
            
            // Convert to FA refund format
            $faRefund = $this->paymentAdapter->convertToFARefund($squareRefund, $originalPayment);
            
            // Record refund in FA
            $refundId = $this->paymentsDao->insertRefund($faRefund);
            
            // Create mapping
            $this->paymentMappingDao->createMapping([
                'square_refund_id' => $squareRefund['id'],
                'fa_refund_id' => $refundId,
                'square_payment_id' => $squareRefund['payment_id'],
                'original_fa_payment_id' => $originalPayment['fa_payment_id'],
                'mapping_data' => json_encode($squareRefund),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Log refund event
            $this->logPaymentEvent([
                'fa_refund_id' => $refundId,
                'square_refund_id' => $squareRefund['id'],
                'original_fa_payment_id' => $originalPayment['fa_payment_id'],
                'event_type' => 'refund_processed',
                'amount' => $faRefund['amount'],
                'currency' => $faRefund['currency'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            return $refundId;
            
        } catch (\Exception $e) {
            throw new RefundProcessingException("Failed to process Square refund: " . $e->getMessage());
        }
    }

    /**
     * Reconciles Square payments with FA payments.
     * 
     * @param array $payments Square payment data
     * @return array Reconciliation results
     * @throws ReconciliationException on reconciliation failure
     */
    public function reconcileSquarePayments(array $payments): array
    {
        try {
            $results = [
                'processed' => 0,
                'reconciled' => 0,
                'failed' => 0,
                'details' => []
            ];
            
            foreach ($payments as $payment) {
                try {
                    // Check if payment already exists
                    $existingPayment = $this->getPaymentBySquareId($payment['id']);
                    
                    if ($existingPayment) {
                        // Update existing payment
                        $this->updatePaymentStatus($existingPayment['fa_payment_id'], $payment);
                        
                        // Create mapping for the existing payment
                        $this->paymentMappingDao->createMapping([
                            'square_payment_id' => $payment['id'],
                            'fa_payment_id' => $existingPayment['fa_payment_id'],
                            'mapping_data' => json_encode($payment),
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        // Log payment event
                        $this->logPaymentEvent([
                            'fa_payment_id' => $existingPayment['fa_payment_id'],
                            'square_payment_id' => $payment['id'],
                            'event_type' => 'recorded',
                            'timestamp' => date('Y-m-d H:i:s')
                        ]);
                        
                        $results['reconciled']++;
                        $results['details'][] = [
                            'payment_id' => $payment['id'],
                            'status' => 'updated',
                            'message' => 'Payment already exists, updated status'
                        ];
                    } else {
                        // Record new payment
                        $paymentId = $this->recordSquarePayment($payment);
                        $results['processed']++;
                        $results['details'][] = [
                            'payment_id' => $payment['id'],
                            'status' => 'recorded',
                            'message' => 'New payment recorded',
                            'fa_payment_id' => $paymentId
                        ];
                    }
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['details'][] = [
                        'payment_id' => $payment['id'],
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            return $results;
            
        } catch (\Exception $e) {
            throw new ReconciliationException("Failed to reconcile payments: " . $e->getMessage());
        }
    }

    /**
     * Gets payment by Square payment ID.
     * 
     * @param string $squarePaymentId Square payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentBySquareId(string $squarePaymentId): ?array
    {
        return $this->paymentMappingDao->getPaymentBySquareId($squarePaymentId);
    }

    /**
     * Gets payment by FA payment ID.
     * 
     * @param int $faPaymentId FA payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentByFaId(int $faPaymentId): ?array
    {
        return $this->paymentsDao->getPaymentById($faPaymentId);
    }

    /**
     * Creates payment mapping between Square and FA.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     * @throws PaymentMappingException on creation failure
     */
    public function createPaymentMapping(array $mappingData): int
    {
        try {
            // Validate mapping data
            $this->validateMappingData($mappingData);
            
            return $this->paymentMappingDao->createMapping($mappingData);
            
        } catch (\Exception $e) {
            throw new PaymentMappingException("Failed to create payment mapping: " . $e->getMessage());
        }
    }

    /**
     * Gets payment reconciliation statistics.
     * 
     * @return array Statistics array
     */
    public function getPaymentStatistics(): array
    {
        return $this->paymentsDao->getPaymentStatistics();
    }

    /**
     * Validates Square payment data.
     * 
     * @param array $squarePayment Square payment data
     * @throws PaymentProcessingException on validation failure
     */
    private function validateSquarePayment(array $squarePayment): void
    {
        if (empty($squarePayment)) {
            throw new PaymentProcessingException("Square payment data is required");
        }
        
        if (empty($squarePayment['id'])) {
            throw new PaymentProcessingException("Square payment ID is required");
        }
        
        if (empty($squarePayment['amount_money']) || !isset($squarePayment['amount_money']['amount']) || !is_numeric($squarePayment['amount_money']['amount'])) {
            throw new PaymentProcessingException("Square payment data is required");
        }
        
        if (!isset($squarePayment['status']) || !in_array($squarePayment['status'], ['COMPLETED', 'PENDING', 'FAILED'])) {
            throw new PaymentProcessingException("Square payment data is required");
        }
    }

    /**
     * Validates Square refund data.
     * 
     * @param array $squareRefund Square refund data
     * @throws RefundProcessingException on validation failure
     */
    private function validateSquareRefund(array $squareRefund): void
    {
        if (empty($squareRefund)) {
            throw new RefundProcessingException("Square refund data is required");
        }
        
        if (empty($squareRefund['id'])) {
            throw new RefundProcessingException("Square refund ID is required");
        }
        
        if (empty($squareRefund['payment_id'])) {
            throw new RefundProcessingException("Square payment ID is required");
        }
        
        if (!isset($squareRefund['amount_money']['amount']) || !is_numeric($squareRefund['amount_money']['amount'])) {
            throw new RefundProcessingException("Valid refund amount is required");
        }
        
        if (!isset($squareRefund['status']) || !in_array($squareRefund['status'], ['COMPLETED', 'PENDING', 'FAILED'])) {
            throw new RefundProcessingException("Valid refund status is required");
        }
    }

    /**
     * Validates mapping data.
     * 
     * @param array $mappingData Mapping data
     * @throws PaymentMappingException on validation failure
     */
    private function validateMappingData(array $mappingData): void
    {
        if (empty($mappingData)) {
            throw new PaymentMappingException("Mapping data is required");
        }
        
        $hasSquareId = !empty($mappingData['square_payment_id']) || !empty($mappingData['square_refund_id']);
        $hasFaId = !empty($mappingData['fa_payment_id']) || !empty($mappingData['fa_refund_id']);
        
        if (!$hasSquareId || !$hasFaId) {
            throw new PaymentMappingException("Mapping data is required");
        }
    }

    /**
     * Updates payment status.
     * 
     * @param int $faPaymentId FA payment ID
     * @param array $squarePayment Square payment data
     * @return bool Success status
     */
    private function updatePaymentStatus(int $faPaymentId, array $squarePayment): bool
    {
        $updateData = [
            'status' => $this->mapSquareStatusToFaStatus($squarePayment['status']),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->paymentsDao->updatePayment($faPaymentId, $updateData);
    }

    /**
     * Maps Square status to FA status.
     * 
     * @param string $squareStatus Square status
     * @return string FA status
     */
    private function mapSquareStatusToFaStatus(string $squareStatus): string
    {
        $statusMapping = [
            'COMPLETED' => 'Completed',
            'PENDING' => 'Pending',
            'FAILED' => 'Failed',
            'CANCELLED' => 'Cancelled'
        ];
        
        return $statusMapping[$squareStatus] ?? $squareStatus;
    }

    /**
     * Logs payment event.
     * 
     * @param array $eventData Event data
     * @return int Event ID
     */
    private function logPaymentEvent(array $eventData): int
    {
        return $this->paymentsDao->logPaymentEvent($eventData);
    }

    /**
     * Gets payments table name.
     * 
     * @return string Table name
     */
    private function getPaymentsTableName(): string
    {
        return $this->tablePrefix . 'payments';
    }
}