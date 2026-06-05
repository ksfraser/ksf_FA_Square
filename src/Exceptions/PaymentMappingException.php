<?php
declare(strict_types=1);

/**
 * Payment Mapping Exception
 * 
 * Exception thrown during payment mapping operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for payment mapping
 */
class PaymentMappingException extends \Exception
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
            case 9001:
                return "Payment mapping failed. Please check the payment IDs.";
            case 9002:
                return "Invalid mapping data. Please verify the mapping information.";
            case 9003:
                return "Payment mapping not found. Please check the mapping ID.";
            case 9004:
                return "Payment mapping update failed. Please try again.";
            case 9005:
                return "Payment mapping error occurred.";
            default:
                return "Payment mapping operation failed.";
        }
    }
}