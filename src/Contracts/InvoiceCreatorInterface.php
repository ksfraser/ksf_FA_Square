<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Contracts;

use Square\Models\Order;

interface InvoiceCreatorInterface
{
    public function createSalesInvoice(
        int $debtorNo,
        int $branchNo,
        \DateTimeInterface $date,
        array $lineItems,
        array $taxes,
        array $options = []
    ): int;

    public function recordPayment(int $invoiceNo, float $amount, \DateTimeInterface $date, int $posId): int;
}
