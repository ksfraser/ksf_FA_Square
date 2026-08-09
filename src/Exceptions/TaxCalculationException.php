<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

/**
 * Tax Calculation Exception
 * 
 * Exception thrown during tax calculation operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for tax management
 */
class TaxCalculationException extends \Exception
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
            case 4001:
                return "Tax calculation failed. Please check the tax rates and amounts.";
            case 4002:
                return "Invalid tax data. Please verify the tax information.";
            case 4003:
                return "Tax rate not found. Please check the tax rate ID.";
            case 4004:
                return "Tax mapping failed. Please try again.";
            case 4005:
                return "Tax calculation error occurred.";
            default:
                return "Tax calculation operation failed.";
        }
    }
}