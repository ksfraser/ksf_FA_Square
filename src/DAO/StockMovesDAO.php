<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for stock_moves table.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class StockMovesDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets the last modified timestamp for a stock item from stock moves.
     * 
     * @param string $stockId Stock ID
     * @return string|null Modified timestamp or null if not found
     */
    public function getLastModified(string $stockId): ?string
    {
        $sql = "SELECT modified_date FROM {$this->tablePrefix}stock_moves WHERE stock_id = " . db_escape($stockId) . " ORDER BY tran_date DESC LIMIT 1";
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false) {
                return $row['modified_date'];
            }
        }
        return null;
    }
}