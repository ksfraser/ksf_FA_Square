<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

use Exception;

/**
 * Data Access Object for location mapping table (FA loc_code <-> Square location_id).
 * 
 * Supports many-to-one mapping: multiple FA locations can map to one Square location.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: Requirements analysis, Solution evaluation
 */
class LocationMappingDAO
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * Special loc_code value representing "All FA Locations"
     */
    public const ALL_LOCATIONS = '*ALL*';

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Ensures the location_mappings table exists, creating it if necessary.
     * 
     * @throws Exception if table creation fails
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->tablePrefix . '0_square_location_mappings';
        
        $checkTable = db_query("SHOW TABLES LIKE '{$tableName}'");
        if (db_num_rows($checkTable) == 0) {
            $sql = "CREATE TABLE {$tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fa_loc_code VARCHAR(5) NOT NULL,
                square_location_id VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_fa_loc_code (fa_loc_code),
                KEY idx_square_location (square_location_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            if (!db_query($sql)) {
                throw new Exception(_("Cannot create location_mappings table: ") . db_error());
            }
        }
    }

    /**
     * Gets all FA locations from the database.
     * 
     * @return array Array of [loc_code, location_name]
     */
    public function getAllFaLocations(): array
    {
        $sql = "SELECT loc_code, location_name FROM {$this->tablePrefix}locations ORDER BY loc_code";
        $result = db_query($sql);
        $locations = [];
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                $locations[] = $row;
            }
        }
        return $locations;
    }

    /**
     * Gets all location mappings.
     * 
     * @return array Array of mappings with fa_loc_code, square_location_id
     */
    public function getAllMappings(): array
    {
        $tableName = $this->tablePrefix . '0_square_location_mappings';
        $sql = "SELECT * FROM {$tableName} ORDER BY fa_loc_code";
        $result = db_query($sql);
        $mappings = [];
        if ($result !== false) {
            while ($row = db_fetch_assoc($result)) {
                $mappings[] = $row;
            }
        }
        return $mappings;
    }

    /**
     * Gets mappings grouped by Square location ID.
     * 
     * @return array Key: square_location_id, Value: array of fa_loc_code
     */
    public function getMappingsBySquareLocation(): array
    {
        $mappings = $this->getAllMappings();
        $grouped = [];
        foreach ($mappings as $mapping) {
            $sqLocId = $mapping['square_location_id'];
            if (!isset($grouped[$sqLocId])) {
                $grouped[$sqLocId] = [];
            }
            $grouped[$sqLocId][] = $mapping['fa_loc_code'];
        }
        return $grouped;
    }

    /**
     * Gets the Square location ID for a given FA loc_code.
     * 
     * @param string $faLocCode FA location code
     * @return string|null Square location ID or null if not mapped
     */
    public function getSquareLocationId(string $faLocCode): ?string
    {
        $tableName = $this->tablePrefix . '0_square_location_mappings';
        $sql = "SELECT square_location_id FROM {$tableName} WHERE fa_loc_code = " . db_escape($faLocCode);
        $result = db_query($sql);
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return $row['square_location_id'];
        }
        return null;
    }

    /**
     * Sets a mapping from FA loc_code to Square location ID.
     * 
     * @param string $faLocCode FA location code or self::ALL_LOCATIONS
     * @param string $squareLocationId Square location ID
     * @return void
     * @throws Exception if database operation fails
     */
    public function setMapping(string $faLocCode, string $squareLocationId): void
    {
        $tableName = $this->tablePrefix . '0_square_location_mappings';
        $sql = "INSERT INTO {$tableName} (fa_loc_code, square_location_id, created_at, updated_at) VALUES (" .
            db_escape($faLocCode) . ", " .
            db_escape($squareLocationId) . ", NOW(), NOW()) " .
            "ON DUPLICATE KEY UPDATE square_location_id = VALUES(square_location_id), updated_at = NOW()";
        if (!db_query($sql)) {
            throw new Exception(_("Failed to set location mapping: ") . db_error());
        }
    }

    /**
     * Removes a mapping for a FA loc_code.
     * 
     * @param string $faLocCode FA location code
     * @return void
     * @throws Exception if database operation fails
     */
    public function removeMapping(string $faLocCode): void
    {
        $tableName = $this->tablePrefix . '0_square_location_mappings';
        $sql = "DELETE FROM {$tableName} WHERE fa_loc_code = " . db_escape($faLocCode);
        if (!db_query($sql)) {
            throw new Exception(_("Failed to remove location mapping: ") . db_error());
        }
    }

    /**
     * Gets the quantity on hand for a stock item in specific FA locations.
     * 
     * @param string $stockId FA stock ID
     * @param array|null $faLocCodes Array of FA loc_codes to sum; null = all locations
     * @return float Total QOH
     */
    public function getQohForLocations(string $stockId, $faLocCodes = null): float
    {
        $tableName = $this->tablePrefix . 'stock_moves';
        
        if ($faLocCodes === null || empty($faLocCodes)) {
            $locCondition = "1=1";
        } else {
            $escapedLocs = array_map(function($loc) {
                return db_escape($loc);
            }, $faLocCodes);
            $locCondition = "loc_code IN (" . implode(', ', $escapedLocs) . ")";
        }
        
        $sql = "SELECT SUM(qty) AS total_qty FROM {$tableName} WHERE stock_id = " . db_escape($stockId) . " AND {$locCondition}";
        $result = db_query($sql);
        
        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            return (float)($row['total_qty'] ?? 0);
        }
        return 0.0;
    }

    /**
     * Checks if there's an "All Locations" mapping.
     * 
     * @return string|null Square location ID if "All Locations" is mapped, null otherwise
     */
    public function getAllLocationsMapping(): ?string
    {
        return $this->getSquareLocationId(self::ALL_LOCATIONS);
    }
}
