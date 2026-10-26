<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Interfaces;

/**
 * Transaction Edit Interface
 * 
 * Defines the contract for transaction edit services.
 * 
 * @UML Note: Interface in ProjectDocs/UML.md
 */
interface TransactionEditInterface
{
    /**
     * Edits a transaction.
     * 
     * @param array $editData Edit data
     * @return array Edit results
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function editTransaction(array $editData): array;

    /**
     * Approves a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveEdit(string $editId, string $approverId, array $approvalData = []): array;

    /**
     * Rejects a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function rejectEdit(string $editId, string $rejecterId, string $reason, array $rejectionData = []): array;

    /**
     * Cancels a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $cancellerId Canceller ID
     * @param string $reason Cancellation reason
     * @return array Cancellation results
     */
    public function cancelEdit(string $editId, string $cancellerId, string $reason): array;

    /**
     * Gets edit queue.
     * 
     * @param array $filters Filter parameters
     * @return array Edit queue
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function getEditQueue(array $filters = []): array;

    /**
     * Gets edit history.
     * 
     * @param array $filters Filter parameters
     * @return array Edit history
     */
    public function getEditHistory(array $filters = []): array;

    /**
     * Gets edit permissions.
     * 
     * @param int $userId User ID
     * @return array Edit permissions
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function getEditPermissions(int $userId): array;

    /**
     * Updates edit permissions.
     * 
     * @param int $userId User ID
     * @param array $permissions Permissions to set
     * @return array Update results
     */
    public function updateEditPermissions(int $userId, array $permissions): array;

    /**
     * Generates edit report.
     * 
     * @param array $filters Filter parameters
     * @return array Edit report
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function generateEditReport(array $filters = []): array;

    /**
     * Validates edit data.
     * 
     * @param array $editData Edit data
     * @throws \Exception on validation failure
     */
    public function validateEditData(array $editData): void;

    /**
     * Checks edit permissions.
     * 
     * @param array $editData Edit data
     * @throws \Exception on permission failure
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function checkEditPermissions(array $editData): void;

    /**
     * Validates edit.
     * 
     * @param array $edit Edit data
     * @return array Validation result
     */
    public function validateEdit(array $edit): array;

    /**
     * Gets configuration.
     * 
     * @return array Configuration
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function getConfig(): array;

    /**
     * Sets configuration.
     * 
     * @param array $config Configuration to set
     */
    public function setConfig(array $config): void;

    /**
     * Clears edit queue.
     */
    public function clearEditQueue(): void;

    /**
     * Clears edit history.
     */
    /**
     * @BABOK Related: NFR-ISU-007
     */
    public function clearEditHistory(): void;

    /**
     * Clears edit permissions.
     */
    public function clearEditPermissions(): void;
}