<?php
declare(strict_types=1);

/**
 * Stock Event Service
 * 
 * Handles stock movement synchronization between FrontAccounting and Square.
 * Listens to FA stock events and syncs to Square inventory.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.03 - Stock Movements, FR-07.01 - FA Native Integration
 */
class StockEventService implements StockEventServiceInterface
{
    private SquareClient $squareClient;
    private LocationMappingDAO $locationMappingDao;
    private StockMovementAdapter $stockAdapter;
    private StockEventDAO $eventDao;
    private string $tablePrefix;

    public function __construct(
        SquareClient $squareClient,
        LocationMappingDAO $locationMappingDao,
        StockMovementAdapter $stockAdapter,
        StockEventDAO $eventDao
    ) {
        $this->squareClient = $squareClient;
        $this->locationMappingDao = $locationMappingDao;
        $this->stockAdapter = $stockAdapter;
        $this->eventDao = $eventDao;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Starts listening to stock events from FrontAccounting.
     */
    public function listenToStockEvents(): void
    {
        // Hook into FA's stock movement events
        add_action('stock_after_move', function($move) {
            $this->handleStockMove($move);
        });
        
        add_action('stock_after_adjustment', function($adjustment) {
            $this->handleStockAdjustment($adjustment);
        });
        
        add_action('stock_after_count', function($count) {
            $this->handleStockCount($count);
        });
    }

    /**
     * Synchronizes stock movements to Square.
     * 
     * @param string $squareLocationId Square location ID
     * @param array $stockMoves Array of stock movement data
     * @throws StockEventException on sync failure
     */
    public function syncStockMovements(string $squareLocationId, array $stockMoves): void
    {
        try {
            // Validate stock moves data
            $this->validateStockMoves($stockMoves);
            
            // Process each stock move
            foreach ($stockMoves as $move) {
                $this->syncSingleStockMove($squareLocationId, $move);
            }
            
        } catch (\Exception $e) {
            throw new StockEventException("Stock movements sync failed: " . $e->getMessage());
        }
    }

    /**
     * Handles stock move events from FrontAccounting.
     * 
     * @param array $move Stock move data
     */
    private function handleStockMove(array $move): void
    {
        try {
            // Get Square location ID
            $squareLocationId = $this->locationMappingDao->getSquareLocationId($move['stock_id']);
            
            if (!$squareLocationId) {
                // Log un-moved item
                $this->eventDao->logUnmappedMove($move);
                return;
            }
            
            // Prepare stock move data for Square
            $stockMove = [
                'type' => 'STOCK_MOVE',
                'from_location' => $move['from_location'],
                'to_location' => $move['to_location'],
                'item_id' => $this->getSquareItemId($move['stock_id']),
                'quantity' => $move['quantity'],
                'reason' => 'Stock transfer from FrontAccounting',
                'timestamp' => date('Y-m-d H:i:s'),
                'fa_move_id' => $move['move_id']
            ];
            
            // Send to Square
            $this->stockAdapter->sendStockMove($squareLocationId, $stockMove);
            
            // Log successful move
            $this->eventDao->logStockMove($stockMove, true);
            
        } catch (\Exception $e) {
            // Log failed move
            $this->eventDao->logStockMove($move, false, $e->getMessage());
        }
    }

    /**
     * Handles stock adjustment events from FrontAccounting.
     * 
     * @param array $adjustment Stock adjustment data
     */
    private function handleStockAdjustment(array $adjustment): void
    {
        try {
            // Get Square location ID
            $squareLocationId = $this->locationMappingDao->getSquareLocationId($adjustment['stock_id']);
            
            if (!$squareLocationId) {
                $this->eventDao->logUnmappedAdjustment($adjustment);
                return;
            }
            
            // Prepare adjustment data
            $adjustmentData = [
                'type' => 'ADJUSTMENT',
                'item_id' => $this->getSquareItemId($adjustment['stock_id']),
                'quantity_change' => $adjustment['quantity_change'],
                'reason' => $adjustment['reason'] ?? 'Stock adjustment from FrontAccounting',
                'timestamp' => date('Y-m-d H:i:s'),
                'fa_adjustment_id' => $adjustment['adjustment_id']
            ];
            
            // Send to Square
            $this->stockAdapter->sendAdjustment($squareLocationId, $adjustmentData);
            
            // Log successful adjustment
            $this->eventDao->logStockAdjustment($adjustmentData, true);
            
        } catch (\Exception $e) {
            $this->eventDao->logStockAdjustment($adjustment, false, $e->getMessage());
        }
    }

    /**
     * Handles stock count events from FrontAccounting.
     * 
     * @param array $count Stock count data
     */
    private function handleStockCount(array $count): void
    {
        try {
            // Get Square location ID
            $squareLocationId = $this->locationMappingDao->getSquareLocationId($count['stock_id']);
            
            if (!$squareLocationId) {
                $this->eventDao->logUnmappedCount($count);
                return;
            }
            
            // Prepare count data
            $countData = [
                'type' => 'INVENTORY_COUNT',
                'item_id' => $this->getSquareItemId($count['stock_id']),
                'counted_quantity' => $count['counted_quantity'],
                'expected_quantity' => $count['expected_quantity'],
                'difference' => $count['counted_quantity'] - $count['expected_quantity'],
                'reason' => $count['reason'] ?? 'Stock count from FrontAccounting',
                'timestamp' => date('Y-m-d H:i:s'),
                'fa_count_id' => $count['count_id']
            ];
            
            // Send to Square
            $this->stockAdapter->sendInventoryCount($squareLocationId, $countData);
            
            // Log successful count
            $this->eventDao->logStockCount($countData, true);
            
        } catch (\Exception $e) {
            $this->eventDao->logStockCount($count, false, $e->getMessage());
        }
    }

    /**
     * Syncs a single stock move to Square.
     * 
     * @param string $squareLocationId Square location ID
     * @param array $move Stock move data
     */
    private function syncSingleStockMove(string $squareLocationId, array $move): void
    {
        $stockMove = [
            'type' => 'STOCK_MOVE',
            'from_location' => $move['from_location'],
            'to_location' => $move['to_location'],
            'item_id' => $this->getSquareItemId($move['stock_id']),
            'quantity' => $move['quantity'],
            'reason' => $move['reason'] ?? 'Manual stock move',
            'timestamp' => date('Y-m-d H:i:s'),
            'fa_move_id' => $move['move_id'] ?? null
        ];
        
        $this->stockAdapter->sendStockMove($squareLocationId, $stockMove);
        $this->eventDao->logStockMove($stockMove, true);
    }

    /**
     * Gets Square item ID from FA stock ID.
     * 
     * @param int $stockId FA stock ID
     * @return string Square item ID
     * @throws StockEventException if mapping not found
     */
    private function getSquareItemId(int $stockId): string
    {
        $mapping = $this->locationMappingDao->getSquareItemId($stockId);
        if (!$mapping) {
            throw new StockEventException("Square item mapping not found for FA stock ID: $stockId");
        }
        return $mapping;
    }

    /**
     * Validates stock moves data.
     * 
     * @param array $stockMoves Stock moves data
     * @throws StockEventException on validation failure
     */
    private function validateStockMoves(array $stockMoves): void
    {
        if (empty($stockMoves)) {
            throw new StockEventException("Stock moves data cannot be empty");
        }
        
        foreach ($stockMoves as $move) {
            if (empty($move['stock_id'])) {
                throw new StockEventException("Stock ID is required for stock moves");
            }
            
            if (!isset($move['quantity']) || !is_numeric($move['quantity'])) {
                throw new StockEventException("Valid quantity is required for stock moves");
            }
        }
    }
}