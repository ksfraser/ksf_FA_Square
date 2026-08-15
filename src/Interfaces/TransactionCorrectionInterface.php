<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Interfaces;

/**
 * Transaction Correction Service Interface
 * 
 * Defines the contract for transaction correction services that handle debtor assignment
 * corrections for both Square staging transactions and generic FA transactions.
 * 
 * @UML Note: Interface in service layer for SOLID principle implementation
 * @BABOK Related: Solution design, System integration, Change management
 */
interface TransactionCorrectionInterface
{
    /**
     * Corrects debtor assignment for a transaction.
     * 
     * Supports both Square staging transactions and generic FA transactions.
     * Uses appropriate method based on transaction type and configuration.
     * 
     * @param int $transactionId Transaction ID
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array Correction results
     * @throws TransactionException When transaction cannot be corrected
     * @throws DebtorException When debtor ID is invalid
     * @throws CorrectionException When correction is disabled or fails
     * 
     * @UML Note: Public method for external service interaction
     * @BABOK Related: Requirements implementation, Quality assurance
     */
    public function correctDebtorAssignment(int $transactionId, int $newDebtorId, array $correctionData = []): array;

    /**
     * Corrects a generic FA transaction (not from Square staging).
     * 
     * Uses FA-specific logic and validation for transaction correction.
     * 
     * @param int $transactionId FA transaction ID
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array Correction results
     * @throws TransactionException When FA transaction cannot be corrected
     * @throws DebtorException When debtor ID is invalid
     * @throws CorrectionException When correction fails
     * 
     * @UML Note: Public method for FA-specific correction
     * @BABOK Related: Solution design, Business process management
     */
    public function correctFaTransaction(int $transactionId, int $newDebtorId, array $correctionData = []): array;

    /**
     * Performs clone/void correction method for Square transactions.
     * 
     * Creates a clone of the transaction, voids the original, and creates a new
     * transaction with the correct debtor assignment.
     * 
     * @param array $transaction Transaction details
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array Correction results
     * @throws TransactionException When clone/void operation fails
     * 
     * @UML Note: Public method for clone/void correction
     * @BABOK Related: Process management, Error handling
     */
    public function performCloneVoidMethod(array $transaction, int $newDebtorId, array $correctionData = []): array;

    /**
     * Checks if transaction can be corrected.
     * 
     * Validates transaction age, status, and modification history.
     * 
     * @param array $transaction Transaction details
     * @return bool True if transaction can be corrected
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Quality control, Risk management
     */
    public function canCorrectTransaction(array $transaction): bool;

    /**
     * Determines transaction source (Square staging vs generic FA).
     * 
     * @param array $transaction Transaction details
     * @return string Transaction source ('square_staging', 'square_import', 'fa_generic')
     * 
     * @UML Note: Public method for transaction identification
     * @BABOK Related: Data analysis, System integration
     */
    public function determineTransactionSource(array $transaction): string;

    /**
     * Checks if transaction has been modified.
     * 
     * @param array $transaction Transaction details
     * @return bool True if transaction has been modified
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Data integrity, Audit management
     */
    public function hasTransactionBeenModified(array $transaction): bool;

    /**
     * Validates correction request.
     * 
     * @param int $transactionId Transaction ID
     * @param int $newDebtorId New debtor ID
     * @throws TransactionException When validation fails
     * @throws DebtorException When validation fails
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Requirements validation, Quality assurance
     */
    public function validateCorrectionRequest(int $transactionId, int $newDebtorId): void;

    /**
     * Gets correction history for a transaction.
     * 
     * @param int $transactionId Transaction ID
     * @return array Correction history
     * 
     * @UML Note: Public method for history retrieval
     * @BABOK Related: Audit management, Historical analysis
     */
    public function getCorrectionHistory(int $transactionId): array;

    /**
     * Gets all correction history.
     * 
     * @return array All correction history
     * 
     * @UML Note: Public method for comprehensive history retrieval
     * @BABOK Related: Reporting, Analytics
     */
    public function getAllCorrectionHistory(): array;

    /**
     * Checks if debtor exists.
     * 
     * @param int $debtorId Debtor ID
     * @return bool True if debtor exists
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Data validation, Business rule enforcement
     */
    public function debtorExists(int $debtorId): bool;

    /**
     * Checks if active correction exists for a transaction.
     * 
     * @param int $transactionId Transaction ID
     * @return bool True if active correction exists
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Conflict resolution, Process management
     */
    public function hasActiveCorrection(int $transactionId): bool;

    /**
     * Gets transaction details.
     * 
     * @param int $transactionId Transaction ID
     * @return array Transaction details
     * 
     * @UML Note: Public data retrieval method
     * @BABOK Related: Data access, Information retrieval
     */
    public function getTransactionDetails(int $transactionId): array;

    /**
     * Clones transaction cart with attachment handling.
     * 
     * @param array $transaction Transaction details
     * @return array Cloned cart
     * 
     * @UML Note: Public method for cart cloning
     * @BABOK Related: Data management, Process automation
     */
    public function cloneTransactionCart(array $transaction): array;

    /**
     * Voids original transaction.
     * 
     * @param array $transaction Transaction details
     * @return array Void result
     * 
     * @UML Note: Public method for transaction voiding
     * @BABOK Related: Process management, Error handling
     */
    public function voidOriginalTransaction(array $transaction): array;

    /**
     * Creates corrected transaction.
     * 
     * @param array $clonedCart Cloned cart
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array New transaction
     * 
     * @UML Note: Public method for transaction creation
     * @BABOK Related: Business process automation, Solution implementation
     */
    public function createCorrectedTransaction(array $clonedCart, int $newDebtorId, array $correctionData = []): array;

    /**
     * Links transactions together.
     * 
     * @param array $originalTransaction Original transaction
     * @param array $newTransaction New transaction
     * 
     * @UML Note: Public method for transaction linking
     * @BABOK Related: Data integrity, Relationship management
     */
    public function linkTransactions(array $originalTransaction, array $newTransaction): void;

    /**
     * Updates transaction history.
     * 
     * @param array $originalTransaction Original transaction
     * @param array $newTransaction New transaction
     * 
     * @UML Note: Public method for history management
     * @BABOK Related: Audit management, Historical tracking
     */
    public function updateTransactionHistory(array $originalTransaction, array $newTransaction): void;

    /**
     * Logs correction with transaction source information.
     * 
     * @param array $result Correction result
     * @param string $transactionSource Transaction source (optional)
     * 
     * @UML Note: Public method for logging
     * @BABOK Related: Audit management, Compliance tracking
     */
    public function logCorrection(array $result, string $transactionSource = 'unknown'): void;

    /**
     * Tracks transaction history with source information.
     * 
     * @param int $transactionId Transaction ID
     * @param array $result Correction result
     * @param string $transactionSource Transaction source (optional)
     * 
     * @UML Note: Public method for history tracking
     * @BABOK Related: Process management, Historical analysis
     */
    public function trackTransactionHistory(int $transactionId, array $result, string $transactionSource = 'unknown'): void;

    /**
     * Gets transaction source detection capabilities.
     * 
     * @return array Source detection capabilities
     * 
     * @UML Note: Public method for capability reporting
     * @BABOK Related: System integration, Data analysis
     */
    public function getSourceDetectionCapabilities(): array;

    /**
     * Gets supported correction methods.
     * 
     * @return array Supported correction methods
     * 
     * @UML Note: Public method for capability reporting
     * @BABOK Related: Solution design, Process management
     */
    public function getSupportedMethods(): array;

    /**
     * Gets correction configuration.
     * 
     * @return array Correction configuration
     * 
     * @UML Note: Public method for configuration management
     * @BABOK Related: Configuration management, System administration
     */
    public function getConfiguration(): array;

    /**
     * Updates correction configuration.
     * 
     * @param array $configuration New configuration
     * @return bool True if configuration was updated
     * 
     * @UML Note: Public method for configuration management
     * @BABOK Related: Configuration management, System administration
     */
    public function updateConfiguration(array $configuration): bool;

    /**
     * Gets correction statistics.
     * 
     * @return array Correction statistics
     * 
     * @UML Note: Public method for analytics
     * @BABOK Related: Analytics, Reporting
     */
    public function getCorrectionStatistics(): array;

    /**
     * Validates attachment handling capabilities.
     * 
     * @param array $transaction Transaction details
     * @return bool True if attachment handling is supported
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Data validation, Capability assessment
     */
    public function supportsAttachmentHandling(array $transaction): bool;

    /**
     * Validates correction data.
     * 
     * @param array $correctionData Correction data
     * @return array Validation result
     * 
     * @UML Note: Public validation method
     * @BABOK Related: Data validation, Quality assurance
     */
    public function validateCorrectionData(array $correctionData): array;

    /**
     * Gets available debtor options for correction.
     * 
     * @param int $transactionId Transaction ID
     * @return array Available debtor options
     * 
     * @UML Note: Public method for data retrieval
     * @BABOK Related: Business rule enforcement, Data validation
     */
    public function getAvailableDebtorOptions(int $transactionId): array;

    /**
     * Gets correction preview for a transaction.
     * 
     * @param int $transactionId Transaction ID
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array Correction preview
     * 
     * @UML Note: Public method for preview functionality
     * @BABOK Related: Requirements validation, Quality assurance
     */
    public function getCorrectionPreview(int $transactionId, int $newDebtorId, array $correctionData = []): array;

    /**
     * Performs correction preview simulation.
     * 
     * @param array $transaction Transaction details
     * @param int $newDebtorId New debtor ID
     * @param array $correctionData Correction data (optional)
     * @return array Simulation results
     * 
     * @UML Note: Public method for simulation
     * @BABOK Related: Risk assessment, Process testing
     */
    public function simulateCorrection(array $transaction, int $newDebtorId, array $correctionData = []): array;

    /**
     * Gets correction recommendations for a transaction.
     * 
     * @param int $transactionId Transaction ID
     * @return array Correction recommendations
     * 
     * @UML Note: Public method for recommendations
     * @BABOK Related: Business analysis, Decision support
     */
    public function getCorrectionRecommendations(int $transactionId): array;

    /**
     * Validates correction impact on related records.
     * 
     * @param array $transaction Transaction details
     * @param int $newDebtorId New debtor ID
     * @return array Impact assessment
     * 
     * @UML Note: Public method for impact assessment
     * @BABOK Related: Risk management, Impact analysis
     */
    public function validateCorrectionImpact(array $transaction, int $newDebtorId): array;

    /**
     * Gets correction error handling capabilities.
     * 
     * @return array Error handling capabilities
     * 
     * @UML Note: Public method for capability reporting
     * @BABOK Related: Error management, System reliability
     */
    public function getErrorHandlingCapabilities(): array;

    /**
     * Performs correction rollback.
     * 
     * @param int $correctionId Correction ID
     * @return array Rollback results
     * 
     * @UML Note: Public method for rollback functionality
     * @BABOK Related: Change management, Error recovery
     */
    public function performRollback(int $correctionId): array;

    /**
     * Gets correction rollback capabilities.
     * 
     * @return array Rollback capabilities
     * 
     * @UML Note: Public method for capability reporting
     * @BABOK Related: Risk management, System reliability
     */
    public function getRollbackCapabilities(): array;
}