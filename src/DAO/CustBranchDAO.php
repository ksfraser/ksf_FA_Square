<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for cust_branch table (customer branches).
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class CustBranchDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets a branch by debtor_no and branch name.
     *
     * @param int $debtorNo Debtor number
     * @param string $branchName Branch name
     * @return array|null Branch data or null if not found
     */
    public function getByDebtorNoAndName(int $debtorNo, string $branchName): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}cust_branch "
            . "WHERE debtor_no = " . $debtorNo . " AND br_name = " . db_escape($branchName);
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
     * Gets a branch by debtor_no and branch code.
     *
     * @param int $debtorNo Debtor number
     * @param int $branchCode Branch code
     * @return array|null Branch data or null if not found
     */
    public function getByDebtorNoAndBranchCode(int $debtorNo, int $branchCode): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}cust_branch "
            . "WHERE debtor_no = " . $debtorNo . " AND branch_code = " . $branchCode;
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
     * Gets all branches for a debtor.
     *
     * @param int $debtorNo Debtor number
     * @return array Array of branch data
     */
    public function getByDebtorNo(int $debtorNo): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}cust_branch WHERE debtor_no = " . $debtorNo . " ORDER BY branch_code";
        $result = db_query($sql);
        $branches = [];
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                if ($row !== false) {
                    $branches[] = $row;
                }
            }
        }
        return $branches;
    }
}