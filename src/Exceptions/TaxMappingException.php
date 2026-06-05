<?php
declare(strict_types=1);

/**
 * Tax Mapping Exception
 * 
 * Exception thrown during tax mapping operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for tax mapping
 */
class TaxMappingException extends \Exception
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
            case 5001:
                return "Tax mapping failed. Please check the tax rate IDs.";
            case 5002:
                return "Invalid mapping data. Please verify the mapping information.";
            case 5003:
                return "Tax mapping not found. Please check the mapping ID.";
            case 5004:
                return "Tax update failed. Please try again.";
            case 5005:
                return "Tax mapping error occurred.";
            default:
                return "Tax mapping operation failed.";
        }
    }
}