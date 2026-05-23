<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for sales_orders table.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SalesOrdersDAO
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
     * Checks if an order with the given customer reference already exists.
     * 
     * @param string $customerRef Customer reference (Square payment ID)
     * @return bool True if order exists, false otherwise
     */
    public function orderExists(string $customerRef): bool
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->tablePrefix}sales_orders "
            . "WHERE customer_ref = " . db_escape($customerRef);
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false && (int)$row['cnt'] > 0) {
                return true;
            }
        }
        return false;
    }
}