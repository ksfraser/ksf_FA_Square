<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

/**
 * Location Mapping DAO
 * 
 * Handles database operations for location mapping between FA and Square.
 * Maps FA locations (loc_code) to Square locations (square_location_id).
 * A special '*ALL*' fa_loc_code maps to a single Square location and
 * makes inventory pushes sum FA QOH across all locations.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-04.02 - Location Mapping
 */
class LocationMappingDAO
{
    const ALL_LOCATIONS = '*ALL*';

    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets the location mappings table name.
     * 
     * @return string Table name
     */
    public function getTableName(): string
    {
        return $this->tablePrefix . 'square_location_mappings';
    }

    /**
     * Gets all FA locations.
     * 
     * @return array FA locations as rows with loc_code and location_name keys
     */
    public function getAllFaLocations(): array
    {
        $tableName = $this->tablePrefix . 'locations';
        $sql = "SELECT loc_code, location_name FROM {$tableName} ORDER BY loc_code";

        $result = \db_query($sql);
        $locations = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $locations[] = $row;
                }
            }
        }

        return $locations;
    }

    /**
     * Gets location mappings grouped by Square location.
     * 
     * @return array Mappings as [square_location_id => [fa_loc_code, ...]]
     */
    public function getMappingsBySquareLocation(): array
    {
        $tableName = $this->getTableName();
        $sql = "SELECT fa_loc_code, square_location_id FROM {$tableName} ORDER BY fa_loc_code";

        $result = \db_query($sql);
        $mappings = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row === false) {
                    continue;
                }
                $mappings[$row['square_location_id']][] = $row['fa_loc_code'];
            }
        }

        return $mappings;
    }

    /**
     * Gets the Square location for the '*ALL*' special mapping.
     * 
     * @return string|null Square location ID or null if not configured
     */
    public function getAllLocationsMapping(): ?string
    {
        $tableName = $this->getTableName();
        $sql = "SELECT square_location_id FROM {$tableName} WHERE fa_loc_code = '" . \db_escape(self::ALL_LOCATIONS) . "'";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row['square_location_id'] : null;
        }

        return null;
    }

    /**
     * Gets Square location ID for an FA location code.
     * 
     * @param string $faLocCode FA location code
     * @return string|null Square location ID or null if not found
     */
    public function getSquareLocationId(string $faLocCode): ?string
    {
        $tableName = $this->getTableName();
        $sql = "SELECT square_location_id FROM {$tableName} WHERE fa_loc_code = '" . \db_escape($faLocCode) . "'";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row['square_location_id'] : null;
        }

        return null;
    }

    /**
     * Gets the Square catalog object ID for an FA stock ID.
     * 
     * @param int $stockId FA stock ID
     * @return string|null Square catalog object ID or null if not found
     */
    public function getSquareItemId(int $stockId): ?string
    {
        $tableName = $this->tablePrefix . '0_square_tokens';
        $sql = "SELECT square_catalog_object_id FROM {$tableName} WHERE stock_id = '" . \db_escape((string)$stockId) . "' LIMIT 1";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row['square_catalog_object_id'] : null;
        }

        return null;
    }

    /**
     * Gets total quantity on hand for a stock item across FA locations.
     * 
     * Voided transactions are excluded, mirroring FA's own QOH calculation.
     * 
     * @param string $stockId FA stock ID
     * @param array|null $faLocCodes FA location codes; null sums across all locations
     * @return float Quantity on hand
     */
    public function getQohForLocations(string $stockId, ?array $faLocCodes): float
    {
        if ($faLocCodes !== null && count($faLocCodes) === 0) {
            return 0.0;
        }

        $sql = "SELECT SUM(qty) AS qty FROM {$this->tablePrefix}stock_moves st
            LEFT JOIN {$this->tablePrefix}voided v ON st.type = v.type AND st.trans_no = v.id
            WHERE ISNULL(v.id)
            AND st.stock_id = '" . \db_escape($stockId) . "'";

        if ($faLocCodes !== null) {
            $escapedCodes = [];
            foreach ($faLocCodes as $faLocCode) {
                $escapedCodes[] = "'" . \db_escape($faLocCode) . "'";
            }
            $sql .= " AND st.loc_code IN (" . implode(',', $escapedCodes) . ")";
        }

        $result = \db_query($sql);
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            if ($row !== false && isset($row['qty'])) {
                return (float)$row['qty'];
            }
        }

        return 0.0;
    }

    /**
     * Gets all location mappings.
     * 
     * @param int $limit Maximum number of mappings to return
     * @return array Location mappings as rows with fa_loc_code and square_location_id keys
     */
    public function getAllMappings(int $limit = 100): array
    {
        $tableName = $this->getTableName();
        $limit = max(1, (int)$limit);
        $sql = "SELECT * FROM {$tableName} ORDER BY created_at DESC, id DESC LIMIT {$limit}";

        $result = \db_query($sql);
        $mappings = [];

        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $mappings[] = $row;
                }
            }
        }

        return $mappings;
    }

    /**
     * Sets (upserts) a location mapping.
     * 
     * @param string $faLocCode FA location code
     * @param string $squareLocationId Square location ID
     * @return bool Success status
     */
    public function setMapping(string $faLocCode, string $squareLocationId): bool
    {
        $tableName = $this->getTableName();
        $sql = "INSERT INTO {$tableName} (fa_loc_code, square_location_id)
            VALUES ('" . \db_escape($faLocCode) . "', '" . \db_escape($squareLocationId) . "')
            ON DUPLICATE KEY UPDATE square_location_id = '" . \db_escape($squareLocationId) . "'";

        return \db_query($sql) !== false;
    }

    /**
     * Removes a location mapping.
     * 
     * @param string $faLocCode FA location code
     * @return bool Success status
     */
    public function removeMapping(string $faLocCode): bool
    {
        $tableName = $this->getTableName();
        $sql = "DELETE FROM {$tableName} WHERE fa_loc_code = '" . \db_escape($faLocCode) . "'";

        return \db_query($sql) !== false;
    }

    /**
     * Ensures the location mappings table exists with the expected schema.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTableName();

        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);

        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table matching sql/install.sql
            $createSql = "CREATE TABLE {$tableName} (
                id INT(11) NOT NULL AUTO_INCREMENT,
                fa_loc_code VARCHAR(5) NOT NULL,
                square_location_id VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY idx_fa_loc_code (fa_loc_code),
                KEY idx_square_location (square_location_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

            \db_query($createSql);
        }
    }
}
