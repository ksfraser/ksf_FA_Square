<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Reconciliation Exception
 * 
 * Exception thrown during payment reconciliation operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for payment reconciliation
 */
class ReconciliationException extends \Exception
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
            case 8001:
                return "Payment reconciliation failed. Please check the payment data.";
            case 8002:
                return "Invalid reconciliation data. Please verify the payment information.";
            case 8003:
                return "Reconciliation processing error occurred.";
            case 8004:
                return "Payment matching failed. Please check the payment IDs.";
            default:
                return "Payment reconciliation operation failed.";
        }
    }
}