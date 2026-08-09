<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for ksf_import_square_payments table.
 *
 * Tracks matches between Square payment IDs and FA transactions.
 * Backward compatible with existing FA_ImportSquareUp production data.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class PaymentMatchDAO
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
        return $this->tablePrefix . 'ksf_import_square_payments';
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
                square_import_payments_id INT(11) NOT NULL AUTO_INCREMENT,
                square_payment_id VARCHAR(32) NOT NULL,
                total_collected DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                trans_type INT(11) NOT NULL DEFAULT 0,
                trans_no VARCHAR(32) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (square_import_payments_id),
                UNIQUE KEY idx_square_payment_id (square_payment_id),
                KEY idx_fa_trans (trans_type, trans_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            if (!\db_query($sql)) {
                throw new Exception(_("Cannot create ksf_import_square_payments table: ") . db_error());
            }
        }
    }

    /**
     * Records a payment match.
     *
     * @param string $squarePaymentId
     * @param int $transType FA transaction type (ST_CUSTPAYMENT, etc.)
     * @param int $transNo FA transaction number
     * @param float|null $totalCollected
     * @return int
     * @throws Exception
     */
    public function insertMatch(
        string $squarePaymentId,
        int $transType,
        int $transNo,
        ?float $totalCollected = null
    ): int {
        $tableName = $this->getTableName();

        $fields = ['square_payment_id', 'trans_type', 'trans_no'];
        $values = [
            "'" . \db_escape($squarePaymentId) . "'",
            (int)$transType,
            (int)$transNo,
        ];

        if ($totalCollected !== null) {
            $fields[] = 'total_collected';
            $values[] = $totalCollected;
        }

        $fieldsStr = implode(', ', $fields);
        $valuesStr = implode(', ', $values);

        $sql = "INSERT INTO {$tableName} ({$fieldsStr}) VALUES ({$valuesStr}) "
             . "ON DUPLICATE KEY UPDATE updated_at = NOW()";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to insert payment match: ") . db_error());
        }

        $id = (int)\db_insert_id();
        if ($id === 0) {
            $existing = $this->getBySquarePaymentId($squarePaymentId);
            if ($existing !== null) {
                return (int)$existing['square_import_payments_id'];
            }
        }

        return $id;
    }

    /**
     * Gets a match by Square payment ID.
     *
     * @param string $squarePaymentId
     * @return array|null
     */
    public function getBySquarePaymentId(string $squarePaymentId): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE square_payment_id = '" . \db_escape($squarePaymentId) . "'";
        $result = \db_query($sql);

        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets matches by FA transaction.
     *
     * @param int $transType
     * @param int $transNo
     * @return array
     */
    public function getByFaTransaction(int $transType, int $transNo): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE trans_type = " . (int)$transType
             . " AND trans_no = '" . \db_escape((string)$transNo) . "'";
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
     * Checks if a payment is already matched.
     *
     * @param string $squarePaymentId
     * @return bool
     */
    public function isMatched(string $squarePaymentId): bool
    {
        return $this->getBySquarePaymentId($squarePaymentId) !== null;
    }

    /**
     * Deletes a match.
     *
     * @param string $squarePaymentId
     * @return void
     * @throws Exception
     */
    public function deleteMatch(string $squarePaymentId): void
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE square_payment_id = '" . \db_escape($squarePaymentId) . "'";

        if (!\db_query($sql)) {
            throw new Exception(_("Failed to delete payment match: ") . db_error());
        }
    }
}
