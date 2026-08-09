<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for webhook subscription management.
 * 
 * Manages webhook subscriptions in our database for tracking and monitoring.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-05.01 through FR-05.07 - Webhook Management
 */
class WebhookSubscriptionDAO
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
        return $this->tablePrefix . 'square_webhook_subscriptions';
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
            square_id VARCHAR(64) NOT NULL,
            url VARCHAR(255) NOT NULL,
            events JSON NOT NULL,
            signature_key VARCHAR(64) NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_square_id (square_id),
            KEY idx_active (is_active),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!\db_query($sql)) {
            throw new Exception(_("Cannot create square_webhook_subscriptions table: ") . db_error());
        }
    }

    /**
     * Inserts a new webhook subscription.
     *
     * @param array $data Subscription data
     * @return int Inserted ID
     * @throws Exception
     */
    public function insertSubscription(array $data): int
    {
        $tableName = $this->getTableName();

        $defaults = [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
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
            throw new Exception(_("Failed to insert webhook subscription: ") . db_error());
        }

        return (int)\db_insert_id();
    }

    /**
     * Updates an existing webhook subscription.
     *
     * @param string $squareId Square subscription ID
     * @param array $data Updated data
     * @return void
     * @throws Exception
     */
    public function updateSubscription(string $squareId, array $data): void
    {
        $tableName = $this->getTableName();

        $sets = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $sets[] = "{$key} = NULL";
            } else {
                $sets[] = "{$key} = '" . \db_escape((string)$value) . "'";
            }
        }

        if (empty($sets)) {
            return;
        }

        $setsStr = implode(', ', $sets);
        $sql = "UPDATE {$tableName} SET {$setsStr} WHERE square_id = '" . \db_escape($squareId) . "'";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to update webhook subscription: ") . db_error());
        }
    }

    /**
     * Deletes a webhook subscription.
     *
     * @param string $squareId Square subscription ID
     * @return void
     * @throws Exception
     */
    public function deleteSubscription(string $squareId): void
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE square_id = '" . \db_escape($squareId) . "'";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to delete webhook subscription: ") . db_error());
        }
    }

    /**
     * Gets all active subscriptions.
     *
     * @return array Array of subscription records
     */
    public function getActiveSubscriptions(): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE is_active = 1 ORDER BY created_at DESC";
        
        $result = \db_query($sql);
        $subscriptions = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $subscriptions[] = $row;
                }
            }
        }

        return $subscriptions;
    }

    /**
     * Gets a subscription by Square ID.
     *
     * @param string $squareId Square subscription ID
     * @return array|null
     */
    public function getBySquareId(string $squareId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_id = '" . \db_escape($squareId) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Logs a webhook event.
     *
     * @param array $eventData Event data
     * @return int Logged event ID
     * @throws Exception
     */
    public function logEvent(array $eventData): int
    {
        $tableName = $this->tablePrefix . 'square_webhook_events';

        $defaults = [
            'processed_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $eventData);

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
            throw new Exception(_("Failed to log webhook event: ") . db_error());
        }

        return (int)\db_insert_id();
    }
}