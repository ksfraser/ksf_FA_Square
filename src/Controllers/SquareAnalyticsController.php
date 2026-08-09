<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Controllers;

use Ksfraser\Frontaccounting\SquareUp\Services\BusinessIntelligenceService;
/**
 * Square Analytics Controller
 * 
 * Handles business intelligence and analytics requests.
 * 
 * @UML Note: Controller diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-08.01 through FR-08.05 - Business Intelligence
 */
class SquareAnalyticsController
{
    private BusinessIntelligenceService $businessIntelligence;
    private string $tablePrefix;

    public function __construct(BusinessIntelligenceService $businessIntelligence, string $tablePrefix)
    {
        $this->businessIntelligence = $businessIntelligence;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Handles analytics requests.
     * 
     * @param array $request Analytics request data
     * @return array Response data
     */
    public function handleAnalyticsRequest(array $request): array
    {
        try {
            $action = $request['action'] ?? 'get_sales_analytics';
            $filters = $request['filters'] ?? [];
            $reportData = $request['report_data'] ?? [];

            // Validate request
            $this->validateRequest($request);

            // Handle different actions
            switch ($action) {
                case 'get_sales_analytics':
                    return $this->handleSalesAnalytics($filters);
                    
                case 'get_customer_analytics':
                    return $this->handleCustomerAnalytics($filters);
                    
                case 'get_inventory_analytics':
                    return $this->handleInventoryAnalytics($filters);
                    
                case 'get_financial_analytics':
                    return $this->handleFinancialAnalytics($filters);
                    
                case 'get_performance_metrics':
                    return $this->handlePerformanceMetrics($filters);
                    
                case 'generate_custom_report':
                    return $this->handleCustomReport($reportData);
                    
                default:
                    throw new \Exception("Unsupported action: {$action}");
            }
            
        } catch (\Exception $e) {
            return $this->formatErrorResponse($e);
        }
    }

    /**
     * Handles sales analytics requests.
     * 
     * @param array $filters Filter parameters
     * @return array Response data
     */
    private function handleSalesAnalytics(array $filters): array
    {
        $analytics = $this->businessIntelligence->getSalesAnalytics($filters);
        
        return [
            'success' => true,
            'data' => $analytics,
            'message' => 'Sales analytics retrieved successfully'
        ];
    }

    /**
     * Handles customer analytics requests.
     * 
     * @param array $filters Filter parameters
     * @return array Response data
     */
    private function handleCustomerAnalytics(array $filters): array
    {
        $analytics = $this->businessIntelligence->getCustomerAnalytics($filters);
        
        return [
            'success' => true,
            'data' => $analytics,
            'message' => 'Customer analytics retrieved successfully'
        ];
    }

    /**
     * Handles inventory analytics requests.
     * 
     * @param array $filters Filter parameters
     * @return array Response data
     */
    private function handleInventoryAnalytics(array $filters): array
    {
        $analytics = $this->businessIntelligence->getInventoryAnalytics($filters);
        
        return [
            'success' => true,
            'data' => $analytics,
            'message' => 'Inventory analytics retrieved successfully'
        ];
    }

    /**
     * Handles financial analytics requests.
     * 
     * @param array $filters Filter parameters
     * @return array Response data
     */
    private function handleFinancialAnalytics(array $filters): array
    {
        $analytics = $this->businessIntelligence->getFinancialAnalytics($filters);
        
        return [
            'success' => true,
            'data' => $analytics,
            'message' => 'Financial analytics retrieved successfully'
        ];
    }

    /**
     * Handles performance metrics requests.
     * 
     * @param array $filters Filter parameters
     * @return array Response data
     */
    private function handlePerformanceMetrics(array $filters): array
    {
        $metrics = $this->businessIntelligence->getPerformanceMetrics($filters);
        
        return [
            'success' => true,
            'data' => $metrics,
            'message' => 'Performance metrics retrieved successfully'
        ];
    }

    /**
     * Handles custom report requests.
     * 
     * @param array $reportData Report data
     * @return array Response data
     */
    private function handleCustomReport(array $reportData): array
    {
        $report = $this->businessIntelligence->generateCustomReport($reportData);
        
        return [
            'success' => true,
            'data' => $report,
            'message' => 'Custom report generated successfully'
        ];
    }

    /**
     * Validates request data.
     * 
     * @param array $request Request data
     * @throws \Exception on validation failure
     */
    private function validateRequest(array $request): void
    {
        if (empty($request)) {
            throw new \Exception("Request data is required");
        }
        
        if (!isset($request['action'])) {
            throw new \Exception("Action is required");
        }
        
        $validActions = [
            'get_sales_analytics',
            'get_customer_analytics',
            'get_inventory_analytics',
            'get_financial_analytics',
            'get_performance_metrics',
            'generate_custom_report'
        ];
        
        if (!in_array($request['action'], $validActions)) {
            throw new \Exception("Invalid action: {$request['action']}");
        }
    }

    /**
     * Formats error response.
     * 
     * @param \Exception $e Exception
     * @return array Error response
     */
    private function formatErrorResponse(\Exception $e): array
    {
        $response = [
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => $e->getCode() ?: 500,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Add context if available
        if ($e instanceof AnalyticsException || $e instanceof ReportGenerationException) {
            $response['context'] = $e->getContext();
            $response['user_message'] = $e->getUserMessage();
        }
        
        return $response;
    }

    /**
     * Gets default filters for analytics.
     * 
     * @return array Default filters
     */
    public function getDefaultFilters(): array
    {
        $currentYear = date('Y');
        $startOfYear = $currentYear . '-01-01';
        $endOfYear = $currentYear . '-12-31';
        
        return [
            'start_date' => $startOfYear,
            'end_date' => $endOfYear,
            'location_id' => null
        ];
    }

    /**
     * Gets available analytics endpoints.
     * 
     * @return array Available endpoints
     */
    public function getAvailableEndpoints(): array
    {
        return [
            'get_sales_analytics' => [
                'description' => 'Get sales analytics data',
                'method' => 'GET',
                'parameters' => ['start_date', 'end_date', 'location_id']
            ],
            'get_customer_analytics' => [
                'description' => 'Get customer analytics data',
                'method' => 'GET',
                'parameters' => ['start_date', 'end_date', 'location_id']
            ],
            'get_inventory_analytics' => [
                'description' => 'Get inventory analytics data',
                'method' => 'GET',
                'parameters' => ['start_date', 'end_date', 'location_id']
            ],
            'get_financial_analytics' => [
                'description' => 'Get financial analytics data',
                'method' => 'GET',
                'parameters' => ['start_date', 'end_date', 'location_id']
            ],
            'get_performance_metrics' => [
                'description' => 'Get performance metrics',
                'method' => 'GET',
                'parameters' => ['start_date', 'end_date', 'location_id']
            ],
            'generate_custom_report' => [
                'description' => 'Generate custom report',
                'method' => 'POST',
                'parameters' => ['report_type', 'user_id', 'filters']
            ]
        ];
    }
}