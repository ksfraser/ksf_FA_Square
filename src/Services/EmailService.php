<?php
declare(strict_types=1);

/**
 * Email Service
 * 
 * Handles email sending and management.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class EmailService
{
    private array $config;
    private array $emailTemplates = [];
    private array $emailLog = [];
    private const LOG_RETENTION_DAYS = 30;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name' => 'FrontAccounting',
            'log_enabled' => true,
            'log_file' => sys_get_temp_dir() . '/email.log',
            'log_retention_days' => self::LOG_RETENTION_DAYS
        ], $config);
    }

    /**
     * Sends an email.
     * 
     * @param array $emailData Email data
     * @return array Send results
     */
    public function sendEmail(array $emailData): array
    {
        try {
            // Validate email data
            $this->validateEmailData($emailData);
            
            // Prepare email
            $preparedEmail = $this->prepareEmail($emailData);
            
            // Send email
            $result = $this->sendEmailWithSmtp($preparedEmail);
            
            // Log email
            if ($this->config['log_enabled']) {
                $this->logEmail($preparedEmail, $result);
            }
            
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Email sending failed: " . $e->getMessage());
        }
    }

    /**
     * Sends multiple emails.
     * 
     * @param array $emails List of email data
     * @return array Send results
     */
    public function sendMultipleEmails(array $emails): array
    {
        $results = [];
        $successCount = 0;
        
        foreach ($emails as $index => $emailData) {
            try {
                $result = $this->sendEmail($emailData);
                $results[$index] = $result;
                
                if ($result['success']) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'email_data' => $emailData
                ];
            }
        }
        
        return [
            'total_emails' => count($emails),
            'success_count' => $successCount,
            'failure_count' => count($emails) - $successCount,
            'results' => $results
        ];
    }

    /**
     * Sends a template email.
     * 
     * @param string $templateName Template name
     * @param array $data Template data
     * @param array $emailData Email data
     * @return array Send results
     */
    public function sendTemplateEmail(string $templateName, array $data, array $emailData): array
    {
        try {
            // Load template
            $template = $this->loadTemplate($templateName);
            
            // Render template
            $renderedEmail = $this->renderTemplate($template, $data);
            
            // Merge with email data
            $mergedEmail = array_merge($renderedEmail, $emailData);
            
            // Send email
            return $this->sendEmail($mergedEmail);
        } catch (\Exception $e) {
            throw new \Exception("Template email sending failed: " . $e->getMessage());
        }
    }

    /**
     * Creates an email template.
     * 
     * @param string $templateName Template name
     * @param array $templateData Template data
     * @return array Template results
     */
    public function createTemplate(string $templateName, array $templateData): array
    {
        try {
            // Validate template data
            $this->validateTemplateData($templateData);
            
            // Create template
            $this->emailTemplates[$templateName] = $templateData;
            
            return [
                'success' => true,
                'template_name' => $templateName,
                'template_data' => $templateData,
                'message' => 'Email template created successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Template creation failed: " . $e->getMessage());
        }
    }

    /**
     * Updates an email template.
     * 
     * @param string $templateName Template name
     * @param array $templateData Template data
     * @return array Template results
     */
    public function updateTemplate(string $templateName, array $templateData): array
    {
        try {
            // Validate template data
            $this->validateTemplateData($templateData);
            
            // Check if template exists
            if (!isset($this->emailTemplates[$templateName])) {
                throw new \Exception("Template not found: {$templateName}");
            }
            
            // Update template
            $this->emailTemplates[$templateName] = $templateData;
            
            return [
                'success' => true,
                'template_name' => $templateName,
                'template_data' => $templateData,
                'message' => 'Email template updated successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Template update failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes an email template.
     * 
     * @param string $templateName Template name
     * @return array Template results
     */
    public function deleteTemplate(string $templateName): array
    {
        try {
            // Check if template exists
            if (!isset($this->emailTemplates[$templateName])) {
                throw new \Exception("Template not found: {$templateName}");
            }
            
            // Delete template
            unset($this->emailTemplates[$templateName]);
            
            return [
                'success' => true,
                'template_name' => $templateName,
                'message' => 'Email template deleted successfully'
            ];
        } catch (\Exception $e) {
            throw new \Exception("Template deletion failed: " . $e->getMessage());
        }
    }

    /**
     * Gets email templates.
     * 
     * @param array $filters Filter parameters
     * @return array Email templates
     */
    public function getTemplates(array $filters = []): array
    {
        $templates = $this->emailTemplates;
        
        // Apply filters
        if (isset($filters['name'])) {
            $templates = array_filter($templates, fn($t) => strpos($t['name'], $filters['name']) !== false);
        }
        
        return array_values($templates);
    }

    /**
     * Gets email logs.
     * 
     * @param array $filters Filter parameters
     * @return array Email logs
     */
    public function getEmailLogs(array $filters = []): array
    {
        $logs = $this->emailLog;
        
        // Apply filters
        if (isset($filters['status'])) {
            $logs = array_filter($logs, fn($l) => $l['status'] === $filters['status']);
        }
        
        if (isset($filters['date_from'])) {
            $logs = array_filter($logs, fn($l) => $l['timestamp'] >= $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $logs = array_filter($logs, fn($l) => $l['timestamp'] <= $filters['date_to']);
        }
        
        return array_values($logs);
    }

    /**
     * Validates email data.
     * 
     * @param array $emailData Email data
     * @throws \Exception on validation failure
     */
    private function validateEmailData(array $emailData): void
    {
        if (empty($emailData)) {
            throw new \Exception("Email data is required");
        }
        
        if (!isset($emailData['to'])) {
            throw new \Exception("Recipient email is required");
        }
        
        if (!isset($emailData['subject'])) {
            throw new \Exception("Subject is required");
        }
        
        if (!isset($emailData['body'])) {
            throw new \Exception("Body is required");
        }
        
        // Validate recipient email(s)
        if (is_array($emailData['to'])) {
            foreach ($emailData['to'] as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid recipient email: {$email}");
                }
            }
        } else {
            if (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid recipient email: {$emailData['to']}");
            }
        }
        
        // Validate sender email
        if (isset($emailData['from']) && !filter_var($emailData['from'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid sender email: {$emailData['from']}");
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
        
        if (!isset($templateData['subject'])) {
            throw new \Exception("Template subject is required");
        }
        
        if (!isset($templateData['body'])) {
            throw new \Exception("Template body is required");
        }
        
        if (!isset($templateData['variables'])) {
            throw new \Exception("Template variables are required");
        }
    }

    /**
     * Prepares email for sending.
     * 
     * @param array $emailData Email data
     * @return array Prepared email
     */
    private function prepareEmail(array $emailData): array
    {
        // Set default from email if not provided
        if (!isset($emailData['from'])) {
            $emailData['from'] = $this->config['from_email'];
        }
        
        // Set default from name if not provided
        if (!isset($emailData['from_name'])) {
            $emailData['from_name'] = $this->config['from_name'];
        }
        
        // Set default content type if not provided
        if (!isset($emailData['content_type'])) {
            $emailData['content_type'] = 'text/html';
        }
        
        // Prepare headers
        $emailData['headers'] = $this->prepareHeaders($emailData);
        
        return $emailData;
    }

    /**
     * Prepares email headers.
     * 
     * @param array $emailData Email data
     * @return array Email headers
     */
    private function prepareHeaders(array $emailData): array
    {
        $headers = [];
        
        // From header
        $headers[] = "From: {$emailData['from_name']} <{$emailData['from']}>";
        
        // Reply-To header
        if (isset($emailData['reply_to'])) {
            $headers[] = "Reply-To: {$emailData['reply_to']}";
        }
        
        // Content-Type header
        $headers[] = "Content-Type: {$emailData['content_type']}; charset=UTF-8";
        
        // MIME-Version header
        $headers[] = "MIME-Version: 1.0";
        
        // X-Mailer header
        $headers[] = "X-Mailer: FrontAccounting Email Service";
        
        return $headers;
    }

    /**
     * Sends email using SMTP.
     * 
     * @param array $emailData Email data
     * @return array Send results
     */
    private function sendEmailWithSmtp(array $emailData): array
    {
        try {
            // Create SMTP connection
            $smtp = $this->createSmtpConnection();
            
            // Send email
            $result = $this->smtpSend($smtp, $emailData);
            
            // Close connection
            fclose($smtp);
            
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("SMTP email sending failed: " . $e->getMessage());
        }
    }

    /**
     * Creates SMTP connection.
     * 
     * @return resource SMTP connection
     * @throws \Exception on connection failure
     */
    private function createSmtpConnection()
    {
        $smtp = fsockopen($this->config['smtp_host'], $this->config['smtp_port'], $errno, $errstr, 30);
        
        if (!$smtp) {
            throw new \Exception("SMTP connection failed: {$errstr} ({$errno})");
        }
        
        // Get greeting
        fgets($smtp, 1024);
        
        // Send EHLO
        $this->smtpCommand($smtp, "EHLO {$this->config['smtp_host']}");
        
        // Start TLS if enabled
        if ($this->config['smtp_encryption'] === 'tls') {
            $this->smtpCommand($smtp, "STARTTLS");
            stream_socket_enable_crypto($smtp, true);
        }
        
        // Authenticate if credentials are provided
        if (!empty($this->config['smtp_username']) && !empty($this->config['smtp_password'])) {
            $this->smtpCommand($smtp, "AUTH LOGIN");
            $this->smtpCommand($smtp, base64_encode($this->config['smtp_username']));
            $this->smtpCommand($smtp, base64_encode($this->config['smtp_password']));
        }
        
        return $smtp;
    }

    /**
     * Sends email using SMTP.
     * 
     * @param resource $smtp SMTP connection
     * @param array $emailData Email data
     * @return array Send results
     */
    private function smtpSend($smtp, array $emailData): array
    {
        // Mail from
        $this->smtpCommand($smtp, "MAIL FROM: <{$emailData['from']}>");
        
        // RCPT to
        if (is_array($emailData['to'])) {
            foreach ($emailData['to'] as $recipient) {
                $this->smtpCommand($smtp, "RCPT TO: <{$recipient}>");
            }
        } else {
            $this->smtpCommand($smtp, "RCPT TO: <{$emailData['to']}>");
        }
        
        // Data
        $this->smtpCommand($smtp, "DATA");
        
        // Headers
        foreach ($emailData['headers'] as $header) {
            fwrite($smtp, $header . "\r\n");
        }
        
        // Subject
        fwrite($smtp, "Subject: {$emailData['subject']}\r\n");
        
        // Body
        fwrite($smtp, "\r\n");
        fwrite($smtp, $emailData['body']);
        fwrite($smtp, "\r\n.\r\n");
        
        // Get response
        $response = fgets($smtp, 1024);
        
        return [
            'success' => strpos($response, '250') !== false,
            'response' => $response,
            'email_data' => $emailData
        ];
    }

    /**
     * Executes SMTP command.
     * 
     * @param resource $smtp SMTP connection
     * @param string $command Command to execute
     * @return string Response
     */
    private function smtpCommand($smtp, string $command): string
    {
        fwrite($smtp, $command . "\r\n");
        return fgets($smtp, 1024);
    }

    /**
     * Loads an email template.
     * 
     * @param string $templateName Template name
     * @return array Template data
     * @throws \Exception on template not found
     */
    private function loadTemplate(string $templateName): array
    {
        if (!isset($this->emailTemplates[$templateName])) {
            throw new \Exception("Template not found: {$templateName}");
        }
        
        return $this->emailTemplates[$templateName];
    }

    /**
     * Renders an email template.
     * 
     * @param array $template Template data
     * @param array $data Template data
     * @return array Rendered template
     */
    private function renderTemplate(array $template, array $data): array
    {
        $rendered = $template;
        
        // Render subject
        $rendered['subject'] = $this->renderText($template['subject'], $data);
        
        // Render body
        $rendered['body'] = $this->renderText($template['body'], $data);
        
        return $rendered;
    }

    /**
     * Renders text with template variables.
     * 
     * @param string $text Text to render
     * @param array $data Template data
     * @return string Rendered text
     */
    private function renderText(string $text, array $data): string
    {
        // Simple template rendering
        foreach ($data as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
            $text = str_replace('{' . $key . '}', $value, $text);
        }
        
        return $text;
    }

    /**
     * Logs email activity.
     * 
     * @param array $emailData Email data
     * @param array $result Send results
     */
    private function logEmail(array $emailData, array $result): void
    {
        $logEntry = [
            'timestamp' => time(),
            'email_data' => $emailData,
            'result' => $result,
            'status' => $result['success'] ? 'sent' : 'failed'
        ];
        
        $this->emailLog[] = $logEntry;
        
        // Write to log file
        $logMessage = sprintf(
            "[%s] [%s] To: %s, Subject: %s\n",
            date('Y-m-d H:i:s', $logEntry['timestamp']),
            $logEntry['status'],
            is_array($emailData['to']) ? implode(', ', $emailData['to']) : $emailData['to'],
            $emailData['subject']
        );
        
        file_put_contents($this->config['log_file'], $logMessage, FILE_APPEND);
    }

    /**
     * Cleans old email logs.
     */
    private function cleanOldLogs(): void
    {
        $cutoffTime = time() - ($this->config['log_retention_days'] * 24 * 60 * 60);
        
        $this->emailLog = array_filter($this->emailLog, fn($log) => $log['timestamp'] >= $cutoffTime);
    }

    /**
     * Gets email statistics.
     * 
     * @return array Email statistics
     */
    public function getEmailStatistics(): array
    {
        $stats = [
            'total_emails' => count($this->emailLog),
            'sent_emails' => 0,
            'failed_emails' => 0,
            'emails_by_hour' => [],
            'emails_by_day' => [],
            'emails_by_status' => []
        ];
        
        foreach ($this->emailLog as $log) {
            if ($log['status'] === 'sent') {
                $stats['sent_emails']++;
            } else {
                $stats['failed_emails']++;
            }
            
            // Count by hour
            $hour = date('H', $log['timestamp']);
            $stats['emails_by_hour'][$hour] = ($stats['emails_by_hour'][$hour] ?? 0) + 1;
            
            // Count by day
            $day = date('Y-m-d', $log['timestamp']);
            $stats['emails_by_day'][$day] = ($stats['emails_by_day'][$day] ?? 0) + 1;
            
            // Count by status
            $stats['emails_by_status'][$log['status']] = ($stats['emails_by_status'][$log['status']] ?? 0) + 1;
        }
        
        return $stats;
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
     * Gets email templates.
     * 
     * @return array Email templates
     */
    public function getTemplatesList(): array
    {
        return array_keys($this->emailTemplates);
    }

    /**
     * Gets email template by name.
     * 
     * @param string $templateName Template name
     * @return array|null Template data or null
     */
    public function getTemplateByName(string $templateName): ?array
    {
        return $this->emailTemplates[$templateName] ?? null;
    }

    /**
     * Gets email log by time range.
     * 
     * @param int $startTime Start timestamp
     * @param int $endTime End timestamp
     * @return array Email logs in time range
     */
    public function getEmailLogsByTimeRange(int $startTime, int $endTime): array
    {
        return array_filter($this->emailLog, fn($log) => 
            $log['timestamp'] >= $startTime && $log['timestamp'] <= $endTime
        );
    }

    /**
     * Gets email logs by status.
     * 
     * @param string $status Status to filter by
     * @return array Email logs with status
     */
    public function getEmailLogsByStatus(string $status): array
    {
        return array_filter($this->emailLog, fn($log) => $log['status'] === $status);
    }

    /**
     * Clears email logs.
     */
    public function clearEmailLogs(): void
    {
        $this->emailLog = [];
    }

    /**
     * Validates SMTP configuration.
     * 
     * @return array Validation results
     */
    public function validateSmtpConfig(): array
    {
        try {
            $smtp = $this->createSmtpConnection();
            fclose($smtp);
            
            return [
                'success' => true,
                'message' => 'SMTP configuration is valid'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SMTP configuration validation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Tests email sending.
     * 
     * @param array $testEmail Test email data
     * @return array Test results
     */
    public function testEmailSending(array $testEmail): array
    {
        try {
            // Validate test email data
            $this->validateEmailData($testEmail);
            
            // Send test email
            $result = $this->sendEmail($testEmail);
            
            return [
                'success' => $result['success'],
                'message' => $result['success'] ? 'Test email sent successfully' : 'Test email failed: ' . $result['response'],
                'result' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Test email sending failed: ' . $e->getMessage()
            ];
        }
    }
}