<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

/**
 * Report Generation Exception
 * 
 * Exception thrown during report generation operations.
 * 
 * @UML Note: Exception hierarchy in ProjectDocs/UML.md
 * @BABOK Related: Error handling for report generation
 */
class ReportGenerationException extends \Exception
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
            case 11001:
                return "Report generation failed. Please check the report data.";
            case 11002:
                return "Invalid report data. Please verify the information.";
            case 11003:
                return "Invalid report type. Please check the report type.";
            case 11004:
                return "Report query failed. Please try again.";
            case 11005:
                return "Report generation error occurred.";
            default:
                return "Report generation operation failed.";
        }
    }
}