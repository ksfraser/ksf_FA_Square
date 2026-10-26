<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class SquareException extends FAException
{
    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function apiError(string $endpoint, string $message, array $errors = []): self
    {
        $e = new self("Square API error on {$endpoint}: {$message}");
        $e->context['endpoint'] = $endpoint;
        $e->context['api_errors'] = $errors;
        return $e;
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function configurationError(string $key): self
    {
        return new self("Square configuration missing: {$key}");
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function importFailed(string $reason): self
    {
        return new self("Import failed: {$reason}");
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function exportFailed(string $reason): self
    {
        return new self("Export failed: {$reason}");
    }
}
