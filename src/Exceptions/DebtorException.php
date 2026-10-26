<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Exceptions;

use Ksfraser\Exceptions\FrontAccounting\FAException;

class DebtorException extends FAException
{
    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function notFound(string $debtorNo): self
    {
        return new self("Debtor not found: {$debtorNo}");
    }

    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
    public static function correctionFailed(string $reason): self
    {
        return new self("Debtor correction failed: {$reason}");
    }
}
