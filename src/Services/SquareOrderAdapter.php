<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Square Order Adapter
 * 
 * Handles conversion between Square orders and FA sales orders.
 * 
 * @UML Note: Adapter pattern diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-02.01 - Order Synchronization
 */
class SquareOrderAdapter
{
    private TaxService $taxService;

    public function __construct(TaxService $taxService)
    {
        $this->taxService = $taxService;
    }

    /**
     * Converts a Square order to FA sales order format.
     * 
     * @param array $squareOrder Square order data
     * @return array FA sales order data
     */
    public function convertToFASalesOrder(array $squareOrder): array
    {
        $customer = $squareOrder['customer'] ?? [];
        $taxData = [
            'tax_included' => false,
            'total' => isset($squareOrder['total_money']['amount'])
                ? $squareOrder['total_money']['amount'] / 100
                : 0
        ];

        return $this->convertToFAOrder($squareOrder, $customer, $taxData);
    }

    /**
     * Converts Square order to FA order format.
     * 
     * @param array $squareOrder Square order data
     * @param array $customer FA customer data
     * @param array $taxData Tax calculation data
     * @return array FA order data
     */
    public function convertToFAOrder(array $squareOrder, array $customer, array $taxData): array
    {
        $orderDate = $squareOrder['created_at'] ?? date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime($orderDate . ' +30 days'));
        
        return [
            'debtor_no' => $customer['debtor_no'],
            'type' => 10, // Sales order type
            'order_date' => date('Y-m-d', strtotime($orderDate)),
            'due_date' => $dueDate,
            'order_ref' => 'SQ-' . $squareOrder['id'],
            'reference' => $squareOrder['reference'] ?? 'Square Order',
            'tax_included' => $taxData['tax_included'],
            'total' => $taxData['total'],
            'notes' => $squareOrder['note'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Converts Square order line item to FA order item.
     * 
     * @param array $squareItem Square order line item
     * @param int $orderId FA order ID
     * @return array FA order item data
     */
    public function convertToFAOrderItem(array $squareItem, int $orderId): array
    {
        $unitPrice = $squareItem['base_price_money']['amount'] / 100; // Convert cents to decimal
        $quantity = $squareItem['quantity'];
        $lineTotal = $unitPrice * $quantity;
        
        // Calculate tax if applicable
        $taxAmount = 0;
        if (isset($squareItem['applied_taxes']) && is_array($squareItem['applied_taxes'])) {
            foreach ($squareItem['applied_taxes'] as $tax) {
                $taxAmount += $tax['applied_money']['amount'] / 100;
            }
        }
        
        return [
            'order_id' => $orderId,
            'item_code' => $this->getFAItemCode($squareItem['item_id']),
            'description' => $squareItem['name'] ?? 'Unknown Item',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $squareItem['discount_money']['amount'] / 100 ?? 0,
            'notes' => $squareItem['note'] ?? '',
            'sequence' => $squareItem['sequence_number'] ?? 0
        ];
    }

    /**
     * Gets FA item code from Square item ID.
     * 
     * @param string $squareItemId Square item ID
     * @return string FA item code
     */
    private function getFAItemCode(string $squareItemId): string
    {
        // This would normally query a mapping table
        // For now, return a placeholder
        return 'SQ-' . $squareItemId;
    }

    /**
     * Converts FA order to Square order format.
     * 
     * @param array $faOrder FA order data
     * @return array Square order data
     */
    public function convertToSquareOrder(array $faOrder): array
    {
        return [
            'reference' => $faOrder['order_ref'],
            'note' => $faOrder['notes'],
            'customer_id' => $faOrder['customer_square_id'] ?? '',
            'location_id' => $faOrder['location_id'] ?? '',
            'line_items' => $this->convertLineItemsToSquare($faOrder['items']),
            'taxes' => $this->convertTaxesToSquare($faOrder['taxes']),
            'total_money' => [
                'amount' => (int)($faOrder['total'] * 100),
                'currency' => 'USD'
            ],
            'created_at' => $faOrder['created_at'] ?? date('Y-m-d H:i:s')
        ];
    }

    /**
     * Converts FA order items to Square line items.
     * 
     * @param array $faItems FA order items
     * @return array Square line items
     */
    private function convertLineItemsToSquare(array $faItems): array
    {
        $squareItems = [];
        
        foreach ($faItems as $item) {
            $squareItems[] = [
                'item_id' => $this->getSquareItemId($item['item_code']),
                'quantity' => $item['quantity'],
                'base_price_money' => [
                    'amount' => (int)($item['unit_price'] * 100),
                    'currency' => 'USD'
                ],
                'applied_money' => [
                    'amount' => (int)($item['tax_amount'] * 100),
                    'currency' => 'USD'
                ],
                'note' => $item['notes'] ?? ''
            ];
        }
        
        return $squareItems;
    }

    /**
     * Converts FA taxes to Square taxes.
     * 
     * @param array $faTaxes FA tax data
     * @return array Square taxes
     */
    private function convertTaxesToSquare(array $faTaxes): array
    {
        $squareTaxes = [];
        
        foreach ($faTaxes as $tax) {
            $squareTaxes[] = [
                'tax_id' => $this->getSquareTaxId($tax['tax_type_id']),
                'applied_money' => [
                    'amount' => (int)($tax['amount'] * 100),
                    'currency' => 'USD'
                ]
            ];
        }
        
        return $squareTaxes;
    }

    /**
     * Gets Square item ID from FA item code.
     * 
     * @param string $faItemCode FA item code
     * @return string Square item ID
     */
    private function getSquareItemId(string $faItemCode): string
    {
        // This would normally query a mapping table
        // For now, return a placeholder
        return str_replace('SQ-', '', $faItemCode);
    }

    /**
     * Gets Square tax ID from FA tax type ID.
     * 
     * @param int $faTaxTypeId FA tax type ID
     * @return string Square tax ID
     */
    private function getSquareTaxId(int $faTaxTypeId): string
    {
        // This would normally query a mapping table
        // For now, return a placeholder
        return 'tax_' . $faTaxTypeId;
    }
}