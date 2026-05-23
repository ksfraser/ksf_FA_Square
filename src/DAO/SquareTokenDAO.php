<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;
use Ksfraser\Frontaccounting\SquareUp\DAO\StockMovesDAO;

/**
 * Data Access Object for square_tokens table.
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

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Ensures the square_tokens table exists, creating it if necessary.
     * 
     * @throws Exception if table creation fails
     */
    public function ensureTableExists(): void
    {
        $checkTable = db_query("SHOW TABLES LIKE '{$this->tablePrefix}0_square_tokens'");
        if (db_num_rows($checkTable) == 0) {
            $sql = "CREATE TABLE {$this->tablePrefix}0_square_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                stock_id VARCHAR(20) NOT NULL,
                sku VARCHAR(255) NOT NULL,
                square_catalog_object_id VARCHAR(255) NOT NULL,
                square_variation_id VARCHAR(255) NOT NULL,
                fa_last_updated DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY stock_id (stock_id)
            ) ENGINE=InnoDB;";
            if (!db_query($sql)) {
                throw new Exception(_("Cannot create square_tokens table: ") . db_error());
            }
        }
    }

    /**
     * Inserts or updates a square token mapping.
     * 
     * @param string $stockId FA stock ID
     * @param string $sku SKU
     * @param string $squareCatalogObjectId Square catalog object ID
     * @param string $squareVariationId Square variation ID
     * @param string|null $faLastUpdated FA last updated timestamp
     * @return void
     * @throws Exception if database operation fails
     */
    public function upsertToken(
        string $stockId,
        string $sku,
        string $squareCatalogObjectId,
        string $squareVariationId,
        ?string $faLastUpdated
    ): void {
        $sql = "INSERT INTO {$this->tablePrefix}0_square_tokens (stock_id, sku, square_catalog_object_id, square_variation_id, fa_last_updated, created_at, updated_at) VALUES (" .
            db_escape($stockId) . ", " . db_escape($sku) . ", " . db_escape($squareCatalogObjectId) . ", " . db_escape($squareVariationId) . ", " .
            ($faLastUpdated !== null ? "'" . db_escape($faLastUpdated) . "'" : "NULL") . ", NOW(), NOW()) " .
            "ON DUPLICATE KEY UPDATE square_catalog_object_id = VALUES(square_catalog_object_id), square_variation_id = VALUES(square_variation_id), fa_last_updated = VALUES(fa_last_updated), updated_at = NOW()";
        if (!db_query($sql)) {
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
     * Retrieves a square token by stock ID.
     * 
     * @param string $stockId FA stock ID
     * @return array|null Token data or null if not found
     */
    public function getTokenByStockId(string $stockId): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}0_square_tokens WHERE stock_id = " . db_escape($stockId);
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            return db_fetch_assoc($result);
        }
        return null;
    }
}