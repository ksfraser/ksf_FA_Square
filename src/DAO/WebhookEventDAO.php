<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for webhook event logging.
 * 
 * Tracks incoming webhook events for audit and debugging purposes.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookEventDAO
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
     * Gets the full table name with prefix.
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tablePrefix . 'square_webhook_events';
    }

    /**
     * Ensures the table exists, creating it if necessary.
     *
     * @throws Exception if table creation fails
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTableName();

        $checkTable = \db_query("SHOW TABLES LIKE '{$tableName}'");
        if (\db_num_rows($checkTable) == 0) {
            $this->createTable();
        }
    }

    /**
     * Creates the table from scratch.
     *
     * @throws Exception
     */
    private function createTable(): void
    {
        $tableName = $this->getTableName();

        $sql = "CREATE TABLE {$tableName} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_data JSON NOT NULL,
            processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_successfully BOOLEAN DEFAULT TRUE,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_event_id (event_id),
            KEY idx_event_type (event_type),
            KEY idx_processed_at (processed_at),
            KEY idx_processed_successfully (processed_successfully)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!\db_query($sql)) {
            throw new Exception(_("Cannot create square_webhook_events table: ") . db_error());
        }
    }

    /**
     * Inserts a new webhook event log.
     *
     * @param array $data Event data
     * @return int Inserted ID
     * @throws Exception
     */
    public function insertEvent(array $data): int
    {
        $tableName = $this->getTableName();

        $defaults = [
            'processed_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'processed_successfully' => true,
        ];

        $data = array_merge($defaults, $data);

        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $fields[] = $key;
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = "'" . \db_escape((string)$value) . "'";
            }
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr})";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert webhook event: ") . db_error());
        }

        return (int)\db_insert_id();
    }

    /**
     * Gets all events.
     *
     * @param int $limit Maximum number of events to return
     * @param bool $onlyFailed Only return failed events
     * @return array Array of event records
     */
    public function getAllEvents(int $limit = 1000, bool $onlyFailed = false): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName}";
        
        if ($onlyFailed) {
            $sql .= " WHERE processed_successfully = FALSE";
        }
        
        $sql .= " ORDER BY processed_at DESC LIMIT " . (int)$limit;
        
        $result = \db_query($sql);
        $events = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $events[] = $row;
                }
            }
        }

        return $events;
    }

    /**
     * Gets events by type.
     *
     * @param string $eventType Event type to filter by
     * @param int $limit Maximum number of events to return
     * @return array Array of event records
     */
    public function getEventsByType(string $eventType, int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE event_type = '" . \db_escape($eventType) . "' 
                ORDER BY processed_at DESC LIMIT " . (int)$limit;
        
        $result = \db_query($sql);
        $events = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $events[] = $row;
                }
            }
        }

        return $events;
    }

    /**
     * Gets events by date range.
     *
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate End date (Y-m-d)
     * @return array Array of event records
     */
    public function getEventsByDateRange(string $fromDate, string $toDate): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE processed_at >= '" . \db_escape($fromDate . " 00:00:00") . "' 
                AND processed_at <= '" . \db_escape($toDate . " 23:59:59") . "'
                ORDER BY processed_at DESC";
        
        $result = \db_query($sql);
        $events = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $events[] = $row;
                }
            }
        }

        return $events;
    }

    /**
     * Gets failed events.
     *
     * @param int $limit Maximum number of events to return
     * @return array Array of failed event records
     */
    public function getFailedEvents(int $limit = 100): array
    {
        return $this->getAllEvents($limit, true);
    }

    /**
     * Marks an event as failed with error message.
     *
     * @param string $eventId Event ID
     * @param string $errorMessage Error message
     * @return void
     * @throws Exception
     */
    public function markEventFailed(string $eventId, string $errorMessage): void
    {
        $tableName = $this->getTableName();
        $sql = "UPDATE {$tableName} 
                SET processed_successfully = FALSE, 
                    error_message = '" . \db_escape($errorMessage) . "',
                    processed_at = NOW()
                WHERE event_id = '" . \db_escape($eventId) . "'";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to mark event as failed: ") . db_error());
        }
    }

    /**
     * Deletes old events (cleanup).
     *
     * @param int $daysToKeep Number of days to keep events
     * @return int Number of deleted events
     * @throws Exception
     */
    public function cleanupOldEvents(int $daysToKeep = 90): int
    {
        $tableName = $this->getTableName();
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
        
        $sql = "DELETE FROM {$tableName} WHERE processed_at < '" . \db_escape($cutoffDate) . "'";
        
        if (!\db_query($sql)) {
            throw new Exception(_("Failed to cleanup old events: ") . db_error());
        }
        
        return (int)\db_affected_rows();
    }

    /**
     * Gets event by ID.
     *
     * @param string $eventId Event ID
     * @return array|null
     */
    public function getEventById(string $eventId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE event_id = '" . \db_escape($eventId) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets event statistics.
     *
     * @return array Statistics array
     */
    public function getEventStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total events
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Successful events
        $successSql = "SELECT COUNT(*) as success FROM {$tableName} WHERE processed_successfully = TRUE";
        $successResult = \db_query($successSql);
        $success = 0;
        if ($successResult !== false) {
            $row = \db_fetch_assoc($successResult);
            $success = (int)($row['success'] ?? 0);
        }
        
        // Failed events
        $failed = $total - $success;
        
        // Events by type
        $typeSql = "SELECT event_type, COUNT(*) as count FROM {$tableName} GROUP BY event_type ORDER BY count DESC";
        $typeResult = \db_query($typeSql);
        $byType = [];
        if ($typeResult !== false) {
            while ($row = \db_fetch_assoc($typeResult)) {
                if ($row !== false) {
                    $byType[$row['event_type']] = (int)$row['count'];
                }
            }
        }
        
        return [
            'total_events' => $total,
            'successful_events' => $success,
            'failed_events' => $failed,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0,
            'events_by_type' => $byType,
        ];
    }
}