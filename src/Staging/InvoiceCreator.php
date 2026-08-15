<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\Square\Contracts\InvoiceCreatorInterface;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;

class InvoiceCreator implements InvoiceCreatorInterface
{
    /**
     * @var string
     */
    private $tablePrefix;

    public function __construct(string $tablePrefix = '0_')
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function createSalesInvoice(
        int $debtorNo,
        int $branchNo,
        \DateTimeInterface $date,
        array $lineItems,
        array $taxes,
        array $options = []
    ): int {
        $reference = $options['reference'] ?? $this->getNextReference();
        $orderNo = $this->createBlankOrder($debtorNo, $branchNo, $date, $reference, $options);

        foreach ($lineItems as $item) {
            $this->addOrderLine($orderNo, $item);
        }

        $this->processOrder($orderNo);

        return $orderNo;
    }

    public function recordPayment(int $invoiceNo, float $amount, \DateTimeInterface $date, int $posId): int
    {
        $sql = "INSERT INTO {$this->tablePrefix}debtor_trans (type, trans_no, tran_date, debtor_no, amount, reference, tpe, alloc, rate, ov_amount, ov_gst, ov_freight, ov_discount, cheque_no)
                SELECT 10, " . (int)$invoiceNo . ", '" . $date->format('Y-m-d') . "', debtor_no, " . (-$amount)
            . ", CONCAT('Square: ', " . (int)$invoiceNo . "), 1, 0, 1, 0, 0, 0, 0, ''"
            . " FROM {$this->tablePrefix}debtors_master"
            . " WHERE debtor_no = (SELECT debtor_no FROM {$this->tablePrefix}sales_orders WHERE order_no = " . (int)$invoiceNo . ")";
        \db_query($sql);

        $sql = "INSERT INTO {$this->tablePrefix}bank_trans (type, trans_no, bank_act, ref, amount, trans_date, posted)
                VALUES (12, " . (int)$invoiceNo . ", " . (int)$posId . ", 'Square Payment:" . (int)$invoiceNo . "', " . $amount . ", '" . $date->format('Y-m-d') . "', 1)";
        \db_query($sql);

        return \db_insert_id();
    }

    private function getNextReference(): string
    {
        $sql = "SELECT MAX(reference) + 1 AS next_ref FROM {$this->tablePrefix}sales_orders";
        $result = \db_query($sql);
        $row = \db_fetch($result);
        return (string)($row['next_ref'] ?? '1000');
    }

    private function createBlankOrder(int $debtorNo, int $branchNo, \DateTimeInterface $date, string $reference, array $options): int
    {
        $deliveryDate = $options['delivery_date'] ?? $date;
        $comments = \db_escape($options['comments'] ?? 'Imported from Square');

        $sql = "INSERT INTO {$this->tablePrefix}sales_orders (order_no, debtor_no, branch_code, ord_date, deliver_date, reference, comments, sales_type, freight_cost, dimension_id, dimension2_id, payment, alloc, from_stk_loc, delivery_address)
                VALUES (" . (int)$reference . ", " . $debtorNo . ", " . $branchNo . ", '" . $date->format('Y-m-d')
            . "', '" . $deliveryDate->format('Y-m-d') . "', '" . \db_escape($reference) . "', '" . $comments
            . "', 1, 0, 0, 0, 0, 0, '" . \db_escape($options['location'] ?? '') . "', '" . \db_escape($options['delivery_address'] ?? '') . "')";
        \db_query($sql);

        return (int)$reference;
    }

    private function addOrderLine(int $orderNo, array $item): void
    {
        $sql = "INSERT INTO {$this->tablePrefix}sales_order_details (order_no, stk_code, unit_price, quantity, discount_percent, price_type, src_id, trans_id)
                VALUES (" . $orderNo . ", '" . \db_escape($item['stock_id'] ?? '') . "', "
            . ($item['unit_price'] ?? 0) . ", " . ($item['quantity'] ?? 1) . ", 0, 0, 0, 0)";
        \db_query($sql);
    }

    private function processOrder(int $orderNo): void
    {
        $sql = "UPDATE {$this->tablePrefix}sales_orders SET trans_type = 10 WHERE order_no = " . $orderNo;
        \db_query($sql);
    }
}
