<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * CRM Integration Exception
 * 
 * Exception thrown during CRM integration operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for customer management
 */
class CRMIntegrationException extends \Exception
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
            case 1001:
                return "Customer data validation failed. Please check the customer information.";
            case 1002:
                return "CRM synchronization failed. Please try again.";
            case 1003:
                return "Customer not found in the system.";
            case 1004:
                return "Communication tracking failed. Please try again.";
            default:
                return "Customer management operation failed.";
        }
    }
}