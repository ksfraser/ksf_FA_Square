<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Stock Event Exception
 * 
 * Exception thrown during stock event operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for stock movements
 */
class StockEventException extends \Exception
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
            case 2001:
                return "Stock synchronization failed. Please check stock item mapping.";
            case 2002:
                return "Invalid stock movement data. Please verify the information.";
            case 2003:
                return "Square API error occurred during stock operation.";
            case 2004:
                return "Stock item not found in Square catalog.";
            default:
                return "Stock management operation failed.";
        }
    }
}