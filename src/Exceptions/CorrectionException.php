<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class CorrectionException extends FAException
{
    public static function disabled(): self
    {
        return new self("Transaction correction is disabled");
    }

    public static function tooOld(int $maxAge): self
    {
        return new self("Transaction is older than the maximum allowed age of {$maxAge} seconds");
    }

    public static function failed(string $reason): self
    {
        return new self("Transaction correction failed: {$reason}");
    }
}
