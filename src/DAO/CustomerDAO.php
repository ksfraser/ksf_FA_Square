<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\DAO;

/**
 * Customer DAO
 * 
 * Handles database operations for customers (debtors).
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.01 - Customer Management
 */
class CustomerDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets customer by ID.
     * 
     * @param int $customerId Customer ID
     * @return array|null Customer data or null if not found
     */
    public function getCustomerById(int $customerId): ?array
    {
        $tableName = $this->getCustomersTableName();
        $sql = "SELECT * FROM {$tableName} WHERE debtor_no = {$customerId}";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets customers.
     * 
     * @param array $filters Filter parameters
     * @return array Customers
     */
    public function getCustomers(array $filters = []): array
    {
        $tableName = $this->getCustomersTableName();
        $where = [];

        if (!empty($filters['email'])) {
            $where[] = "email = '" . \db_escape($filters['email']) . "'";
        }

        if (!empty($filters['name'])) {
            $where[] = "name LIKE '%" . \db_escape($filters['name']) . "%'";
        }

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM {$tableName}{$whereClause}";

        $result = \db_query($sql);
        $customers = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                $customers[] = $row;
            }
        }

        return $customers;
    }

    /**
     * Gets customers table name.
     * 
     * @return string Table name
     */
    private function getCustomersTableName(): string
    {
        return $this->tablePrefix . 'debtors_master';
    }
}
