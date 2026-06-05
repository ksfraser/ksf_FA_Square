<?php
declare(strict_types=1);

/**
 * Stock Movement Adapter
 * 
 * Handles communication with Square's inventory API for stock movements.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.03 - Stock Movements
 */
class StockMovementAdapter
{
    private SquareClient $squareClient;

    public function __construct(SquareClient $squareClient)
    {
        $this->squareClient = $squareClient;
    }

    /**
     * Sends a stock move to Square.
     * 
     * @param string $locationId Square location ID
     * @param array $stockMove Stock move data
     * @return bool Success status
     * @throws StockEventException on API failure
     */
    public function sendStockMove(string $locationId, array $stockMove): bool
    {
        try {
            $api = $this->squareClient->getInventoryApi();
            
            $request = new \Square\Models\ChangeInventoryRequest([
                'type' => \Square\Models\InventoryChangeType::CHANGE,
                'location_id' => $locationId,
                'catalog_object_id' => $stockMove['item_id'],
                'quantity' => $stockMove['quantity'],
                'reason' => $stockMove['reason'],
            ]);
            
            $response = $api->changeInventory($request);
            
            if (!$response->isSuccess()) {
                throw new StockEventException("Square API error: " . $response->getErrors()[0]->getMessage());
            }
            
            return true;
            
        } catch (\Exception $e) {
            throw new StockEventException("Failed to send stock move: " . $e->getMessage());
        }
    }

    /**
     * Sends a stock adjustment to Square.
     * 
     * @param string $locationId Square location ID
     * @param array $adjustment Adjustment data
     * @return bool Success status
     * @throws StockEventException on API failure
     */
    public function sendAdjustment(string $locationId, array $adjustment): bool
    {
        try {
            $api = $this->squareClient->getInventoryApi();
            
            $request = new \Square\Models\ChangeInventoryRequest([
                'type' => \Square\Models\InventoryChangeType::ADJUSTMENT,
                'location_id' => $locationId,
                'catalog_object_id' => $adjustment['item_id'],
                'quantity' => $adjustment['quantity_change'],
                'reason' => $adjustment['reason'],
            ]);
            
            $response = $api->changeInventory($request);
            
            if (!$response->isSuccess()) {
                throw new StockEventException("Square API error: " . $response->getErrors()[0]->getMessage());
            }
            
            return true;
            
        } catch (\Exception $e) {
            throw new StockEventException("Failed to send adjustment: " . $e->getMessage());
        }
    }

    /**
     * Sends an inventory count to Square.
     * 
     * @param string $locationId Square location ID
     * @param array $count Count data
     * @return bool Success status
     * @throws StockEventException on API failure
     */
    public function sendInventoryCount(string $locationId, array $count): bool
    {
        try {
            $api = $this->squareClient->getInventoryApi();
            
            $request = new \Square\Models\BatchChangeInventoryRequest([
                'idempotency_key' => uniqid('inventory_count_', true),
                'changes' => [
                    new \Square\Models\InventoryChange([
                        'type' => \Square\Models\InventoryChangeType::PHYSICAL_COUNT,
                        'location_id' => $locationId,
                        'catalog_object_id' => $count['item_id'],
                        'quantity' => $count['counted_quantity'],
                        'physically_counted_at' => $count['timestamp'],
                    ])
                ]
            ]);
            
            $response = $api->batchChangeInventory($request);
            
            if (!$response->isSuccess()) {
                throw new StockEventException("Square API error: " . $response->getErrors()[0]->getMessage());
            }
            
            return true;
            
        } catch (\Exception $e) {
            throw new StockEventException("Failed to send inventory count: " . $e->getMessage());
        }
    }
}