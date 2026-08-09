<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class DebtorException extends FAException
{
    public static function notFound(string $debtorNo): self
    {
        return new self("Debtor not found: {$debtorNo}");
    }

    public static function correctionFailed(string $reason): self
    {
        return new self("Debtor correction failed: {$reason}");
    }
}
