<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Sales Order Exception
 * 
 * Exception thrown during sales order operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for order management
 */
class SalesOrderException extends \Exception
{
    private ?array $context;
    
    public function __construct(string $message, ?array $context = null, int $code = 0, ?\Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    /**
     * Gets additional context information.
     * 
     * @return array|null Context data
     */
    public function getContext(): ?array
    {
        return $this->context;
    }
    
    /**
     * Gets a user-friendly error message.
     * 
     * @return string User-friendly message
     */
    public function getUserMessage(): string
    {
        switch ($this->code) {
            case 3001:
                return "Order creation failed. Please check customer and item data.";
            case 3002:
                return "Invalid order data. Please verify the order information.";
            case 3003:
                return "Order not found. Please check the order ID.";
            case 3004:
                return "Order update failed. Please try again.";
            case 3005:
                return "Credit note creation failed. Please check the original order.";
            case 3006:
                return "Order synchronization error occurred.";
            default:
                return "Order management operation failed.";
        }
    }
}