<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Tax Rates DAO
 * 
 * Handles database operations for tax rates.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-06.01 - Tax Calculation
 */
class TaxRatesDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets tax rate by ID.
     * 
     * @param int $id Tax type ID
     * @return array|null Tax rate data or null if not found
     */
    public function getTaxRateById(int $id): ?array
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "SELECT * FROM {$tableName} WHERE tax_type_id = {$id}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets all tax rates.
     * 
     * @return array All tax rates
     */
    public function getAllTaxRates(): array
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "SELECT * FROM {$tableName} ORDER BY name";

        $result = \db_query($sql);
        $taxRates = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $taxRates[] = $row;
                }
            }
        }

        return $taxRates;
    }

    /**
     * Gets active tax rates.
     * 
     * @return array Active tax rates
     */
    public function getActiveTaxRates(): array
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "SELECT * FROM {$tableName} WHERE inactive = 0 ORDER BY name";

        $result = \db_query($sql);
        $taxRates = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $taxRates[] = $row;
                }
            }
        }

        return $taxRates;
    }

    /**
     * Inserts new tax rate.
     * 
     * @param array $taxData Tax rate data
     * @return int Tax type ID
     */
    public function insertTaxRate(array $taxData): int
    {
        $tableName = $this->getTaxRatesTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($taxData as $key => $value) {
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
     * Updates tax rate.
     * 
     * @param int $id Tax type ID
     * @param array $data Update data
     * @return bool Success status
     */
    public function updateTaxRate(int $id, array $data): bool
    {
        $tableName = $this->getTaxRatesTableName();
        
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE tax_type_id = {$id}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes tax rate.
     * 
     * @param int $id Tax type ID
     * @return bool Success status
     */
    public function deleteTaxRate(int $id): bool
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "DELETE FROM {$tableName} WHERE tax_type_id = {$id}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets tax rates by name.
     * 
     * @param string $name Tax rate name
     * @return array Matching tax rates
     */
    public function getTaxRatesByName(string $name): array
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE name LIKE '%" . \db_escape($name) . "%' 
                ORDER BY name";

        $result = \db_query($sql);
        $taxRates = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $taxRates[] = $row;
                }
            }
        }

        return $taxRates;
    }

    /**
     * Gets tax rates by rate.
     * 
     * @param float $rate Tax rate
     * @return array Matching tax rates
     */
    public function getTaxRatesByRate(float $rate): array
    {
        $tableName = $this->getTaxRatesTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE rate = {$rate} 
                ORDER BY name";

        $result = \db_query($sql);
        $taxRates = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $taxRates[] = $row;
                }
            }
        }

        return $taxRates;
    }

    /**
     * Gets tax rate statistics.
     * 
     * @return array Statistics array
     */
    public function getTaxRateStatistics(): array
    {
        $tableName = $this->getTaxRatesTableName();
        
        // Total tax rates
        $totalSql = "SELECT COUNT(*) as total FROM {$tableName}";
        $totalResult = \db_query($totalSql);
        $total = 0;
        if ($totalResult !== false) {
            $row = \db_fetch_assoc($totalResult);
            $total = (int)($row['total'] ?? 0);
        }
        
        // Active tax rates
        $activeSql = "SELECT COUNT(*) as active FROM {$tableName} WHERE inactive = 0";
        $activeResult = \db_query($activeSql);
        $active = 0;
        if ($activeResult !== false) {
            $row = \db_fetch_assoc($activeResult);
            $active = (int)($row['active'] ?? 0);
        }
        
        // Tax rates by rate range
        $ranges = [
            'low' => "SELECT COUNT(*) as count FROM {$tableName} WHERE rate BETWEEN 0 AND 5",
            'medium' => "SELECT COUNT(*) as count FROM {$tableName} WHERE rate > 5 AND rate <= 15",
            'high' => "SELECT COUNT(*) as count FROM {$tableName} WHERE rate > 15"
        ];
        
        $byRange = [];
        foreach ($ranges as $range => $sql) {
            $result = \db_query($sql);
            if ($result !== false) {
                $row = \db_fetch_assoc($result);
                $byRange[$range] = (int)($row['count'] ?? 0);
            }
        }
        
        return [
            'total_rates' => $total,
            'active_rates' => $active,
            'inactive_rates' => $total - $active,
            'by_rate_range' => $byRange,
        ];
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTaxRatesTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                tax_type_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                rate DECIMAL(10,4) NOT NULL,
                tax_type_name VARCHAR(100) DEFAULT NULL,
                tax_type_code VARCHAR(50) DEFAULT NULL,
                tax_type_id2 INT DEFAULT NULL,
                tax_type_id3 INT DEFAULT NULL,
                inactive TINYINT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_rate (rate),
                INDEX idx_inactive (inactive)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }

    /**
     * Gets tax rates table name.
     * 
     * @return string Table name
     */
    private function getTaxRatesTableName(): string
    {
        return $this->tablePrefix . 'tax_rates';
    }
}