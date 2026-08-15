<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Payment Adapter
 * 
 * Handles conversion between Square payments and FA payments.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing
 */
class PaymentAdapter
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Converts Square payment to FA payment format.
     * 
     * @param array $squarePayment Square payment data
     * @param array $customer FA customer data
     * @return array FA payment data
     */
    public function convertToFAPayment(array $squarePayment, array $customer): array
    {
        $amount = $squarePayment['amount_money']['amount'] / 100; // Convert cents to decimal
        $currency = $squarePayment['amount_money']['currency'] ?? 'USD';
        
        return [
            'debtor_no' => $customer['debtor_no'],
            'amount' => $amount,
            'currency' => $currency,
            'date_1' => date('Y-m-d'),
            'bank_act' => $this->getBankAccount($squarePayment['payment_method']),
            'ref' => $squarePayment['reference_id'] ?? $squarePayment['id'],
            'person_id' => $customer['person_id'] ?? null,
            'bank_trans_type' => 'Receipt',
            'payment_method' => $this->mapSquarePaymentMethod($squarePayment['payment_method']),
            'status' => $this->mapSquareStatusToFaStatus($squarePayment['status']),
            'notes' => $squarePayment['note'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Converts Square refund to FA refund format.
     * 
     * @param array $squareRefund Square refund data
     * @param array $originalPayment Original payment data
     * @return array FA refund data
     */
    public function convertToFARefund(array $squareRefund, array $originalPayment): array
    {
        $amount = $squareRefund['amount_money']['amount'] / 100; // Convert cents to decimal
        $currency = $squareRefund['amount_money']['currency'] ?? 'USD';
        
        return [
            'debtor_no' => $originalPayment['debtor_no'],
            'amount' => $amount,
            'currency' => $currency,
            'date_1' => date('Y-m-d'),
            'bank_act' => $originalPayment['bank_act'],
            'ref' => $squareRefund['reference_id'] ?? $squareRefund['id'],
            'person_id' => $originalPayment['person_id'] ?? null,
            'bank_trans_type' => 'Payment',
            'payment_method' => $this->mapSquarePaymentMethod($squareRefund['payment_method']),
            'status' => $this->mapSquareStatusToFaStatus($squareRefund['status']),
            'notes' => $squareRefund['note'] ?? 'Square refund',
            'original_payment_id' => $originalPayment['fa_payment_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Converts FA payment to Square payment format.
     * 
     * @param array $faPayment FA payment data
     * @return array Square payment data
     */
    public function convertToSquarePayment(array $faPayment): array
    {
        return [
            'amount_money' => [
                'amount' => (int)($faPayment['amount'] * 100), // Convert to cents
                'currency' => $faPayment['currency'] ?? 'USD'
            ],
            'reference_id' => $faPayment['ref'],
            'note' => $faPayment['notes'],
            'customer_id' => $this->getSquareCustomerId($faPayment['debtor_no']),
            'payment_method' => $this->mapFaPaymentMethodToSquare($faPayment['payment_method']),
            'status' => $this->mapFaStatusToSquareStatus($faPayment['status']),
            'created_at' => $faPayment['created_at']
        ];
    }

    /**
     * Maps Square payment method to FA payment method.
     * 
     * @param string $squareMethod Square payment method
     * @return string FA payment method
     */
    public function mapSquarePaymentMethod(string $squareMethod): string
    {
        $methodMapping = [
            'CARD' => 'Credit Card',
            'CASH' => 'Cash',
            'OTHER' => 'Other',
            'BANK_TRANSFER' => 'Bank Transfer',
            'SQUARE_GIFT_CARD' => 'Gift Card',
            'SQUARE_STORE_CREDIT' => 'Store Credit'
        ];
        
        return $methodMapping[$squareMethod] ?? $squareMethod;
    }

    /**
     * Maps FA payment method to Square payment method.
     * 
     * @param string $faMethod FA payment method
     * @return string Square payment method
     */
    public function mapFaPaymentMethodToSquare(string $faMethod): string
    {
        $methodMapping = [
            'Credit Card' => 'CARD',
            'Cash' => 'CASH',
            'Check' => 'OTHER',
            'Bank Transfer' => 'BANK_TRANSFER',
            'Debit Card' => 'CARD'
        ];
        
        return $methodMapping[$faMethod] ?? 'OTHER';
    }

    /**
     * Maps Square status to FA status.
     * 
     * @param string $squareStatus Square status
     * @return string FA status
     */
    public function mapSquareStatusToFaStatus(string $squareStatus): string
    {
        $statusMapping = [
            'COMPLETED' => 'Completed',
            'PENDING' => 'Pending',
            'FAILED' => 'Failed',
            'CANCELLED' => 'Cancelled',
            'PARTIALLY_FUNDED' => 'Pending',
            'FUNDED' => 'Completed',
            'REFUNDED' => 'Refunded',
            'PARTIALLY_REFUNDED' => 'Partially Refunded'
        ];
        
        return $statusMapping[$squareStatus] ?? $squareStatus;
    }

    /**
     * Maps FA status to Square status.
     * 
     * @param string $faStatus FA status
     * @return string Square status
     */
    public function mapFaStatusToSquareStatus(string $faStatus): string
    {
        $statusMapping = [
            'Completed' => 'COMPLETED',
            'Pending' => 'PENDING',
            'Failed' => 'FAILED',
            'Cancelled' => 'CANCELLED',
            'Refunded' => 'REFUNDED',
            'Partially Refunded' => 'PARTIALLY_REFUNDED'
        ];
        
        return $statusMapping[$faStatus] ?? 'COMPLETED';
    }

    /**
     * Gets bank account from payment method.
     * 
     * @param string $paymentMethod Payment method
     * @return string Bank account
     */
    public function getBankAccount(string $paymentMethod): string
    {
        $bankMapping = [
            'CARD' => 'Default Card Processing',
            'CASH' => 'Cash Sales',
            'BANK_TRANSFER' => 'Bank Account',
            'SQUARE_GIFT_CARD' => 'Gift Card Sales',
            'SQUARE_STORE_CREDIT' => 'Store Credit'
        ];
        
        return $bankMapping[$paymentMethod] ?? 'Default Bank Account';
    }

    /**
     * Gets Square customer ID from FA debtor number.
     * 
     * @param int $debtorNo FA debtor number
     * @return string Square customer ID
     */
    public function getSquareCustomerId(int $debtorNo): string
    {
        // This would normally query the customer mapping table
        // For now, return a placeholder
        return 'cus_' . $debtorNo;
    }

    /**
     * Validates payment data.
     * 
     * @param array $paymentData Payment data
     * @return bool Validation result
     */
    public function validatePaymentData(array $paymentData): bool
    {
        $requiredFields = ['amount', 'currency', 'debtor_no'];
        
        foreach ($requiredFields as $field) {
            if (!isset($paymentData[$field]) || empty($paymentData[$field])) {
                return false;
            }
        }
        
        if (!is_numeric($paymentData['amount']) || $paymentData['amount'] <= 0) {
            return false;
        }
        
        if (!in_array($paymentData['currency'], ['USD', 'EUR', 'GBP', 'CAD', 'AUD'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Calculates payment fees.
     * 
     * @param array $paymentData Payment data
     * @return array Fee calculations
     */
    public function calculateFees(array $paymentData): array
    {
        $amount = $paymentData['amount'];
        $paymentMethod = $paymentData['payment_method'] ?? 'CARD';
        
        // Fee rates by payment method
        $feeRates = [
            'CARD' => 0.029, // 2.9%
            'CASH' => 0.0,
            'BANK_TRANSFER' => 0.01, // 1%
            'SQUARE_GIFT_CARD' => 0.0,
            'SQUARE_STORE_CREDIT' => 0.0
        ];
        
        $feeRate = $feeRates[$paymentMethod] ?? 0.029;
        $feeAmount = $amount * $feeRate;
        
        return [
            'fee_rate' => $feeRate,
            'fee_amount' => round($feeAmount, 2),
            'net_amount' => round($amount - $feeAmount, 2)
        ];
    }

    /**
     * Formats payment summary for display.
     * 
     * @param array $payments Payment data
     * @return array Formatted payment summary
     */
    public function formatPaymentSummary(array $payments): array
    {
        $summary = [
            'total_payments' => 0,
            'total_amount' => 0,
            'by_payment_method' => [],
            'by_status' => [],
            'by_date' => []
        ];
        
        foreach ($payments as $payment) {
            $summary['total_payments']++;
            $summary['total_amount'] += $payment['amount'];
            
            // By payment method
            $method = $payment['payment_method'];
            $summary['by_payment_method'][$method] = ($summary['by_payment_method'][$method] ?? 0) + 1;
            
            // By status
            $status = $payment['status'];
            $summary['by_status'][$status] = ($summary['by_status'][$status] ?? 0) + 1;
            
            // By date
            $date = date('Y-m-d', strtotime($payment['date_1']));
            $summary['by_date'][$date] = ($summary['by_date'][$date] ?? 0) + 1;
        }
        
        return $summary;
    }

    /**
     * Gets payment reconciliation status.
     * 
     * @param array $squarePayment Square payment
     * @param array $faPayment FA payment
     * @return array Reconciliation status
     */
    public function getReconciliationStatus(array $squarePayment, array $faPayment): array
    {
        $squareAmount = $squarePayment['amount_money']['amount'] / 100;
        $faAmount = $faPayment['amount'];
        
        $status = [
            'amount_match' => abs($squareAmount - $faAmount) < 0.01,
            'currency_match' => $squarePayment['amount_money']['currency'] === $faPayment['currency'],
            'date_match' => date('Y-m-d', strtotime($squarePayment['created_at'])) === $faPayment['date_1'],
            'status_match' => $this->mapSquareStatusToFaStatus($squarePayment['status']) === $faPayment['status']
        ];
        
        $status['overall_match'] = $status['amount_match'] && 
                                  $status['currency_match'] && 
                                  $status['date_match'] && 
                                  $status['status_match'];
        
        return $status;
    }
}