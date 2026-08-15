<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

class StagingTableManager
{
    /**
     * @var string
     */
    private $tablePrefix;

    public function __construct(string $tablePrefix = '0_')
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function createStagingTables(): void
    {
        $this->createTransactionStagingTable();
        $this->createItemStagingTable();
        $this->createCustomerMappingTable();
        $this->createImportLogTable();
    }

    public function dropStagingTables(): void
    {
        \db_query("DROP TABLE IF EXISTS {$this->tablePrefix}square_staging_transactions");
        \db_query("DROP TABLE IF EXISTS {$this->tablePrefix}square_staging_items");
        \db_query("DROP TABLE IF EXISTS {$this->tablePrefix}square_customer_mappings");
        \db_query("DROP TABLE IF EXISTS {$this->tablePrefix}square_import_log");
    }

    public function insertStagingTransaction(array $data): int
    {
        $table = "{$this->tablePrefix}square_staging_transactions";
        $columns = implode(', ', array_keys($data));
        $values = "'" . implode("', '", array_map(function ($v) {
            return \db_escape((string)$v);
        }, array_values($data))) . "'";

        \db_query("INSERT INTO {$table} ({$columns}) VALUES ({$values})");
        return (int)\db_insert_id();
    }

    public function getUnprocessedTransactions(string $source = 'api'): array
    {
        $table = "{$this->tablePrefix}square_staging_transactions";
        $result = \db_query("SELECT * FROM {$table} WHERE status = 'staged' AND source = '" . \db_escape($source) . "' ORDER BY transaction_date ASC");
        $rows = [];
        while ($row = \db_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function markProcessed(int $id): void
    {
        $table = "{$this->tablePrefix}square_staging_transactions";
        \db_query("UPDATE {$table} SET status = 'imported' WHERE id = " . (int)$id);
    }

    public function markFailed(int $id, string $error): void
    {
        $table = "{$this->tablePrefix}square_staging_transactions";
        $error = \db_escape($error);
        \db_query("UPDATE {$table} SET status = 'failed', error_log = '{$error}' WHERE id = " . (int)$id);
    }

    private function createTransactionStagingTable(): void
    {
        $table = "{$this->tablePrefix}square_staging_transactions";
        \db_query("CREATE TABLE IF NOT EXISTS {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            source VARCHAR(16) NOT NULL DEFAULT 'api',
            square_transaction_id VARCHAR(32) NOT NULL,
            square_order_id VARCHAR(32) DEFAULT NULL,
            square_payment_id VARCHAR(32) DEFAULT NULL,
            location_id VARCHAR(32) DEFAULT NULL,
            customer_id VARCHAR(32) DEFAULT NULL,
            customer_name VARCHAR(128) DEFAULT NULL,
            transaction_date DATE DEFAULT NULL,
            total_amount DECIMAL(15,2) DEFAULT 0.00,
            tax_amount DECIMAL(15,2) DEFAULT 0.00,
            tip_amount DECIMAL(15,2) DEFAULT 0.00,
            discount_amount DECIMAL(15,2) DEFAULT 0.00,
            currency VARCHAR(8) DEFAULT 'CAD',
            raw_json LONGTEXT DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'staged',
            error_log TEXT DEFAULT NULL,
            fa_invoice_no INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_square_transaction (square_transaction_id),
            KEY idx_status (status),
            KEY idx_date (transaction_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function createItemStagingTable(): void
    {
        $table = "{$this->tablePrefix}square_staging_items";
        \db_query("CREATE TABLE IF NOT EXISTS {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            staging_transaction_id INT(11) DEFAULT NULL,
            sku VARCHAR(64) DEFAULT NULL,
            name VARCHAR(256) DEFAULT NULL,
            quantity INT(11) DEFAULT 0,
            unit_price DECIMAL(15,2) DEFAULT 0.00,
            total DECIMAL(15,2) DEFAULT 0.00,
            tax DECIMAL(15,2) DEFAULT 0.00,
            discount DECIMAL(15,2) DEFAULT 0.00,
            raw_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_staging_transaction (staging_transaction_id),
            KEY idx_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function createCustomerMappingTable(): void
    {
        $table = "{$this->tablePrefix}square_customer_mappings";
        \db_query("CREATE TABLE IF NOT EXISTS {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            square_customer_id VARCHAR(32) NOT NULL,
            fa_debtor_no INT(11) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_square_customer (square_customer_id),
            KEY idx_fa_debtor (fa_debtor_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function createImportLogTable(): void
    {
        $table = "{$this->tablePrefix}square_import_log";
        \db_query("CREATE TABLE IF NOT EXISTS {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            run_date DATETIME NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'api',
            orders_imported INT(11) DEFAULT 0,
            orders_skipped INT(11) DEFAULT 0,
            orders_failed INT(11) DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'completed',
            error_log TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_run_date (run_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
