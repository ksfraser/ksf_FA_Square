<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for debtors_master table (customers).
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class DebtorsMasterDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets a customer by debtor_no.
     * 
     * @param int $debtorNo Debtor number
     * @return array|null Customer data or null if not found
     */
    public function getByDebtorNo(int $debtorNo): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}debtors_master WHERE debtor_no = " . $debtorNo;
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Gets a customer name by debtor_no.
     * 
     * @param int $debtorNo Debtor number
     * @return string Customer name or debtor number if not found
     */
    public function getCustomerName(int $debtorNo): string
    {
        $sql = "SELECT name FROM {$this->tablePrefix}debtors_master WHERE debtor_no = " . (int)$debtorNo;
        $result = db_query($sql);
        $customerName = (string)$debtorNo;
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row) {
                $customerName = $row['name'];
            }
        }
        return $customerName;
    }
}