<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

use Exception;
use DateTimeInterface;

/**
 * Data Access Object for ksf_import_square_transactions table.
 *
 * This is the unified staging table for both CSV and API imports.
 * Backward compatible with existing FA_ImportSquareUp production data.
 *
 * Schema combines:
 * - Original CSV fields from FA_ImportSquareUp
 * - API-specific fields (raw_json, environment, status tracking)
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class TransactionStagingDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * Transaction status constants
     */
    public const STATUS_STAGED = 'staged';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MATCHED = 'matched';

    /**
     * Source constants
     */
    public const SOURCE_API = 'api';
    public const SOURCE_CSV = 'csv';

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
        return $this->tablePrefix . 'ksf_import_square_transactions';
    }

    /**
     * Ensures the table exists, creating it if necessary.
     * Uses ALTER TABLE to add new columns if they don't exist.
     *
     * @throws Exception if table creation fails
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTableName();

        $checkTable = \db_query("SHOW TABLES LIKE '{$tableName}'");
        if (\db_num_rows($checkTable) == 0) {
            $this->createTable();
        } else {
            $this->upgradeTable();
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
            Date DATE NOT NULL,
            Time VARCHAR(8) NOT NULL DEFAULT '',
            Timezone VARCHAR(64) NOT NULL DEFAULT '',
            gross_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discounts DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            service_charges DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gift_card_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            net_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tip DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            partial_refunds DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_collected DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            source VARCHAR(16) NOT NULL DEFAULT 'api',
            card DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            card_entry_methods VARCHAR(16) NOT NULL DEFAULT '',
            cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            square_gift_card DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            other_tender DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            other_tender_type VARCHAR(16) NOT NULL DEFAULT '',
            other_tender_note VARCHAR(32) NOT NULL DEFAULT '',
            fees DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            net_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            transaction_id VARCHAR(32) NOT NULL,
            payment_id VARCHAR(32) NOT NULL DEFAULT '',
            card_brand VARCHAR(16) NOT NULL DEFAULT '',
            PAN_suffix INT(11) NOT NULL DEFAULT 0,
            device_name VARCHAR(32) NOT NULL DEFAULT '',
            staff_name VARCHAR(16) NOT NULL DEFAULT '',
            staff_id VARCHAR(16) NOT NULL DEFAULT '',
            description VARCHAR(64) NOT NULL DEFAULT '',
            details VARCHAR(64) NOT NULL DEFAULT '',
            event_type VARCHAR(32) NOT NULL DEFAULT '',
            location VARCHAR(32) NOT NULL DEFAULT '',
            Dining_option VARCHAR(16) NOT NULL DEFAULT '',
            Customer_id INT(11) NOT NULL DEFAULT 0,
            customer_name VARCHAR(64) NOT NULL DEFAULT '',
            customer_reference_id VARCHAR(16) NOT NULL DEFAULT '',
            device_nickname VARCHAR(16) NOT NULL DEFAULT '',
            third_party_fees DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            deposit_id VARCHAR(32) NOT NULL DEFAULT '',
            deposit_date DATE DEFAULT NULL,
            deposit_details VARCHAR(64) NOT NULL DEFAULT '',
            fee_percentage_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            fee_fixed_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            refund_reason VARCHAR(64) NOT NULL DEFAULT '',
            discount_name VARCHAR(16) NOT NULL DEFAULT '',
            transaction_status VARCHAR(16) NOT NULL DEFAULT '',
            order_reference_id VARCHAR(16) NOT NULL DEFAULT '',
            fulfillment_note VARCHAR(32) NOT NULL DEFAULT '',
            free_processing_applied DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            channel VARCHAR(32) NOT NULL DEFAULT '',
            unattributed_tips DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            square_order_id VARCHAR(32) DEFAULT NULL,
            square_location_id VARCHAR(32) DEFAULT NULL,
            square_customer_id VARCHAR(32) DEFAULT NULL,
            environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
            status VARCHAR(16) NOT NULL DEFAULT 'staged',
            raw_json LONGTEXT DEFAULT NULL,
            error_log TEXT DEFAULT NULL,
            fa_invoice_no INT(11) DEFAULT NULL,
            fa_debtor_no INT(11) DEFAULT NULL,
            fa_branch_code INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_transaction_id (transaction_id),
            UNIQUE KEY idx_payment_id (payment_id),
            KEY idx_date (Date),
            KEY idx_status (status),
            KEY idx_environment (environment),
            KEY idx_source (source),
            KEY idx_deposit_id (deposit_id),
            KEY idx_fa_invoice (fa_invoice_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!\db_query($sql)) {
            throw new Exception(_("Cannot create ksf_import_square_transactions table: ") . db_error());
        }
    }

    /**
     * Upgrades existing table by adding new columns.
     * Safe for existing production data.
     */
    private function upgradeTable(): void
    {
        $tableName = $this->getTableName();

        $newColumns = [
            'square_order_id VARCHAR(32) DEFAULT NULL',
            'square_location_id VARCHAR(32) DEFAULT NULL',
            'square_customer_id VARCHAR(32) DEFAULT NULL',
            "environment VARCHAR(20) NOT NULL DEFAULT 'sandbox'",
            "status VARCHAR(16) NOT NULL DEFAULT 'staged'",
            'raw_json LONGTEXT DEFAULT NULL',
            'error_log TEXT DEFAULT NULL',
            'fa_invoice_no INT(11) DEFAULT NULL',
            'fa_debtor_no INT(11) DEFAULT NULL',
            'fa_branch_code INT(11) DEFAULT NULL',
        ];

        foreach ($newColumns as $colDef) {
            $colName = explode(' ', $colDef)[0];
            $check = @\db_query("SELECT {$colName} FROM {$tableName} LIMIT 1");
            if ($check === false) {
                $alterSql = "ALTER TABLE {$tableName} ADD COLUMN {$colDef}";
                \db_query($alterSql);
            }
        }

        $newIndexes = [
            'KEY idx_environment (environment)',
            'KEY idx_status (status)',
            'KEY idx_fa_invoice (fa_invoice_no)',
        ];

        foreach ($newIndexes as $indexDef) {
            $indexName = explode(' ', $indexDef)[1];
            $check = \db_query("SHOW INDEX FROM {$tableName} WHERE Key_name = '{$indexName}'");
            if (\db_num_rows($check) == 0) {
                $alterSql = "ALTER TABLE {$tableName} ADD INDEX {$indexDef}";
                @\db_query($alterSql);
            }
        }
    }

    /**
     * Inserts a transaction into staging.
     *
     * @param array $data Transaction data
     * @return int Inserted ID
     * @throws Exception
     */
    public function insert(array $data): int
    {
        $tableName = $this->getTableName();

        $defaults = [
            'source' => self::SOURCE_API,
            'status' => self::STATUS_STAGED,
            'environment' => 'sandbox',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $data);

        if (isset($data['Date']) && $data['Date'] instanceof DateTimeInterface) {
            $data['Date'] = $data['Date']->format('Y-m-d');
        }
        if (isset($data['deposit_date']) && $data['deposit_date'] instanceof DateTimeInterface) {
            $data['deposit_date'] = $data['deposit_date']->format('Y-m-d');
        }
        if (isset($data['raw_json']) && is_array($data['raw_json'])) {
            $data['raw_json'] = json_encode($data['raw_json']);
        }

        $allowedFields = $this->getAllowedFields();
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields, true)) {
                $fields[] = $key;
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . \db_escape((string)$value) . "'";
                }
            }
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr}) "
             . "ON DUPLICATE KEY UPDATE updated_at = NOW()";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert transaction: ") . db_error());
        }

        $id = (int)\db_insert_id();
        if ($id === 0) {
            $existing = $this->getByTransactionId($data['transaction_id']);
            if ($existing !== null) {
                return (int)$existing['id'];
            }
        }

        return $id;
    }

    /**
     * Gets a transaction by its Square transaction ID.
     *
     * @param string $transactionId
     * @return array|null
     */
    public function getByTransactionId(string $transactionId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE transaction_id = '" . \db_escape($transactionId) . "'";
        $result = \db_query($sql);

        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets a transaction by its Square payment ID.
     *
     * @param string $paymentId
     * @return array|null
     */
    public function getByPaymentId(string $paymentId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE payment_id = '" . \db_escape($paymentId) . "'";
        $result = \db_query($sql);

        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets transactions by status.
     *
     * @param string $status
     * @param string|null $environment
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public function getByStatus(
        string $status,
        ?string $environment = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE status = '" . \db_escape($status) . "'";

        if ($environment !== null) {
            $sql .= " AND environment = '" . \db_escape($environment) . "'";
        }
        if ($fromDate !== null) {
            $sql .= " AND Date >= '" . \db_escape($fromDate) . "'";
        }
        if ($toDate !== null) {
            $sql .= " AND Date <= '" . \db_escape($toDate) . "'";
        }

        $sql .= " ORDER BY Date ASC, Time ASC";

        $result = \db_query($sql);
        $rows = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Updates the status of a transaction.
     *
     * @param int $id
     * @param string $status
     * @param array|null $extraFields
     * @return void
     * @throws Exception
     */
    public function updateStatus(int $id, string $status, ?array $extraFields = null): void
    {
        $tableName = $this->getTableName();
        $sets = ["status = '" . \db_escape($status) . "'"];

        if ($extraFields !== null) {
            foreach ($extraFields as $key => $value) {
                if ($value === null) {
                    $sets[] = "{$key} = NULL";
                } else {
                    $sets[] = "{$key} = '" . \db_escape((string)$value) . "'";
                }
            }
        }

        $setsStr = implode(', ', $sets);
        $sql = "UPDATE {$tableName} SET {$setsStr} WHERE id = " . (int)$id;

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to update transaction status: ") . db_error());
        }
    }

    /**
     * Checks if a transaction ID already exists.
     *
     * @param string $transactionId
     * @return bool
     */
    public function exists(string $transactionId): bool
    {
        return $this->getByTransactionId($transactionId) !== null;
    }

    /**
     * Gets count of transactions by status.
     *
     * @param string|null $environment
     * @return array [status => count]
     */
    public function getStatusCounts(?string $environment = null): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT status, COUNT(*) AS cnt FROM {$tableName}";

        if ($environment !== null) {
            $sql .= " WHERE environment = '" . \db_escape($environment) . "'";
        }

        $sql .= " GROUP BY status";

        $result = \db_query($sql);
        $counts = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $counts[$row['status']] = (int)$row['cnt'];
                }
            }
        }

        return $counts;
    }

    /**
     * Gets a transaction by its internal ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE id = " . (int)$id;
        $result = \db_query($sql);

        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Updates a transaction by ID.
     *
     * @param int $id
     * @param array $data
     * @return void
     * @throws Exception
     */
    public function update(int $id, array $data): void
    {
        $tableName = $this->getTableName();

        if (isset($data['Date']) && $data['Date'] instanceof DateTimeInterface) {
            $data['Date'] = $data['Date']->format('Y-m-d');
        }
        if (isset($data['deposit_date']) && $data['deposit_date'] instanceof DateTimeInterface) {
            $data['deposit_date'] = $data['deposit_date']->format('Y-m-d');
        }
        if (isset($data['raw_json']) && is_array($data['raw_json'])) {
            $data['raw_json'] = json_encode($data['raw_json']);
        }

        $allowedFields = $this->getAllowedFields();
        $sets = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields, true)) {
                if ($value === null) {
                    $sets[] = "{$key} = NULL";
                } else {
                    $sets[] = "{$key} = '" . \db_escape((string)$value) . "'";
                }
            }
        }

        if (empty($sets)) {
            return;
        }

        $setsStr = implode(', ', $sets);
        $sql = "UPDATE {$tableName} SET {$setsStr} WHERE id = " . (int)$id;

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to update transaction: ") . db_error());
        }
    }

    /**
     * Deletes a transaction by ID.
     *
     * @param int $id
     * @return void
     * @throws Exception
     */
    public function delete(int $id): void
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE id = " . (int)$id;

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to delete transaction: ") . db_error());
        }
    }

    /**
     * Gets the list of allowed fields for insert/update.
     *
     * @return array
     */
    private function getAllowedFields(): array
    {
        return [
            'Date', 'Time', 'Timezone',
            'gross_sales', 'discounts', 'service_charges', 'gift_card_sales',
            'net_sales', 'tax', 'tip', 'partial_refunds', 'total_collected',
            'source', 'card', 'card_entry_methods', 'cash', 'square_gift_card',
            'other_tender', 'other_tender_type', 'other_tender_note',
            'fees', 'net_total',
            'transaction_id', 'payment_id', 'card_brand', 'PAN_suffix',
            'device_name', 'staff_name', 'staff_id', 'description', 'details',
            'event_type', 'location', 'Dining_option',
            'Customer_id', 'customer_name', 'customer_reference_id',
            'device_nickname', 'third_party_fees',
            'deposit_id', 'deposit_date', 'deposit_details',
            'fee_percentage_rate', 'fee_fixed_rate',
            'refund_reason', 'discount_name', 'transaction_status',
            'order_reference_id', 'fulfillment_note', 'free_processing_applied',
            'channel', 'unattributed_tips',
            'square_order_id', 'square_location_id', 'square_customer_id',
            'environment', 'status', 'raw_json', 'error_log',
            'fa_invoice_no', 'fa_debtor_no', 'fa_branch_code',
            'created_at', 'updated_at',
        ];
    }
}
