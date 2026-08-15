<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Analytics Exception
 * 
 * Exception thrown during analytics operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for analytics
 */
class AnalyticsException extends \Exception
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
            case 10001:
                return "Analytics processing failed. Please check the data and filters.";
            case 10002:
                return "Invalid analytics data. Please verify the information.";
            case 10003:
                return "Invalid filters. Please check the filter parameters.";
            case 10004:
                return "Analytics query failed. Please try again.";
            case 10005:
                return "Analytics calculation error occurred.";
            default:
                return "Analytics operation failed.";
        }
    }
}