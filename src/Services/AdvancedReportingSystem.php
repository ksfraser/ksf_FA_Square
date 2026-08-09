<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Advanced Reporting System
 * 
 * Handles advanced reporting with templates, scheduling, and distribution.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-09.02 - Advanced Reporting
 */
class AdvancedReportingSystem
{
    private BusinessIntelligenceService $businessIntelligence;
    private ReportTemplateService $templateService;
    private ReportSchedulerService $schedulerService;
    private ReportDistributionService $distributionService;
    private string $tablePrefix;

    public function __construct(
        BusinessIntelligenceService $businessIntelligence,
        ReportTemplateService $templateService,
        ReportSchedulerService $schedulerService,
        ReportDistributionService $distributionService,
        string $tablePrefix
    ) {
        $this->businessIntelligence = $businessIntelligence;
        $this->templateService = $templateService;
        $this->schedulerService = $schedulerService;
        $this->distributionService = $distributionService;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Generates advanced report with custom formatting.
     * 
     * @param array $reportData Report data
     * @param array $formattingOptions Formatting options
     * @return array Report results
     */
    public function generateAdvancedReport(array $reportData, array $formattingOptions): array
    {
        try {
            // Validate report data
            $this->validateReportData($reportData);
            
            // Generate base report
            $baseReport = $this->businessIntelligence->generateCustomReport($reportData);
            
            // Apply formatting
            $formattedReport = $this->applyFormatting($baseReport, $formattingOptions);
            
            // Generate visualizations
            $visualizations = $this->generateVisualizations($formattedReport);
            
            // Generate executive summary
            $executiveSummary = $this->generateExecutiveSummary($formattedReport);
            
            // Generate recommendations
            $recommendations = $this->generateRecommendations($formattedReport);
            
            return [
                'report_data' => $formattedReport,
                'visualizations' => $visualizations,
                'executive_summary' => $executiveSummary,
                'recommendations' => $recommendations,
                'metadata' => $this->generateReportMetadata($reportData, $formattingOptions),
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Advanced report generation failed: " . $e->getMessage());
        }
    }

    /**
     * Creates a report template.
     * 
     * @param array $templateData Template data
     * @return array Template results
     */
    public function createReportTemplate(array $templateData): array
    {
        try {
            // Validate template data
            $this->validateTemplateData($templateData);
            
            // Create template
            $template = $this->templateService->createTemplate($templateData);
            
            return [
                'success' => true,
                'template' => $template,
                'message' => 'Report template created successfully'
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Report template creation failed: " . $e->getMessage());
        }
    }

    /**
     * Schedules a report for automatic generation.
     * 
     * @param array $scheduleData Schedule data
     * @return array Schedule results
     */
    public function scheduleReport(array $scheduleData): array
    {
        try {
            // Validate schedule data
            $this->validateScheduleData($scheduleData);
            
            // Create schedule
            $schedule = $this->schedulerService->createSchedule($scheduleData);
            
            return [
                'success' => true,
                'schedule' => $schedule,
                'message' => 'Report scheduled successfully'
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Report scheduling failed: " . $e->getMessage());
        }
    }

    /**
     * Distributes a report to recipients.
     * 
     * @param array $distributionData Distribution data
     * @return array Distribution results
     */
    public function distributeReport(array $distributionData): array
    {
        try {
            // Validate distribution data
            $this->validateDistributionData($distributionData);
            
            // Create distribution
            $distribution = $this->distributionService->createDistribution($distributionData);
            
            return [
                'success' => true,
                'distribution' => $distribution,
                'message' => 'Report distributed successfully'
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Report distribution failed: " . $e->getMessage());
        }
    }

    /**
     * Gets report history.
     * 
     * @param array $filters Filter parameters
     * @return array Report history
     */
    public function getReportHistory(array $filters): array
    {
        try {
            // Get report history from database
            $history = $this->getReportHistoryFromDatabase($filters);
            
            return [
                'success' => true,
                'history' => $history,
                'total_count' => count($history),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to get report history: " . $e->getMessage());
        }
    }

    /**
     * Gets report templates.
     * 
     * @param array $filters Filter parameters
     * @return array Report templates
     */
    public function getReportTemplates(array $filters): array
    {
        try {
            // Get templates
            $templates = $this->templateService->getTemplates($filters);
            
            return [
                'success' => true,
                'templates' => $templates,
                'total_count' => count($templates),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to get report templates: " . $e->getMessage());
        }
    }

    /**
     * Gets scheduled reports.
     * 
     * @param array $filters Filter parameters
     * @return array Scheduled reports
     */
    public function getScheduledReports(array $filters): array
    {
        try {
            // Get scheduled reports
            $schedules = $this->schedulerService->getSchedules($filters);
            
            return [
                'success' => true,
                'schedules' => $schedules,
                'total_count' => count($schedules),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to get scheduled reports: " . $e->getMessage());
        }
    }

    /**
     * Gets report distribution history.
     * 
     * @param array $filters Filter parameters
     * @return array Distribution history
     */
    public function getDistributionHistory(array $filters): array
    {
        try {
            // Get distribution history
            $history = $this->distributionService->getDistributionHistory($filters);
            
            return [
                'success' => true,
                'history' => $history,
                'total_count' => count($history),
                'filters' => $filters
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to get distribution history: " . $e->getMessage());
        }
    }

    /**
     * Validates report data.
     * 
     * @param array $reportData Report data
     * @throws \Exception on validation failure
     */
    private function validateReportData(array $reportData): void
    {
        if (empty($reportData)) {
            throw new \Exception("Report data is required");
        }
        
        if (!isset($reportData['report_type'])) {
            throw new \Exception("Report type is required");
        }
        
        if (!isset($reportData['filters'])) {
            throw new \Exception("Report filters are required");
        }
        
        $validTypes = ['sales', 'customer', 'inventory', 'financial', 'performance', 'predictive'];
        if (!in_array($reportData['report_type'], $validTypes)) {
            throw new \Exception("Invalid report type");
        }
    }

    /**
     * Validates template data.
     * 
     * @param array $templateData Template data
     * @throws \Exception on validation failure
     */
    private function validateTemplateData(array $templateData): void
    {
        if (empty($templateData)) {
            throw new \Exception("Template data is required");
        }
        
        if (!isset($templateData['template_name'])) {
            throw new \Exception("Template name is required");
        }
        
        if (!isset($templateData['report_type'])) {
            throw new \Exception("Report type is required");
        }
        
        if (!isset($templateData['formatting_options'])) {
            throw new \Exception("Formatting options are required");
        }
        
        if (!isset($templateData['access_permissions'])) {
            throw new \Exception("Access permissions are required");
        }
    }

    /**
     * Validates schedule data.
     * 
     * @param array $scheduleData Schedule data
     * @throws \Exception on validation failure
     */
    private function validateScheduleData(array $scheduleData): void
    {
        if (empty($scheduleData)) {
            throw new \Exception("Schedule data is required");
        }
        
        if (!isset($scheduleData['template_id'])) {
            throw new \Exception("Template ID is required");
        }
        
        if (!isset($scheduleData['schedule_type'])) {
            throw new \Exception("Schedule type is required");
        }
        
        if (!isset($scheduleData['schedule_config'])) {
            throw new \Exception("Schedule configuration is required");
        }
        
        if (!isset($scheduleData['distribution_config'])) {
            throw new \Exception("Distribution configuration is required");
        }
        
        $validTypes = ['daily', 'weekly', 'monthly', 'quarterly', 'custom'];
        if (!in_array($scheduleData['schedule_type'], $validTypes)) {
            throw new \Exception("Invalid schedule type");
        }
    }

    /**
     * Validates distribution data.
     * 
     * @param array $distributionData Distribution data
     * @throws \Exception on validation failure
     */
    private function validateDistributionData(array $distributionData): void
    {
        if (empty($distributionData)) {
            throw new \Exception("Distribution data is required");
        }
        
        if (!isset($distributionData['report_id'])) {
            throw new \Exception("Report ID is required");
        }
        
        if (!isset($distributionData['recipients'])) {
            throw new \Exception("Recipients are required");
        }
        
        if (!isset($distributionData['distribution_method'])) {
            throw new \Exception("Distribution method is required");
        }
        
        if (!isset($distributionData['distribution_config'])) {
            throw new \Exception("Distribution configuration is required");
        }
        
        $validMethods = ['email', 'ftp', 's3', 'webhook', 'download'];
        if (!in_array($distributionData['distribution_method'], $validMethods)) {
            throw new \Exception("Invalid distribution method");
        }
    }

    /**
     * Applies formatting to report data.
     * 
     * @param array $reportData Report data
     * @param array $formattingOptions Formatting options
     * @return array Formatted report data
     */
    private function applyFormatting(array $reportData, array $formattingOptions): array
    {
        $formatted = $reportData;
        
        // Apply theme
        if (isset($formattingOptions['theme'])) {
            $formatted['theme'] = $formattingOptions['theme'];
        }
        
        // Apply currency formatting
        if (isset($formattingOptions['currency_format'])) {
            $formatted['currency_format'] = $formattingOptions['currency_format'];
            $formatted['data'] = $this->formatCurrencyData($formatted['data'], $formattingOptions['currency_format']);
        }
        
        // Apply date formatting
        if (isset($formattingOptions['date_format'])) {
            $formatted['date_format'] = $formattingOptions['date_format'];
            $formatted['data'] = $this->formatDateData($formatted['data'], $formattingOptions['date_format']);
        }
        
        // Apply number formatting
        if (isset($formattingOptions['number_format'])) {
            $formatted['number_format'] = $formattingOptions['number_format'];
            $formatted['data'] = $this->formatNumberData($formatted['data'], $formattingOptions['number_format']);
        }
        
        // Apply custom styling
        if (isset($formattingOptions['custom_styling'])) {
            $formatted['custom_styling'] = $formattingOptions['custom_styling'];
        }
        
        // Apply layout options
        if (isset($formattingOptions['layout_options'])) {
            $formatted['layout_options'] = $formattingOptions['layout_options'];
        }
        
        return $formatted;
    }

    /**
     * Generates visualizations for report data.
     * 
     * @param array $reportData Report data
     * @return array Visualization data
     */
    private function generateVisualizations(array $reportData): array
    {
        $visualizations = [];
        
        // Generate charts based on data type
        if (isset($reportData['data']['summary'])) {
            $visualizations['summary_chart'] = $this->generateSummaryChart($reportData['data']['summary']);
        }
        
        if (isset($reportData['data']['trends'])) {
            $visualizations['trend_chart'] = $this->generateTrendChart($reportData['data']['trends']);
        }
        
        if (isset($reportData['data']['by_category'])) {
            $visualizations['category_chart'] = $this->generateCategoryChart($reportData['data']['by_category']);
        }
        
        if (isset($reportData['data']['by_location'])) {
            $visualizations['location_chart'] = $this->generateLocationChart($reportData['data']['by_location']);
        }
        
        if (isset($reportData['data']['by_payment_method'])) {
            $visualizations['payment_chart'] = $this->generatePaymentChart($reportData['data']['by_payment_method']);
        }
        
        return $visualizations;
    }

    /**
     * Generates executive summary.
     * 
     * @param array $reportData Report data
     * @return array Executive summary
     */
    private function generateExecutiveSummary(array $reportData): array
    {
        $summary = [
            'key_metrics' => [],
            'insights' => [],
            'recommendations' => [],
            'highlights' => []
        ];
        
        // Extract key metrics
        if (isset($reportData['data']['summary'])) {
            $summary['key_metrics'] = $this->extractKeyMetrics($reportData['data']['summary']);
        }
        
        // Generate insights
        $summary['insights'] = $this->generateInsights($reportData['data']);
        
        // Generate recommendations
        $summary['recommendations'] = $this->generateRecommendations($reportData['data']);
        
        // Generate highlights
        $summary['highlights'] = $this->generateHighlights($reportData['data']);
        
        return $summary;
    }

    /**
     * Generates recommendations based on report data.
     * 
     * @param array $reportData Report data
     * @return array Recommendations
     */
    private function generateRecommendations(array $reportData): array
    {
        $recommendations = [];
        
        // Generate sales recommendations
        if (isset($reportData['data']['sales_performance'])) {
            $recommendations[] = $this->generateSalesRecommendations($reportData['data']['sales_performance']);
        }
        
        // Generate customer recommendations
        if (isset($reportData['data']['customer_satisfaction'])) {
            $recommendations[] = $this->generateCustomerRecommendations($reportData['data']['customer_satisfaction']);
        }
        
        // Generate inventory recommendations
        if (isset($reportData['data']['inventory_summary'])) {
            $recommendations[] = $this->generateInventoryRecommendations($reportData['data']['inventory_summary']);
        }
        
        // Generate financial recommendations
        if (isset($reportData['data']['financial_health'])) {
            $recommendations[] = $this->generateFinancialRecommendations($reportData['data']['financial_health']);
        }
        
        return $recommendations;
    }

    /**
     * Generates report metadata.
     * 
     * @param array $reportData Report data
     * @param array $formattingOptions Formatting options
     * @return array Report metadata
     */
    private function generateReportMetadata(array $reportData, array $formattingOptions): array
    {
        return [
            'report_type' => $reportData['report_type'],
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $reportData['user_id'] ?? 'system',
            'filters' => $reportData['filters'],
            'formatting_options' => $formattingOptions,
            'data_sources' => $this->getDataSources(),
            'version' => '1.0',
            'access_level' => 'internal'
        ];
    }

    /**
     * Gets report history from database.
     * 
     * @param array $filters Filter parameters
     * @return array Report history
     */
    private function getReportHistoryFromDatabase(array $filters): array
    {
        $tableName = $this->getReportHistoryTableName();
        
        // Build query
        $conditions = ["1=1"];
        
        if (isset($filters['start_date'])) {
            $conditions[] = "created_at >= '{$filters['start_date']}'";
        }
        
        if (isset($filters['end_date'])) {
            $conditions[] = "created_at <= '{$filters['end_date']}'";
        }
        
        if (isset($filters['report_type'])) {
            $conditions[] = "report_type = '{$filters['report_type']}'";
        }
        
        if (isset($filters['user_id'])) {
            $conditions[] = "user_id = {$filters['user_id']}";
        }
        
        $sql = "SELECT * FROM {$tableName} WHERE " . implode(' AND ', $conditions) . " ORDER BY created_at DESC";
        
        $result = \db_query($sql);
        $history = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $history[] = $row;
                }
            }
        }
        
        return $history;
    }

    /**
     * Gets data sources for report.
     * 
     * @return array Data sources
     */
    private function getDataSources(): array
    {
        return [
            'sales_analytics' => 'Square Sales API',
            'customer_analytics' => 'Square Customer API',
            'inventory_analytics' => 'Square Inventory API',
            'financial_analytics' => 'Square Financial API',
            'local_database' => 'FrontAccounting Database'
        ];
    }

    /**
     * Formats currency data.
     * 
     * @param array $data Report data
     * @param array $format Currency format
     * @return array Formatted data
     */
    private function formatCurrencyData(array $data, array $format): array
    {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $this->formatCurrencyData($value, $format);
            } elseif (is_numeric($value)) {
                $formatted[$key] = number_format($value, $format['decimals'], $format['decimal_separator'], $format['thousands_separator']);
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }

    /**
     * Formats date data.
     * 
     * @param array $data Report data
     * @param string $format Date format
     * @return array Formatted data
     */
    private function formatDateData(array $data, string $format): array
    {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $this->formatDateData($value, $format);
            } elseif (strtotime($value)) {
                $formatted[$key] = date($format, strtotime($value));
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }

    /**
     * Formats number data.
     * 
     * @param array $data Report data
     * @param array $format Number format
     * @return array Formatted data
     */
    private function formatNumberData(array $data, array $format): array
    {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $formatted[$key] = $this->formatNumberData($value, $format);
            } elseif (is_numeric($value)) {
                $formatted[$key] = number_format($value, $format['decimals'], $format['decimal_separator'], $format['thousands_separator']);
            } else {
                $formatted[$key] = $value;
            }
        }
        
        return $formatted;
    }

    /**
     * Generates summary chart data.
     * 
     * @param array $summary Summary data
     * @return array Chart data
     */
    private function generateSummaryChart(array $summary): array
    {
        return [
            'type' => 'bar',
            'title' => 'Summary Overview',
            'data' => [
                'labels' => ['Total Transactions', 'Total Revenue', 'Average Transaction'],
                'values' => [
                    $summary['total_transactions'] ?? 0,
                    $summary['total_amount'] ?? 0,
                    $summary['average_transaction'] ?? 0
                ]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => ['title', 'legend']
            ]
        ];
    }

    /**
     * Generates trend chart data.
     * 
     * @param array $trends Trend data
     * @return array Chart data
     */
    private function generateTrendChart(array $trends): array
    {
        return [
            'type' => 'line',
            'title' => 'Sales Trends',
            'data' => [
                'labels' => array_column($trends, 'date'),
                'datasets' => [
                    [
                        'label' => 'Revenue',
                        'data' => array_column($trends, 'total_amount'),
                        'borderColor' => '#3b82f6',
                        'fill' => false
                    ]
                ]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => ['title', 'legend']
            ]
        ];
    }

    /**
     * Generates category chart data.
     * 
     * @param array $categories Category data
     * @return array Chart data
     */
    private function generateCategoryChart(array $categories): array
    {
        return [
            'type' => 'doughnut',
            'title' => 'Sales by Category',
            'data' => [
                'labels' => array_column($categories, 'category'),
                'datasets' => [
                    [
                        'data' => array_column($categories, 'total_amount'),
                        'backgroundColor' => ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6']
                    ]
                ]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => ['title', 'legend']
            ]
        ];
    }

    /**
     * Generates location chart data.
     * 
     * @param array $locations Location data
     * @return array Chart data
     */
    private function generateLocationChart(array $locations): array
    {
        return [
            'type' => 'bar',
            'title' => 'Sales by Location',
            'data' => [
                'labels' => array_column($locations, 'location_id'),
                'datasets' => [
                    [
                        'label' => 'Revenue',
                        'data' => array_column($locations, 'total_amount'),
                        'backgroundColor' => '#3b82f6'
                    ]
                ]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => ['title', 'legend']
            ]
        ];
    }

    /**
     * Generates payment chart data.
     * 
     * @param array $payments Payment data
     * @return array Chart data
     */
    private function generatePaymentChart(array $payments): array
    {
        return [
            'type' => 'pie',
            'title' => 'Payment Method Distribution',
            'data' => [
                'labels' => array_column($payments, 'payment_method'),
                'datasets' => [
                    [
                        'data' => array_column($payments, 'count'),
                        'backgroundColor' => ['#ef4444', '#3b82f6', '#10b981', '#f59e0b']
                    ]
                ]
            ],
            'options' => [
                'responsive' => true,
                'plugins' => ['title', 'legend']
            ]
        ];
    }

    /**
     * Extracts key metrics from summary data.
     * 
     * @param array $summary Summary data
     * @return array Key metrics
     */
    private function extractKeyMetrics(array $summary): array
    {
        return [
            'total_transactions' => $summary['total_transactions'] ?? 0,
            'total_revenue' => $summary['total_amount'] ?? 0,
            'average_transaction' => $summary['average_amount'] ?? 0,
            'growth_rate' => $summary['growth_rate'] ?? 0,
            'conversion_rate' => $summary['conversion_rate'] ?? 0
        ];
    }

    /**
     * Generates insights from report data.
     * 
     * @param array $data Report data
     * @return array Insights
     */
    private function generateInsights(array $data): array
    {
        $insights = [];
        
        // Sales insights
        if (isset($data['sales_performance'])) {
            $insights[] = [
                'category' => 'Sales',
                'insight' => 'Sales performance is trending ' . ($data['sales_performance']['growth_rate'] > 0 ? 'upward' : 'downward'),
                'confidence' => 'high',
                'impact' => 'positive'
            ];
        }
        
        // Customer insights
        if (isset($data['customer_satisfaction'])) {
            $insights[] = [
                'category' => 'Customer',
                'insight' => 'Customer satisfaction is ' . ($data['customer_satisfaction']['average_rating'] > 4 ? 'excellent' : 'needs improvement'),
                'confidence' => 'medium',
                'impact' => 'neutral'
            ];
        }
        
        // Financial insights
        if (isset($data['financial_health'])) {
            $insights[] = [
                'category' => 'Financial',
                'insight' => 'Financial health is ' . ($data['financial_health']['health_score'] > 80 ? 'excellent' : 'needs attention'),
                'confidence' => 'high',
                'impact' => 'positive'
            ];
        }
        
        return $insights;
    }

    /**
     * Generates highlights from report data.
     * 
     * @param array $data Report data
     * @return array Highlights
     */
    private function generateHighlights(array $data): array
    {
        $highlights = [];
        
        // Sales highlights
        if (isset($data['sales_performance'])) {
            $highlights[] = [
                'type' => 'achievement',
                'title' => 'Sales Target Met',
                'description' => 'Sales exceeded target by ' . ($data['sales_performance']['performance_score'] - 100) . '%',
                'impact' => 'positive'
            ];
        }
        
        // Warning highlights
        if (isset($data['inventory_alerts'])) {
            $highlights[] = [
                'type' => 'warning',
                'title' => 'Low Stock Alert',
                'description' => '10 items are below reorder level',
                'impact' => 'negative'
            ];
        }
        
        return $highlights;
    }

    /**
     * Generates sales recommendations.
     * 
     * @param array $salesData Sales data
     * @return array Recommendations
     */
    private function generateSalesRecommendations(array $salesData): array
    {
        return [
            'category' => 'Sales',
            'recommendations' => [
                [
                    'action' => 'Increase marketing spend',
                    'reason' => 'Sales growth is declining',
                    'priority' => 'high',
                    'expected_impact' => '15% increase in sales'
                ],
                [
                    'action' => 'Expand product offerings',
                    'reason' => 'Customer demand is increasing',
                    'priority' => 'medium',
                    'expected_impact' => '20% increase in revenue'
                ]
            ]
        ];
    }

    /**
     * Generates customer recommendations.
     * 
     * @param array $customerData Customer data
     * @return array Recommendations
     */
    private function generateCustomerRecommendations(array $customerData): array
    {
        return [
            'category' => 'Customer',
            'recommendations' => [
                [
                    'action' => 'Implement loyalty program',
                    'reason' => 'Customer retention needs improvement',
                    'priority' => 'high',
                    'expected_impact' => '25% increase in retention'
                ],
                [
                    'action' => 'Improve customer service',
                    'reason' => 'Satisfaction scores are below target',
                    'priority' => 'medium',
                    'expected_impact' => '10% increase in satisfaction'
                ]
            ]
        ];
    }

    /**
     * Generates inventory recommendations.
     * 
     * @param array $inventoryData Inventory data
     * @return array Recommendations
     */
    private function generateInventoryRecommendations(array $inventoryData): array
    {
        return [
            'category' => 'Inventory',
            'recommendations' => [
                [
                    'action' => 'Optimize reorder levels',
                    'reason' => 'Current levels are too high',
                    'priority' => 'medium',
                    'expected_impact' => '20% reduction in carrying costs'
                ],
                [
                    'action' => 'Implement safety stock',
                    'reason' => 'Stockouts are increasing',
                    'priority' => 'high',
                    'expected_impact' => '50% reduction in stockouts'
                ]
            ]
        ];
    }

    /**
     * Generates financial recommendations.
     * 
     * @param array $financialData Financial data
     * @return array Recommendations
     */
    private function generateFinancialRecommendations(array $financialData): array
    {
        return [
            'category' => 'Financial',
            'recommendations' => [
                [
                    'action' => 'Reduce expenses',
                    'reason' => 'Operating costs are too high',
                    'priority' => 'high',
                    'expected_impact' => '15% increase in profit margin'
                ],
                [
                    'action' => 'Improve cash flow',
                    'reason' => 'Cash conversion cycle is too long',
                    'priority' => 'medium',
                    'expected_impact' => '10% improvement in cash flow'
                ]
            ]
        ];
    }

    /**
     * Gets report history table name.
     * 
     * @return string Table name
     */
    private function getReportHistoryTableName(): string
    {
        return $this->tablePrefix . 'report_history';
    }
}