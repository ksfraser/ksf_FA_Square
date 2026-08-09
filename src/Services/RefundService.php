<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

use Square\SquareClient;
use Ksfraser\Frontaccounting\SquareUp\DAO\SquareImportLogDAO;
use Ksfraser\Frontaccounting\SquareUp\DAO\PaymentMatchDAO;
use Ksfraser\Frontaccounting\SquareUp\Contracts\RefundServiceInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\RefundProcessingException;
use Ksfraser\Frontaccounting\SquareUp\InvoiceCreator;
use Square\Models\Refund;
use Square\Models\CreateRefundRequest;
use Square\Models\Payment;
use Square\Models\Money;
use Square\Models\ListRefundsRequest;
use Square\Exceptions\ApiException;

/**
 * Service for handling refund processing and payment voids.
 * 
 * Manages complete payment lifecycle including refunds and voids.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.01 through FR-06.05 - Refund Management
 */
class RefundService implements RefundServiceInterface
{
    /**
     * @var SquareClient
     */
    private $client;

    /**
     * @var SquareImportLogDAO
     */
    private $importLogDao;

    /**
     * @var PaymentMatchDAO
     */
    private $paymentMatchDao;

    /**
     * @var InvoiceCreator
     */
    private $invoiceCreator;

    public function __construct(
        SquareClient $client,
        SquareImportLogDAO $importLogDao,
        PaymentMatchDAO $paymentMatchDao,
        InvoiceCreator $invoiceCreator
    ) {
        $this->client = $client;
        $this->importLogDao = $importLogDao;
        $this->paymentMatchDao = $paymentMatchDao;
        $this->invoiceCreator = $invoiceCreator;
    }

    /**
     * Creates a refund for a payment.
     *
     * @param Payment $payment Payment to refund
     * @param int $amountInCents Amount to refund in cents
     * @param string $reason Reason for refund
     * @param string|null $locationId Location ID (optional)
     * @return Refund Created refund
     * @throws RefundProcessingException If refund creation fails
     */
    public function createRefund(Payment $payment, int $amountInCents, string $reason, ?string $locationId = null): Refund
    {
        $this->validateRefundData($payment, $amountInCents, $reason);

        try {
            $api = $this->client->getRefundsApi();
            
            $request = new CreateRefundRequest([
                'payment_id' => $payment->getId(),
                'amount_money' => new Money([
                    'amount' => $amountInCents,
                    'currency' => $payment->getAmountMoney()->getCurrency(),
                ]),
                'reason' => $reason,
                'location_id' => $locationId ?? $this->extractLocationId($payment),
                'version' => $payment->getVersion(),
            ]);

            $result = $api->createRefund($request);
            
            if (!$result->isSuccess()) {
                throw new RefundProcessingException(
                    "Failed to create refund: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            $refund = $result->getResult()->getRefund();
            
            // Log refund creation
            $this->importLogDao->logRefund([
                'square_refund_id' => $refund->getId(),
                'square_payment_id' => $payment->getId(),
                'amount' => $amountInCents,
                'currency' => $payment->getAmountMoney()->getCurrency(),
                'reason' => $reason,
                'status' => 'created',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $refund;
        } catch (ApiException $e) {
            throw new RefundProcessingException(
                "Square API error creating refund: " . $e->getMessage()
            );
        }
    }

    /**
     * Lists refunds with optional date filtering.
     *
     * @param string|null $beginTime Start time (ISO 8601)
     * @param string|null $endTime End time (ISO 8601)
     * @param string|null $locationId Location ID (optional)
     * @return array Array of Refund objects
     * @throws RefundProcessingException If listing fails
     */
    public function listRefunds(?string $beginTime = null, ?string $endTime = null, ?string $locationId = null): array
    {
        try {
            $api = $this->client->getRefundsApi();
            
            $request = new ListRefundsRequest();
            if ($beginTime) {
                $request->setBeginTime($beginTime);
            }
            if ($endTime) {
                $request->setEndTime($endTime);
            }
            if ($locationId) {
                $request->setLocationId($locationId);
            }

            $result = $api->listRefunds($request);
            
            if (!$result->isSuccess()) {
                throw new RefundProcessingException(
                    "Failed to list refunds: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            return $result->getResult()->getRefunds() ?? [];
        } catch (ApiException $e) {
            throw new RefundProcessingException(
                "Square API error listing refunds: " . $e->getMessage()
            );
        }
    }

    /**
     * Cancels a payment (void).
     *
     * @param string $paymentId Payment ID to cancel
     * @return bool True if cancellation was successful
     * @throws RefundProcessingException If cancellation fails
     */
    public function cancelPayment(string $paymentId): bool
    {
        try {
            $api = $this->client->getPaymentsApi();
            
            // Note: Square payments API doesn't have a direct cancel method for all payments
            // This is a simplified implementation - in reality, you might need to use specific
            // cancel methods based on payment type (e.g., Terminal checkout cancel)
            
            // For terminal checkouts, you would use:
            // $result = $api->cancelTerminalCheckout($checkoutId);
            
            // For other payment types, you might need to create a full refund
            $result = $api->cancelPayment($paymentId);
            
            if (!$result->isSuccess()) {
                throw new RefundProcessingException(
                    "Failed to cancel payment: " . $this->getApiErrorMessage($result->getErrors())
                );
            }

            // Log cancellation
            $this->importLogDao->logRefund([
                'square_payment_id' => $paymentId,
                'amount' => 0,
                'currency' => '',
                'reason' => 'Payment cancelled',
                'status' => 'cancelled',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (ApiException $e) {
            throw new RefundProcessingException(
                "Square API error cancelling payment: " . $e->getMessage()
            );
        }
    }

    /**
     * Records a refund in FrontAccounting as a credit note.
     *
     * @param Refund $refund Square refund object
     * @param int $invoiceId FA invoice ID
     * @return int Credit note ID
     * @throws RefundProcessingException If recording fails
     */
    public function recordRefundInFA(Refund $refund, int $invoiceId): int
    {
        try {
            // Find the original payment match
            $paymentMatch = $this->paymentMatchDao->getBySquarePaymentId($refund->getPaymentId());
            
            if (!$paymentMatch) {
                throw new RefundProcessingException(
                    "No payment match found for refund payment ID: " . $refund->getPaymentId()
                );
            }

            // Prepare refund data for FA credit note
            $refundData = [
                'original_invoice_id' => $paymentMatch['fa_invoice_no'],
                'refund_amount' => $refund->getAmountMoney()->getAmount(),
                'refund_reason' => $refund->getReason() ?: 'Customer refund',
                'refund_reference' => $refund->getId(),
                'refund_date' => $refund->getCreatedAt(),
                'currency' => $refund->getAmountMoney()->getCurrency(),
            ];

            // Create credit note using invoice creator
            $creditNoteId = $this->invoiceCreator->createCreditNote($refundData);
            
            // Update refund log with FA credit note ID
            $this->importLogDao->logRefund([
                'square_refund_id' => $refund->getId(),
                'fa_credit_note_id' => $creditNoteId,
                'status' => 'recorded_in_fa',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $creditNoteId;
        } catch (\Exception $e) {
            throw new RefundProcessingException(
                "FA error recording refund: " . $e->getMessage()
            );
        }
    }

    /**
     * Processes multiple refunds in batch.
     *
     * @param array $refunds Array of refund data
     * @return array Results array with success/failure status
     */
    public function processRefundBatch(array $refunds): array
    {
        $results = [];
        
        foreach ($refunds as $refundData) {
            try {
                $payment = $refundData['payment'];
                $amount = $refundData['amount'];
                $reason = $refundData['reason'];
                
                $refund = $this->createRefund($payment, $amount, $reason);
                $this->recordRefundInFA($refund, $refundData['invoice_id']);
                
                $results[] = [
                    'success' => true,
                    'refund_id' => $refund->getId(),
                    'message' => 'Refund processed successfully'
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'message' => 'Refund failed: ' . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Validates refund data before processing.
     *
     * @param Payment $payment Payment to refund
     * @param int $amountInCents Amount to refund
     * @param string $reason Refund reason
     * @throws RefundProcessingException If validation fails
     */
    private function validateRefundData(Payment $payment, int $amountInCents, string $reason): void
    {
        if (empty($payment->getId())) {
            throw new RefundProcessingException("Payment ID is required for refund");
        }

        if ($amountInCents <= 0) {
            throw new RefundProcessingException("Refund amount must be greater than 0");
        }

        $paymentAmount = $payment->getAmountMoney()->getAmount();
        if ($amountInCents > $paymentAmount) {
            throw new RefundProcessingException(
                "Refund amount ($amountInCents) cannot exceed payment amount ($paymentAmount)"
            );
        }

        if (empty(trim($reason))) {
            throw new RefundProcessingException("Refund reason is required");
        }
    }

    /**
     * Extracts location ID from payment.
     *
     * @param Payment $payment Payment object
     * @return string|null Location ID
     */
    private function extractLocationId(Payment $payment): ?string
    {
        // Try to get location ID from payment
        $locationId = $payment->getLocationId();
        
        if (!$locationId) {
            // Try to get from associated order or other sources
            // This is a simplified implementation
            error_log("No location ID found in payment, attempting to extract from other sources");
        }
        
        return $locationId;
    }

    /**
     * Gets refund statistics.
     *
     * @param string|null $beginTime Start time (ISO 8601)
     * @param string|null $endTime End time (ISO 8601)
     * @return array Statistics array
     */
    public function getRefundStatistics(?string $beginTime = null, ?string $endTime = null): array
    {
        try {
            $refunds = $this->listRefunds($beginTime, $endTime);
            
            $totalRefunds = count($refunds);
            $totalAmount = 0;
            $byCurrency = [];
            $byStatus = [];
            
            foreach ($refunds as $refund) {
                $amount = $refund->getAmountMoney()->getAmount();
                $currency = $refund->getAmountMoney()->getCurrency();
                $status = $refund->getStatus() ?? 'unknown';
                
                $totalAmount += $amount;
                $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            }
            
            return [
                'total_refunds' => $totalRefunds,
                'total_amount' => $totalAmount,
                'average_amount' => $totalRefunds > 0 ? round($totalAmount / $totalRefunds, 2) : 0,
                'by_currency' => $byCurrency,
                'by_status' => $byStatus,
            ];
        } catch (\Exception $e) {
            error_log("Error getting refund statistics: " . $e->getMessage());
            return [
                'error' => $e->getMessage(),
                'total_refunds' => 0,
                'total_amount' => 0,
            ];
        }
    }

    /**
     * Extracts error message from API response.
     *
     * @param array $errors API errors
     * @return string Error message
     */
    private function getApiErrorMessage(array $errors): string
    {
        $messages = array_map(function ($error) {
            return $error->getDetail() ?? $error->getCode() ?? 'Unknown error';
        }, $errors);
        
        return implode('; ', $messages);
    }
}