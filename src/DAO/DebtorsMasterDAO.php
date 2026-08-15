<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Debtors Master DAO
 * 
 * Handles database operations for FrontAccounting debtors.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-01.01 - Customer Synchronization
 */
class DebtorsMasterDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets debtor by debtor number.
     * 
     * @param int $debtorNo Debtor number
     * @return array|null Debtor data or null if not found
     */
    public function getDebtor(int $debtorNo): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE debtor_no = {$debtorNo}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets debtor by email.
     * 
     * @param string $email Email address
     * @return array|null Debtor data or null if not found
     */
    public function getByEmail(string $email): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE email = '" . \db_escape($email) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets debtor by phone.
     * 
     * @param string $phone Phone number
     * @return array|null Debtor data or null if not found
     */
    public function getByPhone(string $phone): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE phone = '" . \db_escape($phone) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets debtor by name.
     * 
     * @param string $name Customer name
     * @return array|null Debtor data or null if not found
     */
    public function getByName(string $name): ?array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} WHERE name LIKE '%" . \db_escape($name) . "%'";
        
        $result = \db_query($sql);
        $debtors = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $debtors[] = $row;
                }
            }
        }

        return $debtors; // Return array as name matching may return multiple results
    }

    /**
     * Updates debtor.
     * 
     * @param int $debtorNo Debtor number
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateDebtor(int $debtorNo, array $data): bool
    {
        $tableName = $this->getTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE debtor_no = {$debtorNo}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Inserts new debtor.
     * 
     * @param array $debtorData Debtor data
     * @return int Debtor number
     */
    public function insertDebtor(array $debtorData): int
    {
        $tableName = $this->getTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($debtorData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = "'" . \db_escape($value) . "'";
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Gets all debtors.
     * 
     * @param int $limit Maximum number of debtors to return
     * @return array Debtors
     */
    public function getAllDebtors(int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT * FROM {$tableName} ORDER BY name ASC LIMIT {$limit}";

        $result = \db_query($sql);
        $debtors = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $debtors[] = $row;
                }
            }
        }

        return $debtors;
    }

    /**
     * Searches debtors by criteria.
     * 
     * @param array $criteria Search criteria
     * @return array Matching debtors
     */
    public function searchDebtors(array $criteria): array
    {
        $tableName = $this->getTableName();
        $conditions = [];
        
        if (!empty($criteria['name'])) {
            $conditions[] = "name LIKE '%" . \db_escape($criteria['name']) . "%'";
        }
        
        if (!empty($criteria['email'])) {
            $conditions[] = "email = '" . \db_escape($criteria['email']) . "'";
        }
        
        if (!empty($criteria['phone'])) {
            $conditions[] = "phone = '" . \db_escape($criteria['phone']) . "'";
        }
        
        if (!empty($criteria['category_id'])) {
            $conditions[] = "category_id = " . (int)$criteria['category_id'];
        }
        
        $sql = "SELECT * FROM {$tableName}";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY name ASC LIMIT 100";

        $result = \db_query($sql);
        $debtors = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $debtors[] = $row;
                }
            }
        }

        return $debtors;
    }

    /**
     * Gets debtor statistics.
     * 
     * @return array Statistics array
     */
    public function getDebtorStatistics(): array
    {
        $tableName = $this->getTableName();
        
        // Total debtors
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Debtors by category
        $categorySql = "SELECT category_id, COUNT(*) as count FROM {$tableName} 
                       GROUP BY category_id ORDER BY count DESC";
        $categoryResult = \db_query($categorySql);
        $byCategory = [];
        if ($categoryResult !== false) {
            while ($row = \db_fetch_assoc($categoryResult)) {
                if ($row !== false) {
                    $byCategory[$row['category_id']] = (int)$row['count'];
                }
            }
        }
        
        // Recent debtors
        $recentSql = "SELECT COUNT(*) as recent FROM {$tableName} 
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $recentResult = \db_query($recentSql);
        $recent = 0;
        if ($recentResult !== false) {
            $row = \db_fetch_assoc($recentResult);
            $recent = (int)($row['recent'] ?? 0);
        }
        
        return [
            'total_debtors' => $total,
            'by_category' => $byCategory,
            'recent_debtors' => $recent,
        ];
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                debtor_no INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(50),
                address1 VARCHAR(255),
                address2 VARCHAR(255),
                city VARCHAR(50),
                state VARCHAR(50),
                zip VARCHAR(20),
                country VARCHAR(50) DEFAULT 'US',
                ref VARCHAR(100),
                category_id INT DEFAULT 1,
                sales_type INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_email (email),
                INDEX idx_phone (phone),
                INDEX idx_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Gets the table name.
     * 
     * @return string Table name
     */
    private function getTableName(): string
    {
        return $this->tablePrefix . 'debtors_master';
    }
}