<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Staging;

use ksfraser\FrontAccounting\ImportStaging\Contracts\CustomerRepositoryInterface;
use ksfraser\FrontAccounting\ImportStaging\Models\StagingCustomer;

/**
 * Adapts Square's customer data to ISU's CustomerRepositoryInterface.
 *
 * Uses Square's customer staging table (0_staging_customers)
 * mapped through the standard ISU contract.
 *
 * @requirement FR-SQUARE-ISU-002 Customer Repository Adapter
 * @BABOK Related: BR-SQ-020 Standardize staging on ISU interfaces
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @since 2.4.5
 */
class CustomerRepositoryAdapter implements CustomerRepositoryInterface
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    public function insert(StagingCustomer $customer): int
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "INSERT INTO {$tableName}
                (source, source_customer_id, name, email, phone, address_line1, address_line2,
                 city, province, postal_code, country, raw_json, status, source_updated_at)
                VALUES (" . \db_escape($customer->getSource()) . ","
             . \db_escape($customer->getSourceCustomerId(), true) . ","
             . \db_escape($customer->getName(), true) . ","
             . \db_escape($customer->getEmail(), true) . ","
             . \db_escape($customer->getPhone(), true) . ","
             . \db_escape($customer->getAddressLine1(), true) . ","
             . \db_escape($customer->getAddressLine2(), true) . ","
             . \db_escape($customer->getCity(), true) . ","
             . \db_escape($customer->getProvince(), true) . ","
             . \db_escape($customer->getPostalCode(), true) . ","
             . \db_escape($customer->getCountry(), true) . ","
             . \db_escape($customer->getRawJson(), true) . ","
             . \db_escape($customer->getStatus()) . ","
             . \db_escape($customer->getSourceUpdatedAt() ? $customer->getSourceUpdatedAt()->format('Y-m-d H:i:s') : null, true)
             . ")";
        \db_query($sql);
        return (int)\db_insert_id();
    }

    public function findById(int $id): ?StagingCustomer
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "SELECT * FROM {$tableName} WHERE id = " . (int)$id;
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row ? $this->toModel($row) : null;
        }
        return null;
    }

    public function findBySource(string $source, string $sourceCustomerId): ?StagingCustomer
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "SELECT * FROM {$tableName} WHERE source = " . \db_escape($source)
             . " AND source_customer_id = " . \db_escape($sourceCustomerId);
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row ? $this->toModel($row) : null;
        }
        return null;
    }

    public function findByStatus(string $status, ?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "SELECT * FROM {$tableName} WHERE status = " . \db_escape($status);
        if ($source !== null) {
            $sql .= " AND source = " . \db_escape($source);
        }
        $sql .= " ORDER BY created_at ASC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function findByEmail(string $email): array
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "SELECT * FROM {$tableName} WHERE email = " . \db_escape($email) . " ORDER BY created_at DESC";
        $result = \db_query($sql);
        $models = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $models[] = $this->toModel($row);
                }
            }
        }
        return $models;
    }

    public function updateStatus(int $id, string $status, ?string $error = null): void
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        if ($error !== null) {
            $sql = "UPDATE {$tableName} SET status = " . \db_escape($status)
                 . ", error_log = " . \db_escape($error) . " WHERE id = " . (int)$id;
        } else {
            $sql = "UPDATE {$tableName} SET status = " . \db_escape($status)
                 . " WHERE id = " . (int)$id;
        }
        \db_query($sql);
    }

    public function updateBySource(StagingCustomer $customer): bool
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "UPDATE {$tableName} SET
                name = " . \db_escape($customer->getName() ?? '') . ",
                email = " . \db_escape($customer->getEmail() ?? '') . ",
                phone = " . \db_escape($customer->getPhone() ?? '') . ",
                status = " . \db_escape($customer->getStatus()) . "
                WHERE source = " . \db_escape($customer->getSource())
             . " AND source_customer_id = " . \db_escape($customer->getSourceCustomerId() ?? '');
        \db_query($sql);
        return \db_affected_rows() > 0;
    }

    public function countByStatus(?string $source = null): array
    {
        $tableName = $this->tablePrefix . 'staging_customers';
        $sql = "SELECT status, COUNT(*) as count FROM {$tableName}";
        if ($source !== null) {
            $sql .= " WHERE source = " . \db_escape($source);
        }
        $sql .= " GROUP BY status";
        $result = \db_query($sql);
        $counts = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $counts[$row['status']] = (int)$row['count'];
                }
            }
        }
        return $counts;
    }

    /**
     * Convert a raw row to an ISU StagingCustomer model.
     *
     * @param array<string,mixed> $row
     * @return StagingCustomer
     */
    private function toModel(array $row): StagingCustomer
    {
        $customer = new StagingCustomer($row['source'] ?? 'square_api');
        $customer->setId((int)($row['id'] ?? 0));
        $customer->setSourceCustomerId($row['source_customer_id'] ?? null);
        $customer->setName($row['name'] ?? null);
        $customer->setEmail($row['email'] ?? null);
        $customer->setPhone($row['phone'] ?? null);
        $customer->setAddressLine1($row['address_line1'] ?? null);
        $customer->setAddressLine2($row['address_line2'] ?? null);
        $customer->setCity($row['city'] ?? null);
        $customer->setProvince($row['province'] ?? null);
        $customer->setPostalCode($row['postal_code'] ?? null);
        $customer->setCountry($row['country'] ?? null);
        $customer->setRawJson($row['raw_json'] ?? null);
        $customer->setStatus($row['status'] ?? 'staged');
        $customer->setFaDebtorNo($row['fa_debtor_no'] ? (int)$row['fa_debtor_no'] : null);
        $customer->setErrorLog($row['error_log'] ?? null);
        return $customer;
    }
}
