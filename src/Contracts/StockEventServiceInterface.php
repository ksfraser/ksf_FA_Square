<?php
declare(strict_types=1);

/**
 * Stock Event Service Interface
 * 
 * Defines the contract for stock event services.
 * 
 * @UML Note: Interface diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.03 - Stock Movements, FR-07.01 - FA Native Integration
 */
interface StockEventServiceInterface
{
    /**
     * Starts listening to stock events from FrontAccounting.
     */
    public function listenToStockEvents(): void;

    /**
     * Synchronizes stock movements to Square.
     * 
     * @param string $squareLocationId Square location ID
     * @param array $stockMoves Array of stock movement data
     * @throws StockEventException on sync failure
     */
    public function syncStockMovements(string $squareLocationId, array $stockMoves): void;
}