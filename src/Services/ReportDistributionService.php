<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Report Distribution Service
 * 
 * Handles report distribution to various channels.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-09.05 - Report Distribution
 */
class ReportDistributionService
{
    private EmailService $emailService;
    private FileStorageService $fileStorage;
    private string $tablePrefix;

    public function __construct(EmailService $emailService, FileStorageService $fileStorage, string $tablePrefix)
    {
        $this->emailService = $emailService;
        $this->fileStorage = $fileStorage;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Creates a distribution.
     * 
     * @param array $distributionData Distribution data
     * @return array Created distribution
     */
    public function createDistribution(array $distributionData): array
    {
        try {
            // Validate distribution data
            $this->validateDistributionData($distributionData);
            
            // Prepare distribution data for insertion
            $distributionData['created_at'] = date('Y-m-d H:i:s');
            $distributionData['updated_at'] = date('Y-m-d H:i:s');
            $distributionData['status'] = 'pending';
            
            // Insert distribution
            $distributionId = $this->insertDistribution($distributionData);
            
            // Get created distribution
            $distribution = $this->getDistributionById($distributionId);
            
            // Queue distribution
            $this->queueDistribution($distributionId);
            
            return $distribution;
            
        } catch (\Exception $e) {
            throw new \Exception("Distribution creation failed: " . $e->getMessage());
        }
    }

    /**
     * Updates an existing distribution.
     * 
     * @param int $distributionId Distribution ID
     * @param array $distributionData Distribution data
     * @return array Updated distribution
     */
    public function updateDistribution(int $distributionId, array $distributionData): array
    {
        try {
            // Validate distribution data
            $this->validateDistributionData($distributionData);
            
            // Check if distribution exists
            $existingDistribution = $this->getDistributionById($distributionId);
            if (!$existingDistribution) {
                throw new \Exception("Distribution not found");
            }
            
            // Update distribution
            $distributionData['updated_at'] = date('Y-m-d H:i:s');
            
            $this->updateDistributionById($distributionId, $distributionData);
            
            // Get updated distribution
            $distribution = $this->getDistributionById($distributionId);
            
            return $distribution;
            
        } catch (\Exception $e) {
            throw new \Exception("Distribution update failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes a distribution.
     * 
     * @param int $distributionId Distribution ID
     * @return bool Success status
     */
    public function deleteDistribution(int $distributionId): bool
    {
        try {
            // Check if distribution exists
            $existingDistribution = $this->getDistributionById($distributionId);
            if (!$existingDistribution) {
                throw new \Exception("Distribution not found");
            }
            
            // Cancel any pending distributions
            $this->cancelDistribution($distributionId);
            
            // Delete distribution
            return $this->deleteDistributionById($distributionId);
            
        } catch (\Exception $e) {
            throw new \Exception("Distribution deletion failed: " . $e->getMessage());
        }
    }

    /**
     * Gets a distribution by ID.
     * 
     * @param int $distributionId Distribution ID
     * @return array Distribution data
     */
    public function getDistributionById(int $distributionId): ?array
    {
        $tableName = $this->getDistributionTableName();
        $sql = "SELECT * FROM {$tableName} WHERE distribution_id = {$distributionId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets distributions by filters.
     * 
     * @param array $filters Filter parameters
     * @return array Distributions
     */
    public function getDistributions(array $filters): array
    {
        $tableName = $this->getDistributionTableName();
        
        // Build query
        $conditions = ["1=1"];
        
        if (isset($filters['report_id'])) {
            $conditions[] = "report_id = {$filters['report_id']}";
        }
        
        if (isset($filters['distribution_method'])) {
            $conditions[] = "distribution_method = '{$filters['distribution_method']}'";
        }
        
        if (isset($filters['status'])) {
            $conditions[] = "status = '{$filters['status']}'";
        }
        
        if (isset($filters['created_by'])) {
            $conditions[] = "created_by = {$filters['created_by']}";
        }
        
        $sql = "SELECT * FROM {$tableName} WHERE " . implode(' AND ', $conditions) . " ORDER BY created_at DESC";
        
        $result = \db_query($sql);
        $distributions = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $distributions[] = $row;
                }
            }
        }
        
        return $distributions;
    }

    /**
     * Gets distribution history by filters.
     * 
     * @param array $filters Filter parameters
     * @return array Distribution history
     */
    public function getDistributionHistory(array $filters): array
    {
        $tableName = $this->getDistributionHistoryTableName();
        
        // Build query
        $conditions = ["1=1"];
        
        if (isset($filters['distribution_id'])) {
            $conditions[] = "distribution_id = {$filters['distribution_id']}";
        }
        
        if (isset($filters['status'])) {
            $conditions[] = "status = '{$filters['status']}'";
        }
        
        if (isset($filters['date_from'])) {
            $conditions[] = "created_at >= '{$filters['date_from']}'";
        }
        
        if (isset($filters['date_to'])) {
            $conditions[] = "created_at <= '{$filters['date_to']}'";
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
     * Executes a distribution.
     * 
     * @param int $distributionId Distribution ID
     * @return array Execution result
     */
    public function executeDistribution(int $distributionId): array
    {
        try {
            // Get distribution
            $distribution = $this->getDistributionById($distributionId);
            if (!$distribution) {
                throw new \Exception("Distribution not found");
            }
            
            // Update status to processing
            $this->updateDistributionStatus($distributionId, 'processing');
            
            // Get report data
            $reportData = $this->getReportData($distribution['report_id']);
            
            // Execute distribution based on method
            $result = $this->executeDistributionMethod($distribution, $reportData);
            
            // Update status based on result
            $status = $result['success'] ? 'completed' : 'failed';
            $this->updateDistributionStatus($distributionId, $status);
            
            // Log history
            $this->logDistributionHistory($distributionId, $status, $result['message']);
            
            return [
                'success' => $result['success'],
                'distribution_id' => $distributionId,
                'executed_at' => date('Y-m-d H:i:s'),
                'message' => $result['message'],
                'details' => $result['details'] ?? null
            ];
            
        } catch (\Exception $e) {
            // Update status to failed
            $this->updateDistributionStatus($distributionId, 'failed');
            
            // Log error
            $this->logDistributionHistory($distributionId, 'failed', $e->getMessage());
            
            throw new \Exception("Distribution execution failed: " . $e->getMessage());
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
        
        $validMethods = ['email', 'ftp', 's3', 'webhook', 'download'];
        if (!in_array($distributionData['distribution_method'], $validMethods)) {
            throw new \Exception("Invalid distribution method");
        }
        
        if (!isset($distributionData['distribution_config'])) {
            throw new \Exception("Distribution configuration is required");
        }
        
        if (!isset($distributionData['created_by'])) {
            throw new \Exception("Created by is required");
        }
    }

    /**
     * Queues a distribution for execution.
     * 
     * @param int $distributionId Distribution ID
     * @return bool Success status
     */
    private function queueDistribution(int $distributionId): bool
    {
        // Add to queue table for background processing
        $queueData = [
            'distribution_id' => $distributionId,
            'queued_at' => date('Y-m-d H:i:s'),
            'status' => 'pending',
            'priority' => 'normal'
        ];
        
        return $this->insertIntoQueue($queueData);
    }

    /**
     * Cancels a distribution.
     * 
     * @param int $distributionId Distribution ID
     * @return bool Success status
     */
    private function cancelDistribution(int $distributionId): bool
    {
        $tableName = $this->getDistributionTableName();
        $sql = "UPDATE {$tableName} SET status = 'cancelled', updated_at = NOW() WHERE distribution_id = {$distributionId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Updates distribution status.
     * 
     * @param int $distributionId Distribution ID
     * @param string $status Status
     * @return bool Success status
     */
    private function updateDistributionStatus(int $distributionId, string $status): bool
    {
        $tableName = $this->getDistributionTableName();
        $sql = "UPDATE {$tableName} SET status = '{$status}', updated_at = NOW() WHERE distribution_id = {$distributionId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Logs distribution history.
     * 
     * @param int $distributionId Distribution ID
     * @param string $status Status
     * @param string $message Message
     * @return bool Success status
     */
    private function logDistributionHistory(int $distributionId, string $status, string $message): bool
    {
        $tableName = $this->getDistributionHistoryTableName();
        $sql = "INSERT INTO {$tableName} (distribution_id, status, message, created_at) 
                VALUES ({$distributionId}, '{$status}', '" . \db_escape($message) . "', NOW())";
        
        return \db_query($sql) !== false;
    }

    /**
     * Executes distribution method.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @return array Execution result
     */
    private function executeDistributionMethod(array $distribution, array $reportData): array
    {
        $method = $distribution['distribution_method'];
        $config = json_decode($distribution['distribution_config'], true);
        
        switch ($method) {
            case 'email':
                return $this->executeEmailDistribution($distribution, $reportData, $config);
                
            case 'ftp':
                return $this->executeFtpDistribution($distribution, $reportData, $config);
                
            case 's3':
                return $this->executeS3Distribution($distribution, $reportData, $config);
                
            case 'webhook':
                return $this->executeWebhookDistribution($distribution, $reportData, $config);
                
            case 'download':
                return $this->executeDownloadDistribution($distribution, $reportData, $config);
                
            default:
                throw new \Exception("Unsupported distribution method: {$method}");
        }
    }

    /**
     * Executes email distribution.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @param array $config Configuration
     * @return array Execution result
     */
    private function executeEmailDistribution(array $distribution, array $reportData, array $config): array
    {
        // Generate report file
        $format = $config['format'] ?? 'pdf';
        $fileContent = $this->generateReportFile($reportData, $format);
        $fileName = "report_{$distribution['report_id']}_" . date('Y-m-d_H-i-s') . ".{$format}";
        
        // Upload to file storage
        $fileUrl = $this->fileStorage->uploadFile($fileName, $fileContent);
        
        // Send email
        $recipients = json_decode($distribution['recipients'], true);
        $subject = $config['subject'] ?? "Report Distribution - {$distribution['report_id']}";
        $body = $config['body'] ?? "Please find the attached report.";
        
        $emailResult = $this->emailService->sendEmail([
            'to' => $recipients,
            'subject' => $subject,
            'body' => $body,
            'attachments' => [
                ['name' => $fileName, 'content' => $fileContent, 'contentType' => 'application/pdf']
            ]
        ]);
        
        return [
            'success' => $emailResult['success'],
            'message' => $emailResult['message'],
            'details' => [
                'file_url' => $fileUrl,
                'recipients' => $recipients,
                'email_result' => $emailResult
            ]
        ];
    }

    /**
     * Executes FTP distribution.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @param array $config Configuration
     * @return array Execution result
     */
    private function executeFtpDistribution(array $distribution, array $reportData, array $config): array
    {
        // Generate report file
        $format = $config['format'] ?? 'pdf';
        $fileContent = $this->generateReportFile($reportData, $format);
        $fileName = "report_{$distribution['report_id']}_" . date('Y-m-d_H-i-s') . ".{$format}";
        
        // Connect to FTP
        $ftpConnection = ftp_connect($config['host'], $config['port'] ?? 21);
        if (!$ftpConnection) {
            throw new \Exception("Failed to connect to FTP server");
        }
        
        // Login to FTP
        if (!ftp_login($ftpConnection, $config['username'], $config['password'])) {
            ftp_close($ftpConnection);
            throw new \Exception("FTP login failed");
        }
        
        // Upload file
        $remotePath = $config['remote_path'] ?? '/';
        $uploadResult = ftp_put($ftpConnection, $remotePath . $fileName, $fileContent, FTP_BINARY);
        
        // Close connection
        ftp_close($ftpConnection);
        
        return [
            'success' => $uploadResult,
            'message' => $uploadResult ? "File uploaded successfully to FTP" : "FTP upload failed",
            'details' => [
                'file_name' => $fileName,
                'remote_path' => $remotePath,
                'host' => $config['host']
            ]
        ];
    }

    /**
     * Executes S3 distribution.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @param array $config Configuration
     * @return array Execution result
     */
    private function executeS3Distribution(array $distribution, array $reportData, array $config): array
    {
        // Generate report file
        $format = $config['format'] ?? 'pdf';
        $fileContent = $this->generateReportFile($reportData, $format);
        $fileName = "report_{$distribution['report_id']}_" . date('Y-m-d_H-i-s') . ".{$format}";
        
        // Upload to S3
        $s3Result = $this->fileStorage->uploadToS3($fileName, $fileContent, $config['bucket'], $config['region']);
        
        return [
            'success' => $s3Result['success'],
            'message' => $s3Result['message'],
            'details' => [
                'file_name' => $fileName,
                'bucket' => $config['bucket'],
                'region' => $config['region'],
                'file_url' => $s3Result['file_url'] ?? null
            ]
        ];
    }

    /**
     * Executes webhook distribution.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @param array $config Configuration
     * @return array Execution result
     */
    private function executeWebhookDistribution(array $distribution, array $reportData, array $config): array
    {
        $webhookUrl = $config['webhook_url'];
        $webhookData = [
            'report_id' => $distribution['report_id'],
            'distribution_id' => $distribution['distribution_id'],
            'data' => $reportData,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Send webhook
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($config['api_key'] ?? '')
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $success = $httpCode >= 200 && $httpCode < 300;
        
        return [
            'success' => $success,
            'message' => $success ? "Webhook sent successfully" : "Webhook failed: {$error}",
            'details' => [
                'webhook_url' => $webhookUrl,
                'http_code' => $httpCode,
                'response' => $response
            ]
        ];
    }

    /**
     * Executes download distribution.
     * 
     * @param array $distribution Distribution data
     * @param array $reportData Report data
     * @param array $config Configuration
     * @return array Execution result
     */
    private function executeDownloadDistribution(array $distribution, array $reportData, array $config): array
    {
        // Generate report file
        $format = $config['format'] ?? 'pdf';
        $fileContent = $this->generateReportFile($reportData, $format);
        $fileName = "report_{$distribution['report_id']}_" . date('Y-m-d_H-i-s') . ".{$format}";
        
        // Store file for download
        $fileUrl = $this->fileStorage->uploadFile($fileName, $fileContent);
        
        return [
            'success' => true,
            'message' => "Report file generated and ready for download",
            'details' => [
                'file_name' => $fileName,
                'file_url' => $fileUrl,
                'download_url' => $fileUrl . '?download=1'
            ]
        ];
    }

    /**
     * Generates report file content.
     * 
     * @param array $reportData Report data
     * @param string $format File format
     * @return string File content
     */
    private function generateReportFile(array $reportData, string $format): string
    {
        switch ($format) {
            case 'pdf':
                return $this->generatePdfReport($reportData);
                
            case 'excel':
                return $this->generateExcelReport($reportData);
                
            case 'csv':
                return $this->generateCsvReport($reportData);
                
            case 'json':
                return json_encode($reportData, JSON_PRETTY_PRINT);
                
            default:
                throw new \Exception("Unsupported report format: {$format}");
        }
    }

    /**
     * Generates PDF report.
     * 
     * @param array $reportData Report data
     * @return string PDF content
     */
    private function generatePdfReport(array $reportData): string
    {
        // Simple PDF generation using HTML
        $html = $this->generateHtmlReport($reportData);
        
        // For now, return HTML (in real implementation, use a PDF library)
        return $html;
    }

    /**
     * Generates HTML report.
     * 
     * @param array $reportData Report data
     * @return string HTML content
     */
    private function generateHtmlReport(array $reportData): string
    {
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>Report - ' . ($reportData['metadata']['report_type'] ?? 'Unknown') . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .header { text-align: center; margin-bottom: 40px; }
                .section { margin-bottom: 30px; }
                .chart { border: 1px solid #ccc; padding: 20px; margin: 20px 0; }
                .metric { display: inline-block; margin: 10px; padding: 20px; background: #f5f5f5; border-radius: 5px; }
            </style>
        </head>
        <body>';
        
        $html .= '<div class="header">
            <h1>' . ($reportData['metadata']['report_type'] ?? 'Report') . '</h1>
            <p>Generated on: ' . ($reportData['metadata']['generated_at'] ?? date('Y-m-d H:i:s')) . '</p>
        </div>';
        
        // Add key metrics
        if (isset($reportData['summary']['key_metrics'])) {
            $html .= '<div class="section">
                <h2>Key Metrics</h2>';
            foreach ($reportData['summary']['key_metrics'] as $metric => $value) {
                $html .= '<div class="metric">' . htmlspecialchars($metric) . ': ' . htmlspecialchars($value) . '</div>';
            }
            $html .= '</div>';
        }
        
        // Add visualizations
        if (isset($reportData['visualizations'])) {
            $html .= '<div class="section">
                <h2>Visualizations</h2>';
            foreach ($reportData['visualizations'] as $type => $chart) {
                $html .= '<div class="chart">
                    <h3>' . htmlspecialchars($chart['title'] ?? $type) . '</h3>
                    <p>Chart type: ' . htmlspecialchars($type) . '</p>
                </div>';
            }
            $html .= '</div>';
        }
        
        // Add executive summary
        if (isset($reportData['summary']['executive_summary'])) {
            $html .= '<div class="section">
                <h2>Executive Summary</h2>';
            foreach ($reportData['summary']['executive_summary'] as $section => $content) {
                $html .= '<h3>' . htmlspecialchars(ucfirst($section)) . '</h3>';
                if (is_array($content)) {
                    foreach ($content as $item) {
                        $html .= '<p>' . htmlspecialchars($item) . '</p>';
                    }
                } else {
                    $html .= '<p>' . htmlspecialchars($content) . '</p>';
                }
            }
            $html .= '</div>';
        }
        
        $html .= '</body>
        </html>';
        
        return $html;
    }

    /**
     * Generates Excel report.
     * 
     * @param array $reportData Report data
     * @return string Excel content (CSV format for now)
     */
    private function generateExcelReport(array $reportData): string
    {
        // Generate CSV format for Excel compatibility
        $csv = [];
        
        // Add header
        $csv[] = ['Report Type', 'Generated At', 'User ID'];
        $csv[] = [
            $reportData['metadata']['report_type'] ?? '',
            $reportData['metadata']['generated_at'] ?? '',
            $reportData['metadata']['generated_by'] ?? ''
        ];
        
        // Add summary data
        if (isset($reportData['summary'])) {
            $csv[] = [];
            $csv[] = ['Summary Data'];
            foreach ($reportData['summary'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $csv[] = [$key . '_' . $subKey, $subValue];
                    }
                } else {
                    $csv[] = [$key, $value];
                }
            }
        }
        
        // Convert to CSV
        $csvContent = '';
        foreach ($csv as $row) {
            $csvContent .= implode(',', array_map(function($cell) {
                return '"' . str_replace('"', '""', $cell) . '"';
            }, $row)) . "\n";
        }
        
        return $csvContent;
    }

    /**
     * Generates CSV report.
     * 
     * @param array $reportData Report data
     * @return string CSV content
     */
    private function generateCsvReport(array $reportData): string
    {
        // Generate CSV content
        $csv = [];
        
        // Add header
        $csv[] = ['Metric', 'Value'];
        
        // Add summary data
        if (isset($reportData['summary'])) {
            foreach ($reportData['summary'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $csv[] = [$key . '_' . $subKey, $subValue];
                    }
                } else {
                    $csv[] = [$key, $value];
                }
            }
        }
        
        // Convert to CSV
        $csvContent = '';
        foreach ($csv as $row) {
            $csvContent .= implode(',', array_map(function($cell) {
                return '"' . str_replace('"', '""', $cell) . '"';
            }, $row)) . "\n";
        }
        
        return $csvContent;
    }

    /**
     * Gets report data by ID.
     * 
     * @param int $reportId Report ID
     * @return array Report data
     */
    private function getReportData(int $reportId): array
    {
        // This would typically fetch from a reports database or storage
        // For now, return mock data
        return [
            'metadata' => [
                'report_type' => 'Sales Report',
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => 'system'
            ],
            'summary' => [
                'key_metrics' => [
                    'total_sales' => 100000,
                    'total_orders' => 500,
                    'average_order_value' => 200
                ],
                'executive_summary' => [
                    'highlights' => ['Sales target exceeded by 15%', 'Customer satisfaction improved'],
                    'recommendations' => ['Increase marketing spend', 'Expand product offerings']
                ]
            ],
            'visualizations' => [
                'sales_trend' => [
                    'title' => 'Sales Trend',
                    'type' => 'line'
                ]
            ]
        ];
    }

    /**
     * Inserts into distribution queue.
     * 
     * @param array $queueData Queue data
     * @return bool Success status
     */
    private function insertIntoQueue(array $queueData): bool
    {
        $tableName = $this->getDistributionQueueTableName();
        
        $fields = [];
        $values = [];
        foreach ($queueData as $key => $value) {
            $fields[] = $key;
            $values[] = is_numeric($value) ? $value : "'" . \db_escape($value) . "'";
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";
        
        return \db_query($sql) !== false;
    }

    /**
     * Inserts distribution into database.
     * 
     * @param array $distributionData Distribution data
     * @return int Distribution ID
     */
    private function insertDistribution(array $distributionData): int
    {
        $tableName = $this->getDistributionTableName();
        
        $fields = [];
        $values = [];
        foreach ($distributionData as $key => $value) {
            $fields[] = $key;
            $values[] = is_numeric($value) ? $value : "'" . \db_escape($value) . "'";
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";
        
        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Updates distribution in database.
     * 
     * @param int $distributionId Distribution ID
     * @param array $distributionData Distribution data
     * @return bool Success status
     */
    private function updateDistributionById(int $distributionId, array $distributionData): bool
    {
        $tableName = $this->getDistributionTableName();
        
        $updates = [];
        foreach ($distributionData as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE distribution_id = {$distributionId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes distribution from database.
     * 
     * @param int $distributionId Distribution ID
     * @return bool Success status
     */
    private function deleteDistributionById(int $distributionId): bool
    {
        $tableName = $this->getDistributionTableName();
        $sql = "DELETE FROM {$tableName} WHERE distribution_id = {$distributionId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets distribution table name.
     * 
     * @return string Table name
     */
    private function getDistributionTableName(): string
    {
        return $this->tablePrefix . 'report_distributions';
    }

    /**
     * Gets distribution history table name.
     * 
     * @return string Table name
     */
    private function getDistributionHistoryTableName(): string
    {
        return $this->tablePrefix . 'report_distribution_history';
    }

    /**
     * Gets distribution queue table name.
     * 
     * @return string Table name
     */
    private function getDistributionQueueTableName(): string
    {
        return $this->tablePrefix . 'distribution_queue';
    }

    /**
     * Ensures the tables exist.
     */
    public function ensureTablesExist(): void
    {
        $distributionTable = $this->getDistributionTableName();
        $historyTable = $this->getDistributionHistoryTableName();
        $queueTable = $this->getDistributionQueueTableName();
        
        // Create distribution table
        $checkSql = "SHOW TABLES LIKE '{$distributionTable}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            $createSql = "CREATE TABLE {$distributionTable} (
                distribution_id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                distribution_method VARCHAR(50) NOT NULL,
                recipients JSON NOT NULL,
                distribution_config JSON NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_report_id (report_id),
                INDEX idx_distribution_method (distribution_method),
                INDEX idx_status (status),
                INDEX idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
        
        // Create history table
        $checkSql = "SHOW TABLES LIKE '{$historyTable}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            $createSql = "CREATE TABLE {$historyTable} (
                history_id INT AUTO_INCREMENT PRIMARY KEY,
                distribution_id INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                message TEXT,
                created_at DATETIME NOT NULL,
                INDEX idx_distribution_id (distribution_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
        
        // Create queue table
        $checkSql = "SHOW TABLES LIKE '{$queueTable}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            $createSql = "CREATE TABLE {$queueTable} (
                queue_id INT AUTO_INCREMENT PRIMARY KEY,
                distribution_id INT NOT NULL,
                queued_at DATETIME NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                INDEX idx_distribution_id (distribution_id),
                INDEX idx_status (status),
                INDEX idx_queued_at (queued_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }
}