<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Contracts;

/**
 * Sales Order Service Interface
 * 
 * Defines the contract for sales order integration services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-02.01 - Order Synchronization, FR-02.02 - Order Status Tracking
 */
interface SalesOrderServiceInterface
{
    /**
     * Creates a sales order from Square order data.
     * 
     * @param array $squareOrder Square order data
     * @return array FA sales order data
     * @throws SalesOrderException on creation failure
     */
    public function createSalesOrderFromSquare(array $squareOrder): array;

    /**
     * Updates an existing sales order.
     * 
     * @param int $orderId FA order ID
     * @param array $updates Update data
     * @throws SalesOrderException on update failure
     */
    public function updateSalesOrder(int $orderId, array $updates): void;

    /**
     * Creates a sales credit note from a Square refund.
     * 
     * @param int $originalOrderId Original FA order ID
     * @param string $reason Reason for the credit note
     * @return array FA credit note data
     * @throws SalesOrderException on creation failure
     */
    public function createSalesCreditNote(int $originalOrderId, string $reason): array;

    /**
     * Gets sales order by Square order ID.
     * 
     * @param string $squareOrderId Square order ID
     * @return array|null FA sales order data or null if not found
     */
    public function getSalesOrderBySquareId(string $squareOrderId): ?array;

    /**
     * Gets sales order statistics.
     * 
     * @return array Statistics array
     */
    public function getOrderStatistics(): array;
}