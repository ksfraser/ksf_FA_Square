<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\ImportStaging\Contracts\LineItemRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingLineItem;

/**
 * Adapts Square's item staging data to ISU's LineItemRepositoryInterface.
 *
 * Uses ISU's staging_line_items + staging_line_item_attributes tables.
 * Square-specific fields (category, modifiers, device, etc.) are stored
 * as attributes in the EAV table.
 *
 * @requirement FR-SQUARE-ISU-004 Line Item Repository Adapter
 * @BABOK Related: BR-SQ-020 Standardize staging on ISU interfaces
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @since 2.4.5
 */
class LineItemRepositoryAdapter implements LineItemRepositoryInterface
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function insert(StagingLineItem $item): int
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "INSERT INTO {$tableName}
                (staging_transaction_id, source, source_id, source_updated_at,
                 line_number, sku, name, description, item_type,
                 quantity, unit_price, tax_amount, tax_percent,
                 discount_amount, discount_percent, total_amount, currency, status)
                VALUES (" . (int)$item->getStagingTransactionId() . ","
             . \db_escape($item->getSource()) . ","
             . \db_escape($item->getSourceId(), true) . ","
             . \db_escape($item->getSourceUpdatedAt() ? $item->getSourceUpdatedAt()->format('Y-m-d H:i:s') : null, true) . ","
             . (int)$item->getLineNumber() . ","
             . \db_escape($item->getSku(), true) . ","
             . \db_escape($item->getName()) . ","
             . \db_escape($item->getDescription(), true) . ","
             . \db_escape($item->getItemType(), true) . ","
             . (float)$item->getQuantity() . ","
             . (float)$item->getUnitPrice() . ","
             . (float)$item->getTaxAmount() . ","
             . (float)$item->getTaxPercent() . ","
             . (float)$item->getDiscountAmount() . ","
             . (float)$item->getDiscountPercent() . ","
             . (float)$item->getTotalAmount() . ","
             . \db_escape($item->getCurrency()) . ","
             . \db_escape($item->getStatus())
             . ")";
        \db_query($sql);
        $id = (int)\db_insert_id();

        $this->insertAttributes($id, $item->getAttributes());

        return $id;
    }

    public function findByTransactionId(int $transactionId): array
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "SELECT * FROM {$tableName} WHERE staging_transaction_id = " . (int)$transactionId
             . " ORDER BY line_number ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $model = $this->toModel($row);
                    $model->setAttributes($this->getAttributes((int)$row['id']));
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    public function findBySource(string $source, ?string $sourceId = null): array
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "SELECT * FROM {$tableName} WHERE source = " . \db_escape($source);
        if ($sourceId !== null) {
            $sql .= " AND source_id = " . \db_escape($sourceId);
        }
        $sql .= " ORDER BY line_number ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $model = $this->toModel($row);
                    $model->setAttributes($this->getAttributes((int)$row['id']));
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    public function findByStatus(string $status, ?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "SELECT * FROM {$tableName} WHERE status = " . \db_escape($status);
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY id ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $model = $this->toModel($row);
                    $model->setAttributes($this->getAttributes((int)$row['id']));
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    public function updateStatus(int $id, string $status, ?string $error = null): void
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "UPDATE {$tableName} SET status = " . \db_escape($status)
             . " WHERE id = " . (int)$id;
        \db_query($sql);
    }

    public function updateBySource(StagingLineItem $item): void
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $sql = "UPDATE {$tableName} SET
                line_number = " . (int)$item->getLineNumber() . ",
                sku = " . \db_escape($item->getSku() ?? '') . ",
                name = " . \db_escape($item->getName() ?? '') . ",
                quantity = " . (float)$item->getQuantity() . ",
                unit_price = " . (float)$item->getUnitPrice() . ",
                tax_amount = " . (float)$item->getTaxAmount() . ",
                total_amount = " . (float)$item->getTotalAmount() . ",
                currency = " . \db_escape($item->getCurrency() ?? 'CAD') . ",
                updated_at = NOW()
                WHERE source = " . \db_escape($item->getSource())
             . " AND source_id = " . \db_escape($item->getSourceId() ?? '');
        \db_query($sql);
    }

    public function deleteByTransactionId(int $transactionId): void
    {
        $tableName = $this->tablePrefix . 'staging_line_items';
        $attrTable = $this->tablePrefix . 'staging_line_item_attributes';

        $subSql = "SELECT id FROM {$tableName} WHERE staging_transaction_id = " . (int)$transactionId;
        $result = \db_query($subSql);
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $delSql = "DELETE FROM {$attrTable} WHERE line_item_id = " . (int)$row['id'];
                    \db_query($delSql);
                }
            }
        }

        $sql = "DELETE FROM {$tableName} WHERE staging_transaction_id = " . (int)$transactionId;
        \db_query($sql);
    }

    /**
     * Convert a raw row to an ISU StagingLineItem model.
     *
     * @param array<string,mixed> $row
     * @return StagingLineItem
     */
    private function toModel(array $row): StagingLineItem
    {
        $item = new StagingLineItem();
        $item->setId((int)($row['id'] ?? 0));
        $item->setStagingTransactionId((int)($row['staging_transaction_id'] ?? 0));
        $item->setSource($row['source'] ?? 'square_api');
        $item->setSourceId($row['source_id'] ?? null);
        $item->setLineNumber((int)($row['line_number'] ?? 0));
        $item->setSku($row['sku'] ?? null);
        $item->setName($row['name'] ?? '');
        $item->setDescription($row['description'] ?? null);
        $item->setItemType($row['item_type'] ?? null);
        $item->setQuantity((float)($row['quantity'] ?? 0));
        $item->setUnitPrice((float)($row['unit_price'] ?? 0));
        $item->setTaxAmount((float)($row['tax_amount'] ?? 0));
        $item->setTaxPercent((float)($row['tax_percent'] ?? 0));
        $item->setDiscountAmount((float)($row['discount_amount'] ?? 0));
        $item->setDiscountPercent((float)($row['discount_percent'] ?? 0));
        $item->setTotalAmount((float)($row['total_amount'] ?? 0));
        $item->setCurrency($row['currency'] ?? 'CAD');
        $item->setStatus($row['status'] ?? 'staged');
        return $item;
    }

    private function insertAttributes(int $lineItemId, array $attributes): void
    {
        $attrTable = $this->tablePrefix . 'staging_line_item_attributes';
        foreach ($attributes as $key => $value) {
            $sql = "INSERT INTO {$attrTable} (line_item_id, attribute_key, attribute_value)
                    VALUES (" . (int)$lineItemId . "," . \db_escape($key) . "," . \db_escape((string)$value) . ")";
            \db_query($sql);
        }
    }

    private function getAttributes(int $lineItemId): array
    {
        $attrTable = $this->tablePrefix . 'staging_line_item_attributes';
        $sql = "SELECT attribute_key, attribute_value FROM {$attrTable} WHERE line_item_id = " . (int)$lineItemId;
        $result = \db_query($sql);
        $attrs = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $attrs[$row['attribute_key']] = $row['attribute_value'];
                }
            }
        }
        return $attrs;
    }
}
