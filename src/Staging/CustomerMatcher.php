<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\Square\Contracts\CustomerMatcherInterface;

class CustomerMatcher implements CustomerMatcherInterface
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * @var array
     */
    private $mappings;

    public function __construct(string $tablePrefix = '0_')
    {
        $this->tablePrefix = $tablePrefix;
        $this->mappings = [];
    }

    public function findOrCreateDebtor(array $customerData): int
    {
        $name = $customerData['name'] ?? '';
        $email = $customerData['email'] ?? '';

        if ($email !== '') {
            $sql = "SELECT debtor_no FROM {$this->tablePrefix}debtors_master WHERE email = '" . \db_escape($email) . "'";
            $result = \db_query($sql);
            if ($row = \db_fetch($result)) {
                return (int)$row['debtor_no'];
            }
        }

        if ($name !== '') {
            $sql = "SELECT debtor_no FROM {$this->tablePrefix}debtors_master WHERE name = '" . \db_escape($name) . "'";
            $result = \db_query($sql);
            if ($row = \db_fetch($result)) {
                return (int)$row['debtor_no'];
            }
        }

        $debtorNo = (int)$this->getNextDebtorNo();
        $this->insertDebtor($debtorNo, $customerData);

        return $debtorNo;
    }

    public function findOrCreateBranch(int $debtorNo, array $branchData): int
    {
        $branchCode = $branchData['branch_code'] ?? 'DEFAULT';
        $sql = "SELECT branch_code FROM {$this->tablePrefix}cust_branch WHERE debtor_no = " . (int)$debtorNo
            . " AND branch_code = '" . \db_escape($branchCode) . "'";
        $result = \db_query($sql);

        if (\db_fetch($result)) {
            $sql = "SELECT branch_code FROM {$this->tablePrefix}cust_branch WHERE debtor_no = " . (int)$debtorNo
                . " AND branch_code = '" . \db_escape($branchCode) . "'";
            $result = \db_query($sql);
            $row = \db_fetch($result);
            return (int)$row['branch_code'];
        }

        $sql = "INSERT INTO {$this->tablePrefix}cust_branch (debtor_no, branch_code, br_name, br_address, area, salesman, tax_group_id, sales_type)
                VALUES (" . (int)$debtorNo . ", '" . \db_escape($branchCode) . "', '" . \db_escape($branchData['name'] ?? '')
            . "', '" . \db_escape($branchData['address'] ?? '') . "', 0, 0, 1, 1)";
        \db_query($sql);

        return (int)\db_insert_id();
    }

    public function matchSquareCustomerToFaDebtor(string $squareCustomerId): ?int
    {
        $sql = "SELECT fa_debtor_no FROM {$this->tablePrefix}square_customer_mappings WHERE square_customer_id = '" . \db_escape($squareCustomerId) . "'";
        $result = \db_query($sql);
        if ($row = \db_fetch($result)) {
            return (int)$row['fa_debtor_no'];
        }
        return null;
    }

    public function linkSquareCustomer(string $squareCustomerId, int $debtorNo): void
    {
        $sql = "INSERT INTO {$this->tablePrefix}square_customer_mappings (square_customer_id, fa_debtor_no)
                VALUES ('" . \db_escape($squareCustomerId) . "', " . $debtorNo . ")
                ON DUPLICATE KEY UPDATE fa_debtor_no = " . $debtorNo;
        \db_query($sql);
    }

    private function getNextDebtorNo(): int
    {
        $sql = "SELECT MAX(debtor_no) + 1 AS next_no FROM {$this->tablePrefix}debtors_master";
        $result = \db_query($sql);
        $row = \db_fetch($result);
        return $row['next_no'] ?? 1;
    }

    private function insertDebtor(int $debtorNo, array $data): void
    {
        $name = \db_escape($data['name'] ?? 'Unknown');
        $email = \db_escape($data['email'] ?? '');
        $phone = \db_escape($data['phone'] ?? '');
        $address = \db_escape($data['address'] ?? '');

        $sql = "INSERT INTO {$this->tablePrefix}debtors_master (debtor_no, name, debtor_ref, address, phone, email, curr_code, tax_included, credit_limit, discount, payment_terms, notes, inactive)
                VALUES (" . $debtorNo . ", '" . $name . "', '" . $name . "', '" . $address . "', '" . $phone . "', '" . $email
            . "', 'CAD', 0, 0, 0, '30', '', 0)";
        \db_query($sql);
    }
}
