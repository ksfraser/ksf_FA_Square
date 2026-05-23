<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for square_import_log table.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SquareImportLogDAO
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
     * Gets the last N import log entries.
     * 
     * @param int $limit Maximum number of entries to return
     * @return array Array of log entries
     */
    public function getRecentLogs(int $limit = 10): array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}square_import_log ORDER BY run_date DESC LIMIT " . (int)$limit;
        $result = db_query($sql);
        $logs = [];
        if ($result !== false && db_num_rows($result) > 0) {
            while ($row = db_fetch_assoc($result)) {
                if ($row === false) break;
                $logs[] = $row;
            }
        }
        return $logs;
    }

    /**
     * Checks if there are any import log entries.
     * 
     * @return bool True if there are log entries, false otherwise
     */
    public function hasLogs(): bool
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->tablePrefix}square_import_log";
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false && (int)$row['cnt'] > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inserts a new import log entry.
     * 
     * @param string $source Source of import (e.g., 'api')
     * @param int $imported Number of orders imported
     * @param int $skipped Number of orders skipped
     * @param int $failed Number of orders failed
     * @param string $status Status of import (e.g., 'completed')
     * @return void
     * @throws Exception if query fails
     */
    public function insertLog(
        string $source,
        int $imported,
        int $skipped,
        int $failed,
        string $status = 'completed'
    ): void {
        $sql = "INSERT INTO {$this->tablePrefix}square_import_log "
            . "(run_date, source, orders_imported, orders_skipped, orders_failed, status) VALUES ("
            . "'" . date('Y-m-d H:i:s') . "', "
            . db_escape($source) . ", "
            . $imported . ", "
            . $skipped . ", "
            . $failed . ", "
            . db_escape($status) . ")";
        if (!db_query($sql)) {
            throw new Exception(_("Failed to insert import log: ") . db_error());
        }
    }
}