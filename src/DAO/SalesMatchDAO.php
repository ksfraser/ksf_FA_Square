<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for ksf_import_square_sales table.
 *
 * Tracks matches between Square transaction IDs and FA sales documents.
 * Backward compatible with existing FA_ImportSquareUp production data.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SalesMatchDAO
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
        return $this->tablePrefix . 'ksf_import_square_sales';
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
            $sql = "CREATE TABLE {$tableName} (
                ksf_import_square_sales_id INT(11) NOT NULL AUTO_INCREMENT,
                square_transaction_id VARCHAR(32) NOT NULL,
                sales_order_no VARCHAR(32) NOT NULL DEFAULT '',
                sales_delivery_no VARCHAR(32) NOT NULL DEFAULT '',
                sales_invoice_no VARCHAR(32) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (ksf_import_square_sales_id),
                UNIQUE KEY idx_square_transaction_id (square_transaction_id),
                KEY idx_sales_invoice (sales_invoice_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            if (!\db_query($sql)) {
                throw new Exception(_("Cannot create ksf_import_square_sales table: ") . db_error());
            }
        }
    }

    /**
     * Records a sales match.
     *
     * @param string $squareTransactionId
     * @param int|null $salesInvoiceNo
     * @param int|null $salesOrderNo
     * @param int|null $salesDeliveryNo
     * @return int
     * @throws Exception
     */
    public function insertMatch(
        string $squareTransactionId,
        ?int $salesInvoiceNo = null,
        ?int $salesOrderNo = null,
        ?int $salesDeliveryNo = null
    ): int {
        $tableName = $this->getTableName();

        $fields = ['square_transaction_id'];
        $values = ["'" . \db_escape($squareTransactionId) . "'"];

        if ($salesInvoiceNo !== null) {
            $fields[] = 'sales_invoice_no';
            $values[] = (int)$salesInvoiceNo;
        }
        if ($salesOrderNo !== null) {
            $fields[] = 'sales_order_no';
            $values[] = (int)$salesOrderNo;
        }
        if ($salesDeliveryNo !== null) {
            $fields[] = 'sales_delivery_no';
            $values[] = (int)$salesDeliveryNo;
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $updates = [];
        if ($salesInvoiceNo !== null) {
            $updates[] = "sales_invoice_no = VALUES(sales_invoice_no)";
        }
        if ($salesOrderNo !== null) {
            $updates[] = "sales_order_no = VALUES(sales_order_no)";
        }
        if ($salesDeliveryNo !== null) {
            $updates[] = "sales_delivery_no = VALUES(sales_delivery_no)";
        }
        $updates[] = "updated_at = NOW()";

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr}) "
             . "ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert sales match: ") . db_error());
        }

        $id = (int)\db_insert_id();
        if ($id === 0) {
            $existing = $this->getBySquareTransactionId($squareTransactionId);
            if ($existing !== null) {
                return (int)$existing['ksf_import_square_sales_id'];
            }
        }

        return $id;
    }

    /**
     * Gets a match by Square transaction ID.
     *
     * @param string $squareTransactionId
     * @return array|null
     */
    public function getBySquareTransactionId(string $squareTransactionId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_transaction_id = '" . \db_escape($squareTransactionId) . "'";
        $result = \db_query($sql);

        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets matches by FA invoice number.
     *
     * @param int $salesInvoiceNo
     * @return array
     */
    public function getByInvoiceNo(int $salesInvoiceNo): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE sales_invoice_no = '" . \db_escape((string)$salesInvoiceNo) . "'";
        $result = \db_query($sql);
        $matches = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $matches[] = $row;
                }
            }
        }

        return $matches;
    }

    /**
     * Checks if a transaction is already matched.
     *
     * @param string $squareTransactionId
     * @return bool
     */
    public function isMatched(string $squareTransactionId): bool
    {
        return $this->getBySquareTransactionId($squareTransactionId) !== null;
    }

    /**
     * Deletes a match.
     *
     * @param string $squareTransactionId
     * @return void
     * @throws Exception
     */
    public function deleteMatch(string $squareTransactionId): void
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE square_transaction_id = '" . \db_escape($squareTransactionId) . "'";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to delete sales match: ") . db_error());
        }
    }
}
