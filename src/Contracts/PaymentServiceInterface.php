<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

/**
 * Payment Service Interface
 * 
 * Defines the contract for payment reconciliation services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing, FR-07.02 - Payment Reconciliation
 */
interface PaymentServiceInterface
{
    /**
     * Records a Square payment in FA.
     * 
     * @param array $squarePayment Square payment data
     * @return int Payment ID
     * @throws PaymentProcessingException on processing failure
     */
    public function recordSquarePayment(array $squarePayment): int;

    /**
     * Processes a Square refund.
     * 
     * @param array $squareRefund Square refund data
     * @return int Refund ID
     * @throws RefundProcessingException on processing failure
     */
    public function processSquareRefund(array $squareRefund): int;

    /**
     * Reconciles Square payments with FA payments.
     * 
     * @param array $payments Square payment data
     * @return array Reconciliation results
     * @throws ReconciliationException on reconciliation failure
     */
    public function reconcileSquarePayments(array $payments): array;

    /**
     * Gets payment by Square payment ID.
     * 
     * @param string $squarePaymentId Square payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentBySquareId(string $squarePaymentId): ?array;

    /**
     * Gets payment by FA payment ID.
     * 
     * @param int $faPaymentId FA payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentByFaId(int $faPaymentId): ?array;

    /**
     * Creates payment mapping between Square and FA.
     * 
     * @param array $mappingData Mapping data
     * @return int Mapping ID
     * @throws PaymentMappingException on creation failure
     */
    public function createPaymentMapping(array $mappingData): int;

    /**
     * Gets payment reconciliation statistics.
     * 
     * @return array Statistics array
     */
    public function getPaymentStatistics(): array;
}