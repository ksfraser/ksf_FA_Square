<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class TransactionException extends FAException
{
    public static function notFound(int $transactionId): self
    {
        return new self("Transaction not found: {$transactionId}");
    }

    public static function cannotBeCorrected(int $transactionId): self
    {
        return new self("Transaction cannot be corrected: {$transactionId}");
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
