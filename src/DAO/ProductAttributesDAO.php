<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

/**
 * Data Access Object for the Stage 3 connector attribute domains
 * (ksf_FA_ProductAttributes tables). Reads modifier lists, measurement
 * units, custom attributes and the category hierarchy for a stock item so
 * the Square push path can include them in the catalog object.
 *
 * All reads are guarded by a table probe: when the ksf_FA_ProductAttributes
 * module is not installed, methods return empty values instead of failing.
 *
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-SQUARE-006 item event sync, Stage 3 connector attributes
 * @since 2.4.4
 */
class ProductAttributesDAO
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
     * Modifier lists assigned to a stock item, each with its nested
     * modifiers. Ordered by assignment sort_order then list ordinal.
     *
     * @param string $stockId Stock ID
     * @return array<int, array<string, mixed>> List of modifier lists
     */
    public function getModifierLists(string $stockId): array
    {
        if (!$this->tableExists('product_modifier_lists') || !$this->tableExists('product_modifier_list_assignments')) {
            return [];
        }

        $sql = "SELECT ml.id, ml.name, ml.selection_type, ml.modifier_type, ml.min_selected_modifiers, "
            . "ml.max_selected_modifiers, ml.allow_quantities, ml.hidden_from_customer, ml.ordinal "
            . "FROM {$this->tablePrefix}product_modifier_lists ml "
            . "INNER JOIN {$this->tablePrefix}product_modifier_list_assignments a ON a.modifier_list_id = ml.id "
            . "WHERE a.stock_id = " . \db_escape($stockId) . " AND ml.active = 1 "
            . "ORDER BY a.sort_order, ml.ordinal, ml.id";

        $result = \db_query($sql);
        $lists = [];
        while ($row = \db_fetch_assoc($result)) {
            $lists[] = $this->mapModifierList($row);
        }

        foreach ($lists as &$list) {
            $list['modifiers'] = $this->getModifiers((int)$list['id']);
        }
        unset($list);

        return $lists;
    }

    /**
     * Active modifiers for a modifier list.
     *
     * @param int $modifierListId Modifier list ID
     * @return array<int, array<string, mixed>> List of modifiers
     */
    public function getModifiers(int $modifierListId): array
    {
        if (!$this->tableExists('product_modifiers')) {
            return [];
        }

        $sql = "SELECT id, name, price, on_by_default, ordinal, hidden_online "
            . "FROM {$this->tablePrefix}product_modifiers "
            . "WHERE modifier_list_id = " . (int)$modifierListId . " AND active = 1 "
            . "ORDER BY ordinal, id";

        $result = \db_query($sql);
        $modifiers = [];
        while ($row = \db_fetch_assoc($result)) {
            $modifiers[] = $this->mapModifier($row);
        }

        return $modifiers;
    }

    /**
     * Square measurement unit ID assigned to a stock item, if any.
     *
     * @param string $stockId Stock ID
     * @return string|null Measurement unit ID or null
     */
    public function getMeasurementUnitId(string $stockId): ?string
    {
        if (!$this->tableExists('product_measurement_units')) {
            return null;
        }

        $sql = "SELECT measurement_unit_id FROM {$this->tablePrefix}product_measurement_units "
            . "WHERE stock_id = " . \db_escape($stockId) . " LIMIT 1";

        $result = \db_query($sql);
        $row = \db_fetch_assoc($result);
        if ($row === false || $row === null) {
            return null;
        }
        $value = $row['measurement_unit_id'] ?? '';
        return $value !== '' ? (string)$value : null;
    }

    /**
     * Custom attribute key/value pairs for a stock item.
     *
     * @param string $stockId Stock ID
     * @return array<int, array{attr_key: string, attr_value: string}> List of attributes
     */
    public function getCustomAttributes(string $stockId): array
    {
        if (!$this->tableExists('product_custom_attributes')) {
            return [];
        }

        $sql = "SELECT attr_key, attr_value FROM {$this->tablePrefix}product_custom_attributes "
            . "WHERE stock_id = " . \db_escape($stockId) . " ORDER BY attr_key";

        $result = \db_query($sql);
        $attributes = [];
        while ($row = \db_fetch_assoc($result)) {
            $attributes[] = [
                'attr_key' => (string)($row['attr_key'] ?? ''),
                'attr_value' => (string)($row['attr_value'] ?? ''),
            ];
        }

        return $attributes;
    }

    /**
     * Parent FA stock category for a category, if the hierarchy defines one.
     *
     * @param int $categoryId FA stock category ID
     * @return int|null Parent category ID or null
     */
    public function getCategoryParent(int $categoryId): ?int
    {
        if (!$this->tableExists('product_category_hierarchy')) {
            return null;
        }

        $sql = "SELECT parent_category_id FROM {$this->tablePrefix}product_category_hierarchy "
            . "WHERE category_id = " . (int)$categoryId . " LIMIT 1";

        $result = \db_query($sql);
        $row = \db_fetch_assoc($result);
        if ($row === false || $row === null) {
            return null;
        }
        $value = $row['parent_category_id'] ?? null;
        return ($value === null || $value === '') ? null : (int)$value;
    }

    /**
     * Fulfillment profile for a stock item (product type and service
     * duration), if the Stage 3 module defines one.
     *
     * @param string $stockId Stock ID
     * @return array{product_type: string, service_duration_minutes: int|null}|null Fulfillment profile or null
     */
    public function getFulfillment(string $stockId): ?array
    {
        if (!$this->tableExists('product_fulfillment')) {
            return null;
        }

        $sql = "SELECT product_type, service_duration_minutes FROM {$this->tablePrefix}product_fulfillment "
            . "WHERE stock_id = " . \db_escape($stockId) . " LIMIT 1";

        $result = \db_query($sql);
        $row = \db_fetch_assoc($result);
        if ($row === false || $row === null) {
            return null;
        }

        return [
            'product_type'             => (string)($row['product_type'] ?? 'REGULAR'),
            'service_duration_minutes' => ($row['service_duration_minutes'] ?? null) !== null && $row['service_duration_minutes'] !== ''
                ? (int)$row['service_duration_minutes']
                : null,
        ];
    }

    /**
     * Maps a modifier list row to the normalized bag shape.
     *
     * @param array $row Raw database row
     * @return array<string, mixed> Normalized modifier list
     */
    private function mapModifierList(array $row): array
    {
        return [
            'id'                     => (int)($row['id'] ?? 0),
            'name'                   => (string)($row['name'] ?? ''),
            'selection_type'         => (string)($row['selection_type'] ?? 'SINGLE'),
            'modifier_type'          => (string)($row['modifier_type'] ?? 'NON_ALCOHOL'),
            'min_selected_modifiers' => ($row['min_selected_modifiers'] ?? null) !== null && $row['min_selected_modifiers'] !== ''
                ? (int)$row['min_selected_modifiers']
                : null,
            'max_selected_modifiers' => ($row['max_selected_modifiers'] ?? null) !== null && $row['max_selected_modifiers'] !== ''
                ? (int)$row['max_selected_modifiers']
                : null,
            'allow_quantities'       => (int)($row['allow_quantities'] ?? 0),
            'hidden_from_customer'   => (int)($row['hidden_from_customer'] ?? 0),
            'ordinal'                => (int)($row['ordinal'] ?? 0),
        ];
    }

    /**
     * Maps a modifier row to the normalized bag shape.
     *
     * @param array $row Raw database row
     * @return array<string, mixed> Normalized modifier
     */
    private function mapModifier(array $row): array
    {
        return [
            'id'            => (int)($row['id'] ?? 0),
            'name'          => (string)($row['name'] ?? ''),
            'price'         => ($row['price'] ?? null) !== null && $row['price'] !== '' ? (string)$row['price'] : null,
            'on_by_default' => (int)($row['on_by_default'] ?? 0),
            'ordinal'       => (int)($row['ordinal'] ?? 0),
            'hidden_online' => (int)($row['hidden_online'] ?? 0),
        ];
    }

    /**
     * Probes whether a ksf_FA_ProductAttributes table exists.
     *
     * @param string $table Table name without prefix
     * @return bool True when the table exists
     */
    private function tableExists(string $table): bool
    {
        $result = \db_query("SHOW TABLES LIKE '{$this->tablePrefix}{$table}'");
        return $result !== false && \db_num_rows($result) > 0;
    }
}
