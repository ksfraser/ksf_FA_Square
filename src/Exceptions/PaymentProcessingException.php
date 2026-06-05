<?php
declare(strict_types=1);

/**
 * Payment Processing Exception
 * 
 * Exception thrown during payment processing operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for payment processing
 */
class PaymentProcessingException extends \Exception
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
            case 6001:
                return "Payment processing failed. Please check the payment data.";
            case 6002:
                return "Invalid payment data. Please verify the payment information.";
            case 6003:
                return "Payment not found. Please check the payment ID.";
            case 6004:
                return "Customer not found for payment. Please verify the customer.";
            case 6005:
                return "Payment authorization failed. Please try again.";
            case 6006:
                return "Payment processing error occurred.";
            default:
                return "Payment processing operation failed.";
        }
    }
}