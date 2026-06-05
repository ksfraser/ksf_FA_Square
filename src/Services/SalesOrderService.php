<?php
declare(strict_types=1);

/**
 * Sales Order Service
 * 
 * Handles sales order synchronization between Square and FrontAccounting.
 * Creates, updates, and manages sales orders and credit notes.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-02.01 - Order Synchronization, FR-02.02 - Order Status Tracking
 */
class SalesOrderService implements SalesOrderServiceInterface
{
    private SalesOrdersDAO $salesOrdersDao;
    private SquareOrderAdapter $orderAdapter;
    private TaxService $taxService;
    private CustomerService $customerService;
    private PaymentService $paymentService;
    private string $tablePrefix;

    public function __construct(
        SalesOrdersDAO $salesOrdersDao,
        SquareOrderAdapter $orderAdapter,
        TaxService $taxService,
        CustomerService $customerService,
        PaymentService $paymentService
    ) {
        $this->salesOrdersDao = $salesOrdersDao;
        $this->orderAdapter = $orderAdapter;
        $this->taxService = $taxService;
        $this->customerService = $customerService;
        $this->paymentService = $paymentService;
        $this->tablePrefix = get_company_pref('table_prefix');
    }

    /**
     * Creates a sales order from Square order data.
     * 
     * @param array $squareOrder Square order data
     * @return array FA sales order data
     * @throws SalesOrderException on creation failure
     */
    public function createSalesOrderFromSquare(array $squareOrder): array
    {
        try {
            // Validate Square order data
            $this->validateSquareOrder($squareOrder);
            
            // Get or create customer
            $customer = $this->customerService->syncCustomerToSquare($squareOrder['customer']);
            
            // Calculate taxes
            $taxData = $this->taxService->calculateSquareTaxes($squareOrder);
            
            // Convert to FA order format
            $faOrder = $this->orderAdapter->convertToFAOrder($squareOrder, $customer, $taxData);
            
            // Create sales order in FA
            $orderId = $this->salesOrdersDao->insertOrder($faOrder);
            
            // Create order items
            foreach ($squareOrder['line_items'] as $item) {
                $faItem = $this->orderAdapter->convertToFAOrderItem($item, $orderId);
                $this->salesOrdersDao->insertOrderItem($faItem);
            }
            
            // Update mapping
            $this->salesOrdersDao->updateMappingBySquareId(
                $squareOrder['id'],
                ['fa_order_id' => $orderId]
            );
            
            // Log order creation
            $this->salesOrdersDao->logOrderEvent([
                'fa_order_id' => $orderId,
                'square_order_id' => $squareOrder['id'],
                'event_type' => 'created',
                'event_data' => json_encode($squareOrder),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $faOrder['order_id'] = $orderId;
            return $faOrder;
            
        } catch (\Exception $e) {
            throw new SalesOrderException("Failed to create sales order: " . $e->getMessage());
        }
    }

    /**
     * Updates an existing sales order.
     * 
     * @param int $orderId FA order ID
     * @param array $updates Update data
     * @throws SalesOrderException on update failure
     */
    public function updateSalesOrder(int $orderId, array $updates): void
    {
        try {
            // Validate order exists
            $order = $this->salesOrdersDao->getOrder($orderId);
            if (!$order) {
                throw new SalesOrderException("Order not found: {$orderId}");
            }
            
            // Validate update data
            $this->validateOrderUpdate($updates);
            
            // Update order
            $this->salesOrdersDao->updateOrder($orderId, $updates);
            
            // Log update event
            $this->salesOrdersDao->logOrderEvent([
                'fa_order_id' => $orderId,
                'event_type' => 'updated',
                'event_data' => json_encode($updates),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            throw new SalesOrderException("Failed to update sales order: " . $e->getMessage());
        }
    }

    /**
     * Creates a sales credit note from a Square refund.
     * 
     * @param int $originalOrderId Original FA order ID
     * @param string $reason Reason for the credit note
     * @return array FA credit note data
     * @throws SalesOrderException on creation failure
     */
    public function createSalesCreditNote(int $originalOrderId, string $reason): array
    {
        try {
            // Get original order
            $originalOrder = $this->salesOrdersDao->getOrder($originalOrderId);
            if (!$originalOrder) {
                throw new SalesOrderException("Original order not found: {$originalOrderId}");
            }
            
            // Get customer from original order
            $customer = $this->customerService->getCustomerByDebtorNo($originalOrder['debtor_no']);
            
            // Create credit note data
            $creditNote = [
                'debtor_no' => $originalOrder['debtor_no'],
                'type' => 11, // Credit note type
                'order_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'order_ref' => 'CN-' . uniqid(),
                'reference' => $reason,
                'tax_included' => $originalOrder['tax_included'],
                'total' => 0, // Will be calculated from items
                'notes' => "Square refund: {$reason}",
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Create credit note in FA
            $creditNoteId = $this->salesOrdersDao->insertOrder($creditNote);
            
            // Get original order items and create credit note items
            $originalItems = $this->salesOrdersDao->getOrderItems($originalOrderId);
            foreach ($originalItems as $item) {
                // Create credit note item (negative quantity)
                $creditItem = [
                    'order_id' => $creditNoteId,
                    'item_code' => $item['item_code'],
                    'description' => $item['description'],
                    'quantity' => -$item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => -$item['line_total'],
                    'tax_amount' => -$item['tax_amount'],
                    'discount_amount' => 0
                ];
                
                $this->salesOrdersDao->insertOrderItem($creditItem);
                $creditNote['total'] += $creditItem['line_total'];
            }
            
            // Update credit note total
            $this->salesOrdersDao->updateOrder($creditNoteId, ['total' => $creditNote['total']]);
            
            // Update mapping
            $this->salesOrdersDao->updateMappingByCreditNoteId(
                $creditNoteId,
                ['original_order_id' => $originalOrderId]
            );
            
            // Log credit note creation
            $this->salesOrdersDao->logOrderEvent([
                'fa_order_id' => $creditNoteId,
                'original_order_id' => $originalOrderId,
                'event_type' => 'credit_note_created',
                'event_data' => json_encode(['reason' => $reason]),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $creditNote['order_id'] = $creditNoteId;
            return $creditNote;
            
        } catch (\Exception $e) {
            throw new SalesOrderException("Failed to create credit note: " . $e->getMessage());
        }
    }

    /**
     * Gets sales order by Square order ID.
     * 
     * @param string $squareOrderId Square order ID
     * @return array|null FA sales order data or null if not found
     */
    public function getSalesOrderBySquareId(string $squareOrderId): ?array
    {
        return $this->salesOrdersDao->getBySquareId($squareOrderId);
    }

    /**
     * Gets sales order statistics.
     * 
     * @return array Statistics array
     */
    public function getOrderStatistics(): array
    {
        return $this->salesOrdersDao->getOrderStatistics();
    }

    /**
     * Validates Square order data.
     * 
     * @param array $squareOrder Square order data
     * @throws SalesOrderException on validation failure
     */
    private function validateSquareOrder(array $squareOrder): void
    {
        if (empty($squareOrder['id'])) {
            throw new SalesOrderException("Square order ID is required");
        }
        
        if (empty($squareOrder['customer']) || !isset($squareOrder['customer']['id'])) {
            throw new SalesOrderException("Customer information is required");
        }
        
        if (empty($squareOrder['line_items']) || !is_array($squareOrder['line_items'])) {
            throw new SalesOrderException("Line items are required");
        }
        
        foreach ($squareOrder['line_items'] as $item) {
            if (empty($item['item_id'])) {
                throw new SalesOrderException("Item ID is required for line items");
            }
            
            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                throw new SalesOrderException("Valid quantity is required for line items");
            }
            
            if (!isset($item['base_price_money']) || !isset($item['base_price_money']['amount'])) {
                throw new SalesOrderException("Valid price is required for line items");
            }
        }
    }

    /**
     * Validates order update data.
     * 
     * @param array $updates Update data
     * @throws SalesOrderException on validation failure
     */
    private function validateOrderUpdate(array $updates): void
    {
        $allowedFields = ['reference', 'notes', 'total', 'tax_included', 'order_date', 'due_date'];
        
        foreach (array_keys($updates) as $field) {
            if (!in_array($field, $allowedFields)) {
                throw new SalesOrderException("Invalid update field: {$field}");
            }
        }
        
        if (isset($updates['total']) && !is_numeric($updates['total'])) {
            throw new SalesOrderException("Total must be a numeric value");
        }
    }
}