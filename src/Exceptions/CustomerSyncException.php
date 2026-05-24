<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

/**
 * Exception thrown when customer synchronization fails.
 * 
 * @UML Note: Exception handling in ProjectDocs/UML.md
 * @BABOK Related: FR-04.01 through FR-04.08 - Customer Management
 */
class CustomerSyncException extends \Exception
{
    /**
     * Creates a new customer sync exception.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}