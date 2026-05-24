<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

/**
 * Exception thrown when webhook operations fail.
 * 
 * @UML Note: Exception handling in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookCreationException extends \Exception
{
    /**
     * Creates a new webhook creation exception.
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