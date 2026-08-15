<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Refund Processing Exception
 * 
 * Exception thrown during refund processing operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for refund processing
 */
class RefundProcessingException extends \Exception
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
            case 7001:
                return "Refund processing failed. Please check the refund data.";
            case 7002:
                return "Invalid refund data. Please verify the refund information.";
            case 7003:
                return "Original payment not found. Please check the payment ID.";
            case 7004:
                return "Refund authorization failed. Please try again.";
            case 7005:
                return "Refund processing error occurred.";
            default:
                return "Refund processing operation failed.";
        }
    }
}