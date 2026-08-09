<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMovesDAO;

/**
 * Data Access Object for square_tokens table with environment isolation.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class SquareTokenDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * @var string 'sandbox' or 'production'
     */
    private $environment;

    public function __construct(string $tablePrefix, string $environment = 'sandbox')
    {
        $this->tablePrefix = $tablePrefix;
        $this->environment = $environment;
    }

    /**
     * Ensures the square_tokens table exists and has the environment column,
     * creating/altering it if necessary.
     * 
     * @throws Exception if table creation/alteration fails
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->tablePrefix . '0_square_tokens';
        
        $checkTable = \db_query("SHOW TABLES LIKE '{$tableName}'");
        if (\db_num_rows($checkTable) == 0) {
            $sql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                stock_id VARCHAR(20) NOT NULL,
                sku VARCHAR(255) NOT NULL,
                square_catalog_object_id VARCHAR(255) NOT NULL,
                square_variation_id VARCHAR(255) NULL,
                environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
                fa_last_updated DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY idx_stock_id_env (stock_id, environment),
                KEY idx_sku (sku),
                KEY idx_environment (environment)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            if (!\db_query($sql)) {
                throw new Exception(_("Cannot create square_tokens table: ") . db_error());
            }
        } else {
            $this->ensureEnvironmentColumnExists();
        }
    }

    /**
     * Ensures the environment column exists (for upgrades).
     */
    private function ensureEnvironmentColumnExists(): void
    {
        $tableName = $this->tablePrefix . '0_square_tokens';
        
        $checkColumn = \db_query("SHOW COLUMNS FROM {$tableName} LIKE 'environment'");
        if (\db_num_rows($checkColumn) == 0) {
            \db_query("ALTER TABLE {$tableName} ADD COLUMN environment VARCHAR(20) NOT NULL DEFAULT 'sandbox'");
            \db_query("ALTER TABLE {$tableName} DROP KEY idx_stock_id");
            \db_query("ALTER TABLE {$tableName} ADD UNIQUE KEY idx_stock_id_env (stock_id, environment)");
            \db_query("ALTER TABLE {$tableName} ADD KEY idx_environment (environment)");
        }
        
        $checkVarIdColumn = \db_query("SHOW COLUMNS FROM {$tableName} LIKE 'square_variation_id'");
        if (\db_num_rows($checkVarIdColumn) > 0) {
            $row = \db_fetch_assoc($checkVarIdColumn);
            if (isset($row['Null']) && $row['Null'] === 'NO') {
                \db_query("ALTER TABLE {$tableName} MODIFY COLUMN square_variation_id VARCHAR(255) NULL");
            }
        }
    }

    /**
     * Inserts or updates a square token mapping for the current environment.
     * 
     * @param string $stockId FA stock ID
     * @param string $sku SKU
     * @param string $squareCatalogObjectId Square catalog object ID
     * @param string|null $squareVariationId Square variation ID
     * @param string|null $faLastUpdated FA last updated timestamp
     * @return void
     * @throws Exception if database operation fails
     */
    public function upsertToken(
        string $stockId,
        string $sku,
        string $squareCatalogObjectId,
        $squareVariationId = null,
        $faLastUpdated = null
    ): void {
        $tableName = $this->tablePrefix . '0_square_tokens';
        
        $sql = "INSERT INTO {$tableName} (stock_id, sku, square_catalog_object_id, square_variation_id, environment, fa_last_updated, created_at, updated_at) VALUES (" .
            \db_escape($stockId) . ", " .
            \db_escape($sku) . ", " .
            \db_escape($squareCatalogObjectId) . ", " .
            ($squareVariationId !== null ? \db_escape($squareVariationId) : "NULL") . ", " .
            \db_escape($this->environment) . ", " .
            ($faLastUpdated !== null ? "'" . \db_escape($faLastUpdated) . "'" : "NULL") . ", NOW(), NOW()) " .
            "ON DUPLICATE KEY UPDATE " .
            "square_catalog_object_id = VALUES(square_catalog_object_id), " .
            "square_variation_id = VALUES(square_variation_id), " .
            "fa_last_updated = VALUES(fa_last_updated), " .
            "updated_at = NOW()";
        
        if (!\db_query($sql)) {
            throw new Exception(_("Failed to upsert square token: ") . db_error());
        }
    }

    /**
     * Retrieves the FA last updated timestamp for a given stock ID.
     * 
     * @param string $stockId FA stock ID
     * @return string|null FA last updated timestamp or null if not found
     */
    public function getFaLastUpdated(string $stockId): ?string
    {
        $stockMovesDao = new StockMovesDAO($this->tablePrefix);
        return $stockMovesDao->getLastModified($stockId);
    }

    /**
     * Retrieves a square token by stock ID for the current environment.
     * 
     * @param string $stockId FA stock ID
     * @return array|null Token data or null if not found
     */
    public function getTokenByStockId(string $stockId): ?array
    {
        $tableName = $this->tablePrefix . '0_square_tokens';
        
        $sql = "SELECT * FROM {$tableName} WHERE stock_id = " . \db_escape($stockId) . 
            " AND environment = " . \db_escape($this->environment);
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            return \db_fetch_assoc($result);
        }
        return null;
    }

    /**
     * Gets all token mappings for the current environment.
     * 
     * @return array Array of token rows
     */
    public function getAllTokens(): array
    {
        $tableName = $this->tablePrefix . '0_square_tokens';
        
        $sql = "SELECT * FROM {$tableName} WHERE environment = " . \db_escape($this->environment);
        
        $result = \db_query($sql);
        $tokens = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                $tokens[] = $row;
            }
        }
        return $tokens;
    }

    /**
     * Gets the current environment.
     * 
     * @return string 'sandbox' or 'production'
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }
}
