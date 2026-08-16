<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Controllers;

use ksfraser\FrontAccounting\Square\Services\TransactionCorrectionService;
use ksfraser\FrontAccounting\Square\Services\TransactionReviewService;
use ksfraser\FrontAccounting\Square\Services\TransactionHistoryService;
use ksfraser\FrontAccounting\Square\Interfaces\TransactionCorrectionInterface;
use ksfraser\FrontAccounting\Square\Exceptions\TransactionException;
use ksfraser\FrontAccounting\Square\Exceptions\DebtorException;
use ksfraser\FrontAccounting\Square\Exceptions\CorrectionException;

/**
 * Transaction Correction Controller
 * 
 * Handles HTTP requests for transaction correction operations.
 * Provides RESTful API endpoints for debtor assignment corrections.
 * 
 * @UML Note: Controller layer for HTTP request handling
 * @BABOK Related: System integration, API management, User interface design
 */
class TransactionCorrectionController implements TransactionCorrectionInterface
{
    private TransactionCorrectionService $correctionService;
    private TransactionReviewService $reviewService;
    private TransactionHistoryService $historyService;
    
    /**
     * Constructor
     * 
     * @param TransactionCorrectionService $correctionService Transaction correction service
     * @param TransactionReviewService $reviewService Transaction review service
     * @param TransactionHistoryService $historyService Transaction history service
     */
    public function __construct(
        TransactionCorrectionService $correctionService,
        TransactionReviewService $reviewService,
        TransactionHistoryService $historyService
    ) {
        $this->correctionService = $correctionService;
        $this->reviewService = $reviewService;
        $this->historyService = $historyService;
    }

    /**
     * Handles HTTP requests for transaction correction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleRequest(array $request): array
    {
        try {
            $method = $request['method'] ?? 'GET';
            $action = $request['action'] ?? 'index';
            
            switch ($method) {
                case 'POST':
                    return $this->handlePostRequest($action, $request);
                case 'GET':
                    return $this->handleGetRequest($action, $request);
                case 'PUT':
                    return $this->handlePutRequest($action, $request);
                case 'DELETE':
                    return $this->handleDeleteRequest($action, $request);
                default:
                    return $this->createErrorResponse(405, 'Method not allowed');
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Handles POST requests.
     * 
     * @param string $action Action to perform
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    private function handlePostRequest(string $action, array $request): array
    {
        switch ($action) {
            case 'correct':
                return $this->correctTransaction($request);
            case 'correct_fa':
                return $this->handleCorrectFaTransaction($request);
            case 'preview':
                return $this->previewCorrection($request);
            case 'simulate':
                return $this->handleSimulateCorrection($request);
            case 'validate':
                return $this->validateCorrection($request);
            default:
                return $this->createErrorResponse(404, 'Action not found');
        }
    }

    /**
     * Handles GET requests.
     * 
     * @param string $action Action to perform
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    private function handleGetRequest(string $action, array $request): array
    {
        switch ($action) {
            case 'index':
                return $this->index($request);
            case 'history':
                return $this->handleGetCorrectionHistory($request);
            case 'statistics':
                return $this->handleGetCorrectionStatistics($request);
            case 'capabilities':
                return $this->getCapabilities($request);
            case 'recommendations':
                return $this->getRecommendations($request);
            case 'preview':
                return $this->handleGetCorrectionPreview($request);
            case 'impact':
                return $this->getCorrectionImpact($request);
            case 'available_debtors':
                return $this->getAvailableDebtors($request);
            default:
                return $this->createErrorResponse(404, 'Action not found');
        }
    }

    /**
     * Handles PUT requests.
     * 
     * @param string $action Action to perform
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    private function handlePutRequest(string $action, array $request): array
    {
        switch ($action) {
            case 'configuration':
                return $this->handleUpdateConfiguration($request);
            case 'rollback':
                return $this->handlePerformRollback($request);
            default:
                return $this->createErrorResponse(404, 'Action not found');
        }
    }

    /**
     * Handles DELETE requests.
     * 
     * @param string $action Action to perform
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    private function handleDeleteRequest(string $action, array $request): array
    {
        switch ($action) {
            case 'correction':
                return $this->deleteCorrection($request);
            default:
                return $this->createErrorResponse(404, 'Action not found');
        }
    }

    /**
     * Corrects debtor assignment for a transaction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function correctTransaction(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            $newDebtorId = (int)($request['new_debtor_id'] ?? 0);
            $correctionData = $request['correction_data'] ?? [];
            
            // Validate required parameters
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            if ($newDebtorId <= 0) {
                return $this->createErrorResponse(400, 'New debtor ID is required');
            }
            
            // Perform correction
            $result = $this->correctDebtorAssignment($transactionId, $newDebtorId, $correctionData);
            
            return $this->createSuccessResponse($result, 'Transaction corrected successfully');
        } catch (TransactionException $e) {
            return $this->createErrorResponse(400, $e->getMessage());
        } catch (DebtorException $e) {
            return $this->createErrorResponse(400, $e->getMessage());
        } catch (CorrectionException $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, 'An unexpected error occurred');
        }
    }

    /**
     * Corrects a generic FA transaction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleCorrectFaTransaction(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            $newDebtorId = (int)($request['new_debtor_id'] ?? 0);
            $correctionData = $request['correction_data'] ?? [];
            
            // Validate required parameters
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            if ($newDebtorId <= 0) {
                return $this->createErrorResponse(400, 'New debtor ID is required');
            }
            
            // Perform FA correction
            $result = $this->correctionService->correctFaTransaction($transactionId, $newDebtorId, $correctionData);
            
            return $this->createSuccessResponse($result, 'FA transaction corrected successfully');
        } catch (TransactionException $e) {
            return $this->createErrorResponse(400, $e->getMessage());
        } catch (DebtorException $e) {
            return $this->createErrorResponse(400, $e->getMessage());
        } catch (CorrectionException $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, 'An unexpected error occurred');
        }
    }

    /**
     * Gets correction preview for a transaction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleGetCorrectionPreview(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            $newDebtorId = (int)($request['new_debtor_id'] ?? 0);
            $correctionData = $request['correction_data'] ?? [];
            
            // Validate required parameters
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            if ($newDebtorId <= 0) {
                return $this->createErrorResponse(400, 'New debtor ID is required');
            }
            
            // Get correction preview
            $preview = $this->correctionService->getCorrectionPreview($transactionId, $newDebtorId, $correctionData);
            
            return $this->createSuccessResponse($preview, 'Correction preview generated');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Simulates correction operation.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleSimulateCorrection(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            $newDebtorId = (int)($request['new_debtor_id'] ?? 0);
            $correctionData = $request['correction_data'] ?? [];
            
            // Validate required parameters
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            if ($newDebtorId <= 0) {
                return $this->createErrorResponse(400, 'New debtor ID is required');
            }
            
            // Get transaction details
            $transaction = $this->correctionService->getTransactionDetails($transactionId);
            
            // Simulate correction
            $simulation = $this->correctionService->simulateCorrection($transaction, $newDebtorId, $correctionData);
            
            return $this->createSuccessResponse($simulation, 'Correction simulation completed');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Validates correction data.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function validateCorrection(array $request): array
    {
        try {
            $correctionData = $request['correction_data'] ?? [];
            
            // Validate correction data
            $validation = $this->correctionService->validateCorrectionData($correctionData);
            
            return $this->createSuccessResponse($validation, 'Validation completed');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets correction history for a transaction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleGetCorrectionHistory(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            $history = $this->correctionService->getCorrectionHistory($transactionId);
            
            return $this->createSuccessResponse($history, 'Correction history retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets all correction history.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function index(array $request): array
    {
        try {
            $history = $this->correctionService->getAllCorrectionHistory();
            
            return $this->createSuccessResponse($history, 'All correction history retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets correction statistics.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleGetCorrectionStatistics(array $request): array
    {
        try {
            $statistics = $this->correctionService->getCorrectionStatistics();
            
            return $this->createSuccessResponse($statistics, 'Correction statistics retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets correction capabilities.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function getCapabilities(array $request): array
    {
        try {
            $capabilities = [
                'source_detection' => $this->correctionService->getSourceDetectionCapabilities(),
                'supported_methods' => $this->correctionService->getSupportedMethods(),
                'error_handling' => $this->correctionService->getErrorHandlingCapabilities(),
                'rollback' => $this->correctionService->getRollbackCapabilities(),
                'configuration' => $this->correctionService->getConfiguration()
            ];
            
            return $this->createSuccessResponse($capabilities, 'Capabilities retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets correction recommendations.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function getRecommendations(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            $recommendations = $this->correctionService->getCorrectionRecommendations($transactionId);
            
            return $this->createSuccessResponse($recommendations, 'Recommendations retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets correction impact assessment.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function getCorrectionImpact(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            $newDebtorId = (int)($request['new_debtor_id'] ?? 0);
            
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            if ($newDebtorId <= 0) {
                return $this->createErrorResponse(400, 'New debtor ID is required');
            }
            
            // Get transaction details
            $transaction = $this->correctionService->getTransactionDetails($transactionId);
            
            // Get impact assessment
            $impact = $this->correctionService->validateCorrectionImpact($transaction, $newDebtorId);
            
            return $this->createSuccessResponse($impact, 'Impact assessment completed');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Gets available debtor options.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function getAvailableDebtors(array $request): array
    {
        try {
            $transactionId = (int)($request['transaction_id'] ?? 0);
            
            if ($transactionId <= 0) {
                return $this->createErrorResponse(400, 'Transaction ID is required');
            }
            
            $debtors = $this->correctionService->getAvailableDebtorOptions($transactionId);
            
            return $this->createSuccessResponse($debtors, 'Available debtors retrieved');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Updates correction configuration.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handleUpdateConfiguration(array $request): array
    {
        try {
            $configuration = $request['configuration'] ?? [];
            
            if (empty($configuration)) {
                return $this->createErrorResponse(400, 'Configuration data is required');
            }
            
            $updated = $this->correctionService->updateConfiguration($configuration);
            
            if ($updated) {
                return $this->createSuccessResponse([], 'Configuration updated successfully');
            } else {
                return $this->createErrorResponse(400, 'Failed to update configuration');
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Performs correction rollback.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function handlePerformRollback(array $request): array
    {
        try {
            $correctionId = (int)($request['correction_id'] ?? 0);
            
            if ($correctionId <= 0) {
                return $this->createErrorResponse(400, 'Correction ID is required');
            }
            
            $result = $this->correctionService->performRollback($correctionId);
            
            return $this->createSuccessResponse($result, 'Rollback completed successfully');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Deletes a correction.
     * 
     * @param array $request HTTP request data
     * @return array HTTP response
     */
    public function deleteCorrection(array $request): array
    {
        try {
            $correctionId = (int)($request['correction_id'] ?? 0);
            
            if ($correctionId <= 0) {
                return $this->createErrorResponse(400, 'Correction ID is required');
            }
            
            // Perform rollback
            $result = $this->correctionService->performRollback($correctionId);
            
            return $this->createSuccessResponse($result, 'Correction deleted successfully');
        } catch (\Exception $e) {
            return $this->createErrorResponse(500, $e->getMessage());
        }
    }

    /**
     * Creates success response.
     * 
     * @param array $data Response data
     * @param string $message Success message
     * @return array HTTP response
     */
    private function createSuccessResponse(array $data, string $message): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'status' => 200
        ];
    }

    /**
     * Creates error response.
     * 
     * @param int $status HTTP status code
     * @param string $message Error message
     * @return array HTTP response
     */
    private function createErrorResponse(int $status, string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => [],
            'status' => $status
        ];
    }

    // Interface method implementations
    public function correctDebtorAssignment(int $transactionId, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->correctDebtorAssignment($transactionId, $newDebtorId, $correctionData);
    }

    public function correctFaTransaction(int $transactionId, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->correctFaTransaction($transactionId, $newDebtorId, $correctionData);
    }

    public function performCloneVoidMethod(array $transaction, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->performCloneVoidMethod($transaction, $newDebtorId, $correctionData);
    }

    public function canCorrectTransaction(array $transaction): bool
    {
        return $this->correctionService->canCorrectTransaction($transaction);
    }

    public function determineTransactionSource(array $transaction): string
    {
        return $this->correctionService->determineTransactionSource($transaction);
    }

    public function hasTransactionBeenModified(array $transaction): bool
    {
        return $this->correctionService->hasTransactionBeenModified($transaction);
    }

    public function validateCorrectionRequest(int $transactionId, int $newDebtorId): void
    {
        $this->correctionService->validateCorrectionRequest($transactionId, $newDebtorId);
    }

    public function getCorrectionHistory(int $transactionId): array
    {
        return $this->correctionService->getCorrectionHistory($transactionId);
    }

    public function getAllCorrectionHistory(): array
    {
        return $this->correctionService->getAllCorrectionHistory();
    }

    public function debtorExists(int $debtorId): bool
    {
        return $this->correctionService->debtorExists($debtorId);
    }

    public function hasActiveCorrection(int $transactionId): bool
    {
        return $this->correctionService->hasActiveCorrection($transactionId);
    }

    public function getTransactionDetails(int $transactionId): array
    {
        return $this->correctionService->getTransactionDetails($transactionId);
    }

    public function cloneTransactionCart(array $transaction): array
    {
        return $this->correctionService->cloneTransactionCart($transaction);
    }

    public function voidOriginalTransaction(array $transaction): array
    {
        return $this->correctionService->voidOriginalTransaction($transaction);
    }

    public function createCorrectedTransaction(array $clonedCart, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->createCorrectedTransaction($clonedCart, $newDebtorId, $correctionData);
    }

    public function linkTransactions(array $originalTransaction, array $newTransaction): void
    {
        $this->correctionService->linkTransactions($originalTransaction, $newTransaction);
    }

    public function updateTransactionHistory(array $originalTransaction, array $newTransaction): void
    {
        $this->correctionService->updateTransactionHistory($originalTransaction, $newTransaction);
    }

    public function logCorrection(array $result, string $transactionSource = 'unknown'): void
    {
        $this->correctionService->logCorrection($result, $transactionSource);
    }

    public function trackTransactionHistory(int $transactionId, array $result, string $transactionSource = 'unknown'): void
    {
        $this->correctionService->trackTransactionHistory($transactionId, $result, $transactionSource);
    }

    public function getSourceDetectionCapabilities(): array
    {
        return $this->correctionService->getSourceDetectionCapabilities();
    }

    public function getSupportedMethods(): array
    {
        return $this->correctionService->getSupportedMethods();
    }

    public function getConfiguration(): array
    {
        return $this->correctionService->getConfiguration();
    }

    public function updateConfiguration(array $configuration): bool
    {
        return $this->correctionService->updateConfiguration($configuration);
    }

    public function getCorrectionStatistics(): array
    {
        return $this->correctionService->getCorrectionStatistics();
    }

    public function supportsAttachmentHandling(array $transaction): bool
    {
        return $this->correctionService->supportsAttachmentHandling($transaction);
    }

    public function validateCorrectionData(array $correctionData): array
    {
        return $this->correctionService->validateCorrectionData($correctionData);
    }

    public function getAvailableDebtorOptions(int $transactionId): array
    {
        return $this->correctionService->getAvailableDebtorOptions($transactionId);
    }

    public function getCorrectionPreview(int $transactionId, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->getCorrectionPreview($transactionId, $newDebtorId, $correctionData);
    }

    public function simulateCorrection(array $transaction, int $newDebtorId, array $correctionData = []): array
    {
        return $this->correctionService->simulateCorrection($transaction, $newDebtorId, $correctionData);
    }

    public function getCorrectionRecommendations(int $transactionId): array
    {
        return $this->correctionService->getCorrectionRecommendations($transactionId);
    }

    public function validateCorrectionImpact(array $transaction, int $newDebtorId): array
    {
        return $this->correctionService->validateCorrectionImpact($transaction, $newDebtorId);
    }

    public function getErrorHandlingCapabilities(): array
    {
        return $this->correctionService->getErrorHandlingCapabilities();
    }

    public function performRollback(int $correctionId): array
    {
        return $this->correctionService->performRollback($correctionId);
    }

    public function getRollbackCapabilities(): array
    {
        return $this->correctionService->getRollbackCapabilities();
    }
}