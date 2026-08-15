<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\DAO;

/**
 * Expenses DAO
 * 
 * Handles database operations for expenses and expense categories.
 * 
 * @UML Note: DAO diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-07.03 - Expense Tracking
 */
class ExpensesDAO
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Gets expense by ID.
     * 
     * @param int $expenseId Expense ID
     * @return array|null Expense data or null if not found
     */
    public function getExpenseById(int $expenseId): ?array
    {
        $tableName = $this->getExpensesTableName();
        $sql = "SELECT * FROM {$tableName} WHERE expense_id = {$expenseId}";

        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Inserts an expense record.
     * 
     * @param array $expense Expense data
     * @return int New expense ID
     */
    public function insertExpense(array $expense): int
    {
        $tableName = $this->getExpensesTableName();
        $sql = "INSERT INTO {$tableName} (
            expense_date,
            description,
            amount,
            category_id,
            payment_method,
            created_at
        ) VALUES (
            '" . \db_escape($expense['expense_date'] ?? date('Y-m-d')) . "',
            '" . \db_escape($expense['description'] ?? '') . "',
            " . (float)($expense['amount'] ?? 0) . ",
            " . (int)($expense['category_id'] ?? 0) . ",
            '" . \db_escape($expense['payment_method'] ?? '') . "',
            '" . date('Y-m-d H:i:s') . "'
        )";

        \db_query($sql);

        return \db_insert_id();
    }

    /**
     * Gets expense categories.
     * 
     * @return array Expense categories
     */
    public function getExpenseCategories(): array
    {
        $tableName = $this->getCategoriesTableName();
        $sql = "SELECT * FROM {$tableName}";

        $result = \db_query($sql);
        $categories = [];
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Gets expenses table name.
     * 
     * @return string Table name
     */
    private function getExpensesTableName(): string
    {
        return $this->tablePrefix . 'ksf_expenses';
    }

    /**
     * Gets expense categories table name.
     * 
     * @return string Table name
     */
    private function getCategoriesTableName(): string
    {
        return $this->tablePrefix . 'ksf_expense_categories';
    }
}
