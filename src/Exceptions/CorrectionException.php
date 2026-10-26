<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class CorrectionException extends FAException
{
    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function disabled(): self
    {
        return new self("Transaction correction is disabled");
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function tooOld(int $maxAge): self
    {
        return new self("Transaction is older than the maximum allowed age of {$maxAge} seconds");
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function failed(string $reason): self
    {
        return new self("Transaction correction failed: {$reason}");
    }
}
