<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

use Exception;
use DateTimeInterface;

/**
 * Data Access Object for ksf_import_square_items table.
 *
 * Line items for staged transactions. Backward compatible with FA_ImportSquareUp.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class ItemStagingDAO
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
        return $this->tablePrefix . 'ksf_import_square_items';
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
            Category VARCHAR(32) NOT NULL DEFAULT '',
            Item VARCHAR(64) NOT NULL DEFAULT '',
            Price_Point_Name VARCHAR(32) NOT NULL DEFAULT '',
            stock_id VARCHAR(32) NOT NULL DEFAULT '',
            modifiers_applied VARCHAR(32) NOT NULL DEFAULT '',
            quantity INT(11) NOT NULL DEFAULT 0,
            gross_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discounts DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            net_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            transaction_id VARCHAR(32) NOT NULL,
            payment_id VARCHAR(32) NOT NULL DEFAULT '',
            device_name VARCHAR(32) NOT NULL DEFAULT '',
            notes VARCHAR(64) NOT NULL DEFAULT '',
            details VARCHAR(64) NOT NULL DEFAULT '',
            event_type VARCHAR(32) NOT NULL DEFAULT '',
            location VARCHAR(32) NOT NULL DEFAULT '',
            dining_option VARCHAR(16) NOT NULL DEFAULT '',
            Customer_id INT(11) NOT NULL DEFAULT 0,
            customer_name VARCHAR(64) NOT NULL DEFAULT '',
            customer_reference_id VARCHAR(16) NOT NULL DEFAULT '',
            unit VARCHAR(16) NOT NULL DEFAULT '',
            count DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            itemization_type VARCHAR(16) NOT NULL DEFAULT '',
            fulfillment_note VARCHAR(32) NOT NULL DEFAULT '',
            sku VARCHAR(64) DEFAULT NULL,
            name VARCHAR(256) DEFAULT NULL,
            unit_price DECIMAL(15,2) DEFAULT 0.00,
            total_amount DECIMAL(15,2) DEFAULT 0.00,
            discount_amount DECIMAL(15,2) DEFAULT 0.00,
            square_catalog_object_id VARCHAR(32) DEFAULT NULL,
            square_variation_id VARCHAR(32) DEFAULT NULL,
            raw_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transaction_id (transaction_id),
            KEY idx_payment_id (payment_id),
            KEY idx_stock_id (stock_id),
            KEY idx_sku (sku),
            KEY idx_date (Date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!\db_query($sql)) {
            throw new Exception(_("Cannot create ksf_import_square_items table: ") . \db_error_msg($db));
        }
    }

    /**
     * Upgrades existing table by adding new columns.
     */
    private function upgradeTable(): void
    {
        $tableName = $this->getTableName();

        $newColumns = [
            'sku VARCHAR(64) DEFAULT NULL',
            'name VARCHAR(256) DEFAULT NULL',
            'unit_price DECIMAL(15,2) DEFAULT 0.00',
            'total_amount DECIMAL(15,2) DEFAULT 0.00',
            'discount_amount DECIMAL(15,2) DEFAULT 0.00',
            'square_catalog_object_id VARCHAR(32) DEFAULT NULL',
            'square_variation_id VARCHAR(32) DEFAULT NULL',
            'raw_json LONGTEXT DEFAULT NULL',
        ];

        foreach ($newColumns as $colDef) {
            $colName = explode(' ', $colDef)[0];
            $check = @\db_query("SELECT {$colName} FROM {$tableName} LIMIT 1");
            if ($check === false) {
                $alterSql = "ALTER TABLE {$tableName} ADD COLUMN {$colDef}";
                \db_query($alterSql);
            }
        }
    }

    /**
     * Inserts an item into staging.
     *
     * @param array $data Item data
     * @return int Inserted ID
     * @throws Exception
     */
    public function insert(array $data): int
    {
        $tableName = $this->getTableName();

        $defaults = [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $data);

        if (isset($data['Date']) && $data['Date'] instanceof DateTimeInterface) {
            $data['Date'] = $data['Date']->format('Y-m-d');
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
                    $values[] = \db_escape((string)$value);
                }
            }
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr})";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert item: ") . \db_error_msg($db));
        }

        return (int)\db_insert_id();
    }

    /**
     * Gets all items for a transaction.
     *
     * @param string $transactionId
     * @return array
     */
    public function getByTransactionId(string $transactionId): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE transaction_id = " . \db_escape($transactionId) . " ORDER BY id ASC";
        $result = \db_query($sql);
        $items = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $items[] = $row;
                }
            }
        }

        return $items;
    }

    /**
     * Gets all items for a payment.
     *
     * @param string $paymentId
     * @return array
     */
    public function getByPaymentId(string $paymentId): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE payment_id = " . \db_escape($paymentId) . " ORDER BY id ASC";
        $result = \db_query($sql);
        $items = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $items[] = $row;
                }
            }
        }

        return $items;
    }

    /**
     * Deletes all items for a transaction.
     *
     * @param string $transactionId
     * @return void
     * @throws Exception
     */
    public function deleteByTransactionId(string $transactionId): void
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE transaction_id = " . \db_escape($transactionId);

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to delete items: ") . \db_error_msg($db));
        }
    }

    /**
     * Gets an item by its internal ID.
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
     * Updates an item by ID.
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
                    $sets[] = "{$key} = " . \db_escape((string)$value);
                }
            }
        }

        if (empty($sets)) {
            return;
        }

        $setsStr = implode(', ', $sets);
        $sql = "UPDATE {$tableName} SET {$setsStr} WHERE id = " . (int)$id;

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to update item: ") . \db_error_msg($db));
        }
    }

    /**
     * Deletes an item by ID.
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
            throw new Exception(_("Failed to delete item: ") . \db_error_msg($db));
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
            'Category', 'Item', 'Price_Point_Name',
            'stock_id', 'modifiers_applied',
            'quantity', 'gross_sales', 'discounts', 'net_sales', 'tax',
            'transaction_id', 'payment_id',
            'device_name', 'notes', 'details', 'event_type', 'location', 'dining_option',
            'Customer_id', 'customer_name', 'customer_reference_id',
            'unit', 'count', 'itemization_type', 'fulfillment_note',
            'sku', 'name', 'unit_price', 'total_amount', 'discount_amount',
            'square_catalog_object_id', 'square_variation_id',
            'raw_json',
            'created_at', 'updated_at',
        ];
    }
}
