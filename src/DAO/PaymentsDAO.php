<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Payments DAO
 * 
 * Handles database operations for payments and refunds.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Payment Processing, FR-07.02 - Payment Reconciliation
 */
class PaymentsDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets payment by ID.
     * 
     * @param int $paymentId Payment ID
     * @return array|null Payment data or null if not found
     */
    public function getPaymentById(int $paymentId): ?array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE payment_id = {$paymentId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets payment by reference.
     * 
     * @param string $reference Payment reference
     * @return array|null Payment data or null if not found
     */
    public function getPaymentByReference(string $reference): ?array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE ref = " . \db_escape($reference);
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets payments by debtor number.
     * 
     * @param int $debtorNo Debtor number
     * @return array Payment data
     */
    public function getPaymentsByDebtorNo(int $debtorNo): array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} WHERE debtor_no = {$debtorNo} ORDER BY date_1 DESC";

        $result = \db_query($sql);
        $payments = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $payments[] = $row;
                }
            }
        }

        return $payments;
    }

    /**
     * Gets payments by date range.
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Payment data
     */
    public function getPaymentsByDateRange(string $startDate, string $endDate): array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE date_1 BETWEEN '{$startDate}' AND '{$endDate}' 
                ORDER BY date_1 DESC";

        $result = \db_query($sql);
        $payments = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $payments[] = $row;
                }
            }
        }

        return $payments;
    }

    /**
     * Inserts new payment.
     * 
     * @param array $paymentData Payment data
     * @return int Payment ID
     */
    public function insertPayment(array $paymentData): int
    {
        $tableName = $this->getPaymentsTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($paymentData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = \db_escape($value);
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Inserts new refund.
     * 
     * @param array $refundData Refund data
     * @return int Refund ID
     */
    public function insertRefund(array $refundData): int
    {
        $tableName = $this->getPaymentsTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($refundData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = \db_escape($value);
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Updates payment.
     * 
     * @param int $paymentId Payment ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updatePayment(int $paymentId, array $data): bool
    {
        $tableName = $this->getPaymentsTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : \db_escape($value));
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE payment_id = {$paymentId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes payment.
     * 
     * @param int $paymentId Payment ID
     * @return bool Success status
     */
    public function deletePayment(int $paymentId): bool
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "DELETE FROM {$tableName} WHERE payment_id = {$paymentId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets payments by payment method.
     * 
     * @param string $paymentMethod Payment method
     * @return array Payment data
     */
    public function getPaymentsByMethod(string $paymentMethod): array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE payment_method = " . \db_escape($paymentMethod) . " 
                ORDER BY date_1 DESC";

        $result = \db_query($sql);
        $payments = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $payments[] = $row;
                }
            }
        }

        return $payments;
    }

    /**
     * Gets payments by status.
     * 
     * @param string $status Payment status
     * @return array Payment data
     */
    public function getPaymentsByStatus(string $status): array
    {
        $tableName = $this->getPaymentsTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE status = " . \db_escape($status) . " 
                ORDER BY date_1 DESC";

        $result = \db_query($sql);
        $payments = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $payments[] = $row;
                }
            }
        }

        return $payments;
    }

    /**
     * Gets payment statistics.
     * 
     * @return array Statistics array
     */
    public function getPaymentStatistics(): array
    {
        $tableName = $this->getPaymentsTableName();
        
        // Total payments
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Recent payments
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        // Payment amounts for the last 30 days
        $amountSql = "SELECT 
            SUM(amount) as total_amount,
            COUNT(amount) as total_payments,
            AVG(amount) as average_amount
            FROM {$tableName} 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $amountResult = \db_query($amountSql);
        $amounts = [
            'total_amount' => 0,
            'total_payments' => 0,
            'average_amount' => 0
        ];
        if ($amountResult !== false) {
            $row = \db_fetch_assoc($amountResult);
            $amounts = [
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'total_payments' => (int)($row['total_payments'] ?? 0),
                'average_amount' => (float)($row['average_amount'] ?? 0)
            ];
        }
        
        // Payment methods distribution
        $methodSql = "SELECT payment_method, COUNT(*) as count FROM {$tableName} 
                     GROUP BY payment_method ORDER BY count DESC";
        $methodResult = \db_query($methodSql);
        $byMethod = [];
        if ($methodResult !== false) {
            while ($row = \db_fetch_assoc($methodResult)) {
                if ($row !== false) {
                    $byMethod[$row['payment_method']] = (int)$row['count'];
                }
            }
        }
        
        // Status distribution
        $statusSql = "SELECT status, COUNT(*) as count FROM {$tableName} 
                     GROUP BY status ORDER BY count DESC";
        $statusResult = \db_query($statusSql);
        $byStatus = [];
        if ($statusResult !== false) {
            while ($row = \db_fetch_assoc($statusResult)) {
                if ($row !== false) {
                    $byStatus[$row['status']] = (int)$row['count'];
                }
            }
        }
        
        return [
            'total_payments' => $total,
            'recent_payments' => $recent,
            'amounts' => $amounts,
            'by_payment_method' => $byMethod,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Logs payment event.
     * 
     * @param array $eventData Event data
     * @return int Event ID
     */
    public function logPaymentEvent(array $eventData): int
    {
        $tableName = $this->getEventLogTableName();
        $faPaymentId = $eventData['fa_payment_id'] ?? 'NULL';
        $eventCurrency = $eventData['currency'] ?? 'USD';
        
        $sql = "INSERT INTO {$tableName} (
            fa_payment_id, square_payment_id, square_refund_id, 
            original_fa_payment_id, event_type, amount, 
            currency, timestamp
        ) VALUES (
            {$faPaymentId},
            " . (isset($eventData['square_payment_id']) ? \db_escape($eventData['square_payment_id']) : 'NULL') . ",
            " . (isset($eventData['square_refund_id']) ? \db_escape($eventData['square_refund_id']) : 'NULL') . ",
            " . (isset($eventData['original_fa_payment_id']) ? (int)$eventData['original_fa_payment_id'] : 'NULL') . ",
            '{$eventData['event_type']}',
            {$eventData['amount']},
            '{$eventCurrency}',
            '{$eventData['timestamp']}'
        )";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Ensures all tables exist.
     */
    public function ensureTablesExist(): void
    {
        $this->ensurePaymentsTableExists();
        $this->ensureEventLogTableExists();
    }

    /**
     * Ensures payments table exists.
     */
    private function ensurePaymentsTableExists(): void
    {
        $tableName = $this->getPaymentsTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                payment_id INT AUTO_INCREMENT PRIMARY KEY,
                debtor_no INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                currency VARCHAR(3) DEFAULT 'USD',
                date_1 DATE NOT NULL,
                bank_act VARCHAR(50),
                ref VARCHAR(100),
                person_id INT,
                bank_trans_type VARCHAR(50),
                payment_method VARCHAR(50),
                status VARCHAR(50),
                notes TEXT,
                original_payment_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (original_payment_id) REFERENCES {$tableName}(payment_id),
                INDEX idx_debtor_no (debtor_no),
                INDEX idx_date_1 (date_1),
                INDEX idx_ref (ref),
                INDEX idx_payment_method (payment_method),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Ensures event log table exists.
     */
    private function ensureEventLogTableExists(): void
    {
        $tableName = $this->getEventLogTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                event_id INT AUTO_INCREMENT PRIMARY KEY,
                fa_payment_id INT,
                square_payment_id VARCHAR(100),
                square_refund_id VARCHAR(100),
                original_fa_payment_id INT,
                event_type VARCHAR(50) NOT NULL,
                amount DECIMAL(15,2),
                currency VARCHAR(3) DEFAULT 'USD',
                timestamp DATETIME NOT NULL,
                INDEX idx_fa_payment_id (fa_payment_id),
                INDEX idx_square_payment_id (square_payment_id),
                INDEX idx_square_refund_id (square_refund_id),
                INDEX idx_original_fa_payment_id (original_fa_payment_id),
                INDEX idx_event_type (event_type),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Gets payments table name.
     * 
     * Module-owned table (not FA's core 0_payments) so payment tracking
     * never collides with FrontAccounting's real payment records.
     * 
     * @return string Table name
     */
    private function getPaymentsTableName(): string
    {
        return $this->tablePrefix . 'square_payments';
    }

    /**
     * Gets event log table name.
     * 
     * Module-owned table to avoid clashing with FA tables.
     * 
     * @return string Table name
     */
    private function getEventLogTableName(): string
    {
        return $this->tablePrefix . 'square_payment_events';
    }
}