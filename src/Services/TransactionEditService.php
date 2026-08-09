<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Transaction Edit Service
 * 
 * Handles transaction editing and correction functionality.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class TransactionEditService
{
    private array $config;
    private array $editHistory = [];
    private array $editQueue = [];
    private array $editPermissions = [];
    private const EDIT_STATUS_PENDING = 'pending';
    const EDIT_STATUS_APPROVED = 'approved';
    const EDIT_STATUS_REJECTED = 'rejected';
    const EDIT_STATUS_CANCELLED = 'cancelled';
    const EDIT_TYPE_CORRECTION = 'correction';
    const EDIT_TYPE_MODIFICATION = 'modification';
    const EDIT_TYPE_VOID = 'void';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enable_editing' => true,
            'enable_corrections' => true,
            'enable_modifications' => true,
            'enable_voids' => true,
            'auto_approve_threshold' => 100, // Amount threshold for auto-approval
            'edit_timeout' => 24 * 60 * 60, // 24 hours
            'log_edits' => true,
            'edit_log_file' => sys_get_temp_dir() . '/edits.log'
        ], $config);
        
        $this->initializeEditPermissions();
    }

    /**
     * Edits a transaction.
     * 
     * @param array $editData Edit data
     * @return array Edit results
     */
    public function editTransaction(array $editData): array
    {
        try {
            // Validate edit data
            $this->validateEditData($editData);
            
            // Check edit permissions
            $this->checkEditPermissions($editData);
            
            // Create edit entry
            $editEntry = $this->createEditEntry($editData);
            
            // Perform edit validation
            $validationResult = $this->validateEdit($editEntry);
            
            if (!$validationResult['success']) {
                throw new \Exception("Edit validation failed: " . $validationResult['message']);
            }
            
            // Add to edit queue
            $this->addToEditQueue($editEntry);
            
            // Perform auto-approval if applicable
            if ($this->shouldAutoApprove($editData)) {
                $autoApprovalResult = $this->performAutoApproval($editEntry);
                $editEntry['auto_approval_result'] = $autoApprovalResult;
            }
            
            // Log edit
            if ($this->config['log_edits']) {
                $this->logEdit($editEntry);
            }
            
            return $editEntry;
        } catch (\Exception $e) {
            throw new \Exception("Transaction editing failed: " . $e->getMessage());
        }
    }

    /**
     * Approves a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $approverId Approver ID
     * @param array $approvalData Approval data
     * @return array Approval results
     */
    public function approveEdit(string $editId, string $approverId, array $approvalData = []): array
    {
        try {
            // Find edit in queue
            $edit = $this->findEditById($editId);
            
            if (!$edit) {
                throw new \Exception("Edit not found");
            }
            
            if ($edit['status'] !== self::EDIT_STATUS_PENDING) {
                throw new \Exception("Edit is not pending approval");
            }
            
            // Update edit status
            $edit['status'] = self::EDIT_STATUS_APPROVED;
            $edit['approved_by'] = $approverId;
            $edit['approved_at'] = time();
            $edit['approval_data'] = $approvalData;
            
            // Process approval
            $approvalResult = $this->processApproval($edit);
            
            // Remove from queue and add to history
            $this->removeFromEditQueue($editId);
            $this->addToEditHistory($edit);
            
            // Apply changes to transaction
            $this->applyEditChanges($edit);
            
            return $approvalResult;
        } catch (\Exception $e) {
            throw new \Exception("Edit approval failed: " . $e->getMessage());
        }
    }

    /**
     * Rejects a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $rejecterId Rejecter ID
     * @param string $reason Rejection reason
     * @param array $rejectionData Rejection data
     * @return array Rejection results
     */
    public function rejectEdit(string $editId, string $rejecterId, string $reason, array $rejectionData = []): array
    {
        try {
            // Find edit in queue
            $edit = $this->findEditById($editId);
            
            if (!$edit) {
                throw new \Exception("Edit not found");
            }
            
            if ($edit['status'] !== self::EDIT_STATUS_PENDING) {
                throw new \Exception("Edit is not pending approval");
            }
            
            // Update edit status
            $edit['status'] = self::EDIT_STATUS_REJECTED;
            $edit['rejected_by'] = $rejecterId;
            $edit['rejected_at'] = time();
            $edit['rejection_reason'] = $reason;
            $edit['rejection_data'] = $rejectionData;
            
            // Process rejection
            $rejectionResult = $this->processRejection($edit);
            
            // Remove from queue and add to history
            $this->removeFromEditQueue($editId);
            $this->addToEditHistory($edit);
            
            return $rejectionResult;
        } catch (\Exception $e) {
            throw new \Exception("Edit rejection failed: " . $e->getMessage());
        }
    }

    /**
     * Cancels a transaction edit.
     * 
     * @param string $editId Edit ID
     * @param string $cancellerId Canceller ID
     * @param string $reason Cancellation reason
     * @return array Cancellation results
     */
    public function cancelEdit(string $editId, string $cancellerId, string $reason): array
    {
        try {
            // Find edit in queue
            $edit = $this->findEditById($editId);
            
            if (!$edit) {
                throw new \Exception("Edit not found");
            }
            
            if ($edit['status'] !== self::EDIT_STATUS_PENDING) {
                throw new \Exception("Edit is not pending cancellation");
            }
            
            // Update edit status
            $edit['status'] = self::EDIT_STATUS_CANCELLED;
            $edit['cancelled_by'] = $cancellerId;
            $edit['cancelled_at'] = time();
            $edit['cancellation_reason'] = $reason;
            
            // Remove from queue and add to history
            $this->removeFromEditQueue($editId);
            $this->addToEditHistory($edit);
            
            return [
                'success' => true,
                'edit_id' => $editId,
                'cancelled_by' => $cancellerId,
                'cancelled_at' => $edit['cancelled_at'],
                'reason' => $reason,
                'message' => 'Edit cancelled successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Edit cancellation failed: " . $e->getMessage());
        }
    }

    /**
     * Gets edit queue.
     * 
     * @param array $filters Filter parameters
     * @return array Edit queue
     */
    public function getEditQueue(array $filters = []): array
    {
        $filteredQueue = $this->editQueue;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredQueue = array_filter($filteredQueue, fn($e) => $e['status'] == $filters['status']);
        }
        
        if (isset($filters['edit_type'])) {
            $filteredQueue = array_filter($filteredQueue, fn($e) => $e['edit_type'] == $filters['edit_type']);
        }
        
        if (isset($filters['priority'])) {
            $filteredQueue = array_filter($filteredQueue, fn($e) => $e['priority'] == $filters['priority']);
        }
        
        if (isset($filters['amount_min'])) {
            $filteredQueue = array_filter($filteredQueue, fn($e) => $e['amount'] >= $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $filteredQueue = array_filter($filteredQueue, fn($e) => $e['amount'] <= $filters['amount_max']);
        }
        
        return array_values($filteredQueue);
    }

    /**
     * Gets edit history.
     * 
     * @param array $filters Filter parameters
     * @return array Edit history
     */
    public function getEditHistory(array $filters = []): array
    {
        $filteredHistory = $this->editHistory;
        
        // Apply filters
        if (isset($filters['status'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['status'] == $filters['status']);
        }
        
        if (isset($filters['edit_type'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['edit_type'] == $filters['edit_type']);
        }
        
        if (isset($filters['editor'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['edited_by'] == $filters['editor']);
        }
        
        if (isset($filters['date_from'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['edited_at'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $filteredHistory = array_filter($filteredHistory, fn($h) => $h['edited_at'] <= $filters['date_to']);
        }
        
        return array_values($filteredHistory);
    }

    /**
     * Gets edit permissions.
     * 
     * @param int $userId User ID
     * @return array Edit permissions
     */
    public function getEditPermissions(int $userId): array
    {
        return $this->editPermissions[$userId] ?? [];
    }

    /**
     * Updates edit permissions.
     * 
     * @param int $userId User ID
     * @param array $permissions Permissions to set
     * @return array Update results
     */
    public function updateEditPermissions(int $userId, array $permissions): array
    {
        $this->editPermissions[$userId] = $permissions;
        
        return [
            'success' => true,
            'user_id' => $userId,
            'permissions' => $permissions,
            'message' => 'Edit permissions updated successfully'
        ];
    }

    /**
     * Generates edit report.
     * 
     * @param array $filters Filter parameters
     * @return array Edit report
     */
    public function generateEditReport(array $filters = []): array
    {
        $report = [
            'generated_at' => time(),
            'filters' => $filters,
            'queue_summary' => $this->getQueueSummary($filters),
            'history_summary' => $this->getHistorySummary($filters),
            'statistics' => $this->getEditStatistics(),
            'recommendations' => $this->generateRecommendations()
        ];
        
        return $report;
    }

    /**
     * Validates edit data.
     * 
     * @param array $editData Edit data
     * @throws \Exception on validation failure
     */
    private function validateEditData(array $editData): void
    {
        if (empty($editData)) {
            throw new \Exception("Edit data is required");
        }
        
        if (!isset($editData['transaction_id'])) {
            throw new \Exception("Transaction ID is required");
        }
        
        if (!isset($editData['edit_type'])) {
            throw new \Exception("Edit type is required");
        }
        
        if (!isset($editData['edit_data'])) {
            throw new \Exception("Edit data is required");
        }
        
        $validTypes = [self::EDIT_TYPE_CORRECTION, self::EDIT_TYPE_MODIFICATION, self::EDIT_TYPE_VOID];
        if (!in_array($editData['edit_type'], $validTypes)) {
            throw new \Exception("Invalid edit type");
        }
    }

    /**
     * Checks edit permissions.
     * 
     * @param array $editData Edit data
     * @throws \Exception on permission failure
     */
    private function checkEditPermissions(array $editData): void
    {
        if (!$this->config['enable_editing']) {
            throw new \Exception("Editing is disabled");
        }
        
        $userId = $editData['user_id'] ?? 0;
        $editType = $editData['edit_type'];
        
        if ($userId <= 0) {
            throw new \Exception("User ID is required");
        }
        
        $permissions = $this->getEditPermissions($userId);
        
        if (empty($permissions)) {
            throw new \Exception("No edit permissions found for user");
        }
        
        switch ($editType) {
            case self::EDIT_TYPE_CORRECTION:
                if (!in_array('corrections', $permissions)) {
                    throw new \Exception("User does not have correction permissions");
                }
                break;
            case self::EDIT_TYPE_MODIFICATION:
                if (!in_array('modifications', $permissions)) {
                    throw new \Exception("User does not have modification permissions");
                }
                break;
            case self::EDIT_TYPE_VOID:
                if (!in_array('voids', $permissions)) {
                    throw new \Exception("User does not have void permissions");
                }
                break;
        }
    }

    /**
     * Creates edit entry.
     * 
     * @param array $editData Edit data
     * @return array Edit entry
     */
    private function createEditEntry(array $editData): array
    {
        $entry = [
            'edit_id' => uniqid('edit_'),
            'transaction_id' => $editData['transaction_id'],
            'edit_type' => $editData['edit_type'],
            'edit_data' => $editData['edit_data'],
            'original_data' => $editData['original_data'] ?? [],
            'user_id' => $editData['user_id'],
            'priority' => $this->calculateEditPriority($editData),
            'created_at' => time(),
            'status' => self::EDIT_STATUS_PENDING,
            'metadata' => $editData['metadata'] ?? []
        ];
        
        return $entry;
    }

    /**
     * Validates edit.
     * 
     * @param array $edit Edit data
     * @return array Validation result
     */
    private function validateEdit(array $edit): array
    {
        $validation = [
            'success' => true,
            'message' => 'Edit validation passed',
            'validation_details' => []
        ];
        
        // Validate based on edit type
        switch ($edit['edit_type']) {
            case self::EDIT_TYPE_CORRECTION:
                $correctionValidation = $this->validateCorrection($edit);
                $validation['success'] = $correctionValidation['success'];
                $validation['message'] = $correctionValidation['message'];
                $validation['validation_details'] = $correctionValidation['details'];
                break;
            case self::EDIT_TYPE_MODIFICATION:
                $modificationValidation = $this->validateModification($edit);
                $validation['success'] = $modificationValidation['success'];
                $validation['message'] = $modificationValidation['message'];
                $validation['validation_details'] = $modificationValidation['details'];
                break;
            case self::EDIT_TYPE_VOID:
                $voidValidation = $this->validateVoid($edit);
                $validation['success'] = $voidValidation['success'];
                $validation['message'] = $voidValidation['message'];
                $validation['validation_details'] = $voidValidation['details'];
                break;
        }
        
        return $validation;
    }

    /**
     * Validates correction edit.
     * 
     * @param array $edit Edit data
     * @return array Validation result
     */
    private function validateCorrection(array $edit): array
    {
        $correctionData = $edit['edit_data'];
        
        // Validate correction data
        if (!isset($correctionData['field'])) {
            return [
                'success' => false,
                'message' => 'Field to correct is required',
                'details' => []
            ];
        }
        
        if (!isset($correctionData['old_value'])) {
            return [
                'success' => false,
                'message' => 'Old value is required',
                'details' => []
            ];
        }
        
        if (!isset($correctionData['new_value'])) {
            return [
                'success' => false,
                'message' => 'New value is required',
                'details' => []
            ];
        }
        
        // Check if the old value matches the current value
        $currentValue = $this->getCurrentTransactionValue($edit['transaction_id'], $correctionData['field']);
        if ($currentValue != $correctionData['old_value']) {
            return [
                'success' => false,
                'message' => 'Old value does not match current value',
                'details' => [
                    'current_value' => $currentValue,
                    'provided_old_value' => $correctionData['old_value']
                ]
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Correction validation passed',
            'details' => [
                'field' => $correctionData['field'],
                'old_value' => $correctionData['old_value'],
                'new_value' => $correctionData['new_value']
            ]
        ];
    }

    /**
     * Validates modification edit.
     * 
     * @param array $edit Edit data
     * @return array Validation result
     */
    private function validateModification(array $edit): array
    {
        $modificationData = $edit['edit_data'];
        
        // Validate modification data
        if (!isset($modificationData['changes']) || !is_array($modificationData['changes'])) {
            return [
                'success' => false,
                'message' => 'Changes array is required',
                'details' => []
            ];
        }
        
        // Validate each change
        foreach ($modificationData['changes'] as $change) {
            if (!isset($change['field'])) {
                return [
                    'success' => false,
                    'message' => 'Field is required for each change',
                    'details' => []
                ];
            }
            
            if (!isset($change['value'])) {
                return [
                    'success' => false,
                    'message' => 'Value is required for each change',
                    'details' => []
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Modification validation passed',
            'details' => [
                'changes_count' => count($modificationData['changes']),
                'changes' => $modificationData['changes']
            ]
        ];
    }

    /**
     * Validates void edit.
     * 
     * @param array $edit Edit data
     * @return array Validation result
     */
    private function validateVoid(array $edit): array
    {
        $voidData = $edit['edit_data'];
        
        // Validate void data
        if (!isset($voidData['reason'])) {
            return [
                'success' => false,
                'message' => 'Void reason is required',
                'details' => []
            ];
        }
        
        if (empty($voidData['reason'])) {
            return [
                'success' => false,
                'message' => 'Void reason cannot be empty',
                'details' => []
            ];
        }
        
        // Check if transaction can be voided
        $canVoid = $this->checkTransactionVoidability($edit['transaction_id']);
        if (!$canVoid['can_void']) {
            return [
                'success' => false,
                'message' => $canVoid['reason'],
                'details' => $canVoid['details']
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Void validation passed',
            'details' => [
                'reason' => $voidData['reason'],
                'can_void' => true
            ]
        ];
    }

    /**
     * Calculates edit priority.
     * 
     * @param array $editData Edit data
     * @return string Priority level
     */
    private function calculateEditPriority(array $editData): string
    {
        $amount = $editData['amount'] ?? 0;
        $editType = $editData['edit_type'];
        
        // Void edits have highest priority
        if ($editType === self::EDIT_TYPE_VOID) {
            return 'critical';
        }
        
        // Amount-based priority
        if ($amount > 10000) {
            return 'high';
        } elseif ($amount > 5000) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Determines if auto-approval should be performed.
     * 
     * @param array $editData Edit data
     * @return bool True if auto-approval should be performed
     */
    private function shouldAutoApprove(array $editData): bool
    {
        $amount = $editData['amount'] ?? 0;
        $editType = $editData['edit_type'];
        $userId = $editData['user_id'];
        
        // Check if edit type is auto-approvable
        if ($editType === self::EDIT_TYPE_VOID) {
            return false;
        }
        
        // Check amount threshold
        if ($amount > $this->config['auto_approve_threshold']) {
            return false;
        }
        
        // Check user permissions
        $permissions = $this->getEditPermissions($userId);
        if (!in_array('auto_approve', $permissions)) {
            return false;
        }
        
        return true;
    }

    /**
     * Performs auto-approval.
     * 
     * @param array $edit Edit data
     * @return array Auto-approval result
     */
    private function performAutoApproval(array $edit): array
    {
        $edit['status'] = self::EDIT_STATUS_APPROVED;
        $edit['approved_by'] = 'system';
        $edit['approved_at'] = time();
        $edit['auto_approved'] = true;
        
        return [
            'success' => true,
            'edit_id' => $edit['edit_id'],
            'method' => 'auto',
            'reason' => 'Amount below threshold and user has auto-approval permissions',
            'timestamp' => time()
        ];
    }

    /**
     * Processes approval.
     * 
     * @param array $edit Edit data
     * @return array Approval result
     */
    private function processApproval(array $edit): array
    {
        // This would be implemented with actual approval processing logic
        return [
            'success' => true,
            'edit_id' => $edit['edit_id'],
            'transaction_id' => $edit['transaction_id'],
            'approved_by' => $edit['approved_by'],
            'approved_at' => $edit['approved_at'],
            'edit_type' => $edit['edit_type'],
            'message' => 'Edit approved successfully'
        ];
    }

    /**
     * Processes rejection.
     * 
     * @param array $edit Edit data
     * @return array Rejection result
     */
    private function processRejection(array $edit): array
    {
        // This would be implemented with actual rejection processing logic
        return [
            'success' => true,
            'edit_id' => $edit['edit_id'],
            'transaction_id' => $edit['transaction_id'],
            'rejected_by' => $edit['rejected_by'],
            'rejected_at' => $edit['rejected_at'],
            'reason' => $edit['rejection_reason'],
            'message' => 'Edit rejected successfully'
        ];
    }

    /**
     * Applies edit changes to transaction.
     * 
     * @param array $edit Edit data
     */
    private function applyEditChanges(array $edit): void
    {
        $editType = $edit['edit_type'];
        
        switch ($editType) {
            case self::EDIT_TYPE_CORRECTION:
                $this->applyCorrection($edit);
                break;
            case self::EDIT_TYPE_MODIFICATION:
                $this->applyModification($edit);
                break;
            case self::EDIT_TYPE_VOID:
                $this->applyVoid($edit);
                break;
        }
    }

    /**
     * Applies correction to transaction.
     * 
     * @param array $edit Edit data
     */
    private function applyCorrection(array $edit): void
    {
        $correctionData = $edit['edit_data'];
        
        // This would be implemented with actual correction application logic
        $this->updateTransactionField($edit['transaction_id'], $correctionData['field'], $correctionData['new_value']);
    }

    /**
     * Applies modification to transaction.
     * 
     * @param array $edit Edit data
     */
    private function applyModification(array $edit): void
    {
        $modificationData = $edit['edit_data'];
        
        // This would be implemented with actual modification application logic
        foreach ($modificationData['changes'] as $change) {
            $this->updateTransactionField($edit['transaction_id'], $change['field'], $change['value']);
        }
    }

    /**
     * Applies void to transaction.
     * 
     * @param array $edit Edit data
     */
    private function applyVoid(array $edit): void
    {
        $voidData = $edit['edit_data'];
        
        // This would be implemented with actual void application logic
        $this->voidTransaction($edit['transaction_id'], $voidData['reason']);
    }

    /**
     * Gets current transaction value.
     * 
     * @param int $transactionId Transaction ID
     * @param string $field Field name
     * @return mixed Current value
     */
    private function getCurrentTransactionValue(int $transactionId, string $field)
    {
        // This would be implemented with actual value retrieval logic
        return 'current_value';
    }

    /**
     * Checks if transaction can be voided.
     * 
     * @param int $transactionId Transaction ID
     * @return array Voidability check result
     */
    private function checkTransactionVoidability(int $transactionId): array
    {
        // This would be implemented with actual voidability check logic
        return [
            'can_void' => true,
            'reason' => '',
            'details' => []
        ];
    }

    /**
     * Updates transaction field.
     * 
     * @param int $transactionId Transaction ID
     * @param string $field Field name
     * @param mixed $value New value
     */
    private function updateTransactionField(int $transactionId, string $field, $value): void
    {
        // This would be implemented with actual field update logic
    }

    /**
     * Voids transaction.
     * 
     * @param int $transactionId Transaction ID
     * @param string $reason Void reason
     */
    private function voidTransaction(int $transactionId, string $reason): void
    {
        // This would be implemented with actual void logic
    }

    /**
     * Initializes edit permissions.
     */
    private function initializeEditPermissions(): void
    {
        // This would be implemented with actual permission initialization logic
        $this->editPermissions = [
            1 => ['corrections', 'modifications', 'voids', 'auto_approve'],
            2 => ['corrections', 'modifications'],
            3 => ['corrections']
        ];
    }

    /**
     * Adds to edit queue.
     * 
     * @param array $edit Edit data
     */
    private function addToEditQueue(array $edit): void
    {
        $this->editQueue[] = $edit;
    }

    /**
     * Removes from edit queue.
     * 
     * @param string $editId Edit ID
     */
    private function removeFromEditQueue(string $editId): void
    {
        $this->editQueue = array_filter($this->editQueue, fn($e) => $e['edit_id'] != $editId);
    }

    /**
     * Adds to edit history.
     * 
     * @param array $edit Edit data
     */
    private function addToEditHistory(array $edit): void
    {
        $this->editHistory[] = $edit;
    }

    /**
     * Finds edit by ID.
     * 
     * @param string $editId Edit ID
     * @return array|null Edit or null
     */
    private function findEditById(string $editId): ?array
    {
        foreach ($this->editQueue as $edit) {
            if ($edit['edit_id'] == $editId) {
                return $edit;
            }
        }
        return null;
    }

    /**
     * Logs edit.
     * 
     * @param array $edit Edit data
     */
    private function logEdit(array $edit): void
    {
        $logMessage = sprintf(
            "[%s] [%s] Edit ID: %s, Transaction ID: %s, Type: %s, Status: %s, User: %s\n",
            date('Y-m-d H:i:s'),
            'EDIT',
            $edit['edit_id'],
            $edit['transaction_id'],
            $edit['edit_type'],
            $edit['status'],
            $edit['user_id']
        );
        
        file_put_contents($this->config['edit_log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Gets queue summary.
     * 
     * @param array $filters Filter parameters
     * @return array Queue summary
     */
    private function getQueueSummary(array $filters = []): array
    {
        $queue = $this->getEditQueue($filters);
        
        return [
            'total_edits' => count($queue),
            'pending_edits' => count(array_filter($queue, fn($e) => $e['status'] === self::EDIT_STATUS_PENDING)),
            'approved_edits' => count(array_filter($queue, fn($e) => $e['status'] === self::EDIT_STATUS_APPROVED)),
            'rejected_edits' => count(array_filter($queue, fn($e) => $e['status'] === self::EDIT_STATUS_REJECTED)),
            'high_priority' => count(array_filter($queue, fn($e) => $e['priority'] === 'high')),
            'medium_priority' => count(array_filter($queue, fn($e) => $e['priority'] === 'medium')),
            'low_priority' => count(array_filter($queue, fn($e) => $e['priority'] === 'low'))
        ];
    }

    /**
     * Gets history summary.
     * 
     * @param array $filters Filter parameters
     * @return array History summary
     */
    private function getHistorySummary(array $filters = []): array
    {
        $history = $this->getEditHistory($filters);
        
        return [
            'total_edits' => count($history),
            'approved_edits' => count(array_filter($history, fn($h) => $h['status'] === self::EDIT_STATUS_APPROVED)),
            'rejected_edits' => count(array_filter($history, fn($h) => $h['status'] === self::EDIT_STATUS_REJECTED)),
            'cancelled_edits' => count(array_filter($history, fn($h) => $h['status'] === self::EDIT_STATUS_CANCELLED)),
            'auto_approved' => count(array_filter($history, fn($h) => isset($h['auto_approved']) && $h['auto_approved'])),
            'manual_approved' => count(array_filter($history, fn($h) => !isset($h['auto_approved']) || !$h['auto_approved']))
        ];
    }

    /**
     * Gets edit statistics.
     * 
     * @return array Edit statistics
     */
    private function getEditStatistics(): array
    {
        $stats = [
            'total_edits' => count($this->editHistory),
            'total_edits_by_type' => $this->countEditsByType(),
            'total_edits_by_status' => $this->countEditsByStatus(),
            'average_edit_time' => $this->calculateAverageEditTime(),
            'edit_success_rate' => $this->calculateEditSuccessRate(),
            'edit_backlog' => count($this->editQueue),
            'top_editors' => $this->getTopEditors()
        ];
        
        return $stats;
    }

    /**
     * Counts edits by type.
     * 
     * @return array Counts by type
     */
    private function countEditsByType(): array
    {
        $counts = [];
        
        foreach ($this->editHistory as $edit) {
            $type = $edit['edit_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Counts edits by status.
     * 
     * @return array Counts by status
     */
    private function countEditsByStatus(): array
    {
        $counts = [];
        
        foreach ($this->editHistory as $edit) {
            $status = $edit['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        
        return $counts;
    }

    /**
     * Calculates average edit time.
     * 
     * @return float Average edit time in seconds
     */
    private function calculateAverageEditTime(): float
    {
        $totalTime = 0;
        $count = 0;
        
        foreach ($this->editHistory as $edit) {
            if (isset($edit['edited_at']) && isset($edit['created_at'])) {
                $totalTime += $edit['edited_at'] - $edit['created_at'];
                $count++;
            }
        }
        
        return $count > 0 ? $totalTime / $count : 0;
    }

    /**
     * Calculates edit success rate.
     * 
     * @return float Success rate
     */
    private function calculateEditSuccessRate(): float
    {
        if (empty($this->editHistory)) {
            return 0;
        }
        
        $successful = count(array_filter($this->editHistory, fn($h) => $h['status'] === self::EDIT_STATUS_APPROVED));
        $total = count($this->editHistory);
        
        return $total > 0 ? $successful / $total : 0;
    }

    /**
     * Gets top editors.
     * 
     * @return array Top editors
     */
    private function getTopEditors(): array
    {
        $editorCounts = [];
        
        foreach ($this->editHistory as $edit) {
            $editor = $edit['edited_by'] ?? $edit['user_id'];
            $editorCounts[$editor] = ($editorCounts[$editor] ?? 0) + 1;
        }
        
        arsort($editorCounts);
        return array_slice($editorCounts, 0, 5, true);
    }

    /**
     * Generates recommendations.
     * 
     * @return array Recommendations
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];
        
        // Queue recommendations
        if (count($this->editQueue) > 50) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'queue_management',
                'message' => 'High edit backlog detected. Consider adding more approvers.'
            ];
        }
        
        // Performance recommendations
        $avgEditTime = $this->calculateAverageEditTime();
        if ($avgEditTime > 3600) { // 1 hour
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'performance',
                'message' => 'Average edit time is high. Consider optimizing approval processes.'
            ];
        }
        
        // Quality recommendations
        $successRate = $this->calculateEditSuccessRate();
        if ($successRate < 0.8) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'quality',
                'message' => 'Low edit success rate. Consider improving edit validation.'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Gets configuration.
     * 
     * @return array Configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Sets configuration.
     * 
     * @param array $config Configuration to set
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Clears edit queue.
     */
    public function clearEditQueue(): void
    {
        $this->editQueue = [];
    }

    /**
     * Clears edit history.
     */
    public function clearEditHistory(): void
    {
        $this->editHistory = [];
    }

    /**
     * Clears edit permissions.
     */
    public function clearEditPermissions(): void
    {
        $this->editPermissions = [];
    }
}