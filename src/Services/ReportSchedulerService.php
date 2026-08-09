<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Report Scheduler Service
 * 
 * Handles automatic report scheduling and generation.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-09.04 - Report Scheduling
 */
class ReportSchedulerService
{
    private BusinessIntelligenceService $businessIntelligence;
    private ReportTemplateService $templateService;
    private AdvancedReportingSystem $reportingSystem;
    private string $tablePrefix;

    public function __construct(
        BusinessIntelligenceService $businessIntelligence,
        ReportTemplateService $templateService,
        AdvancedReportingSystem $reportingSystem,
        string $tablePrefix
    ) {
        $this->businessIntelligence = $businessIntelligence;
        $this->templateService = $templateService;
        $this->reportingSystem = $reportingSystem;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Creates a new schedule.
     * 
     * @param array $scheduleData Schedule data
     * @return array Created schedule
     */
    public function createSchedule(array $scheduleData): array
    {
        try {
            // Validate schedule data
            $this->validateScheduleData($scheduleData);
            
            // Prepare schedule data for insertion
            $scheduleData['created_at'] = date('Y-m-d H:i:s');
            $scheduleData['updated_at'] = date('Y-m-d H:i:s');
            $scheduleData['last_run'] = null;
            $scheduleData['next_run'] = $this->calculateNextRun($scheduleData);
            
            // Insert schedule
            $scheduleId = $this->insertSchedule($scheduleData);
            
            // Get created schedule
            $schedule = $this->getScheduleById($scheduleId);
            
            return $schedule;
            
        } catch (\Exception $e) {
            throw new \Exception("Schedule creation failed: " . $e->getMessage());
        }
    }

    /**
     * Updates an existing schedule.
     * 
     * @param int $scheduleId Schedule ID
     * @param array $scheduleData Schedule data
     * @return array Updated schedule
     */
    public function updateSchedule(int $scheduleId, array $scheduleData): array
    {
        try {
            // Validate schedule data
            $this->validateScheduleData($scheduleData);
            
            // Check if schedule exists
            $existingSchedule = $this->getScheduleById($scheduleId);
            if (!$existingSchedule) {
                throw new \Exception("Schedule not found");
            }
            
            // Update schedule
            $scheduleData['updated_at'] = date('Y-m-d H:i:s');
            $scheduleData['next_run'] = $this->calculateNextRun($scheduleData);
            
            $this->updateScheduleById($scheduleId, $scheduleData);
            
            // Get updated schedule
            $schedule = $this->getScheduleById($scheduleId);
            
            return $schedule;
            
        } catch (\Exception $e) {
            throw new \Exception("Schedule update failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes a schedule.
     * 
     * @param int $scheduleId Schedule ID
     * @return bool Success status
     */
    public function deleteSchedule(int $scheduleId): bool
    {
        try {
            // Check if schedule exists
            $existingSchedule = $this->getScheduleById($scheduleId);
            if (!$existingSchedule) {
                throw new \Exception("Schedule not found");
            }
            
            // Delete schedule
            return $this->deleteScheduleById($scheduleId);
            
        } catch (\Exception $e) {
            throw new \Exception("Schedule deletion failed: " . $e->getMessage());
        }
    }

    /**
     * Gets a schedule by ID.
     * 
     * @param int $scheduleId Schedule ID
     * @return array Schedule data
     */
    public function getScheduleById(int $scheduleId): ?array
    {
        $tableName = $this->getScheduleTableName();
        $sql = "SELECT * FROM {$tableName} WHERE schedule_id = {$scheduleId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets schedules by filters.
     * 
     * @param array $filters Filter parameters
     * @return array Schedules
     */
    public function getSchedules(array $filters): array
    {
        $tableName = $this->getScheduleTableName();
        
        // Build query
        $conditions = ["1=1"];
        
        if (isset($filters['template_id'])) {
            $conditions[] = "template_id = {$filters['template_id']}";
        }
        
        if (isset($filters['schedule_type'])) {
            $conditions[] = "schedule_type = '{$filters['schedule_type']}'";
        }
        
        if (isset($filters['is_active'])) {
            $conditions[] = "is_active = " . ($filters['is_active'] ? 1 : 0);
        }
        
        if (isset($filters['created_by'])) {
            $conditions[] = "created_by = {$filters['created_by']}";
        }
        
        $sql = "SELECT * FROM {$tableName} WHERE " . implode(' AND ', $conditions) . " ORDER BY next_run ASC";
        
        $result = \db_query($sql);
        $schedules = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $schedules[] = $row;
                }
            }
        }
        
        return $schedules;
    }

    /**
     * Runs a schedule manually.
     * 
     * @param int $scheduleId Schedule ID
     * @return array Schedule execution result
     */
    public function runSchedule(int $scheduleId): array
    {
        try {
            // Get schedule
            $schedule = $this->getScheduleById($scheduleId);
            if (!$schedule) {
                throw new \Exception("Schedule not found");
            }
            
            // Get template
            $template = $this->templateService->getTemplateById($schedule['template_id']);
            if (!$template) {
                throw new \Exception("Template not found");
            }
            
            // Generate report
            $reportData = [
                'report_type' => $template['report_type'],
                'user_id' => $schedule['created_by'],
                'filters' => json_decode($schedule['schedule_config'], true)['filters'] ?? []
            ];
            
            $report = $this->businessIntelligence->generateCustomReport($reportData);
            
            // Update schedule
            $this->updateScheduleRunTime($scheduleId);
            
            return [
                'success' => true,
                'report_id' => $report['report_id'] ?? null,
                'executed_at' => date('Y-m-d H:i:s'),
                'schedule_id' => $scheduleId,
                'message' => 'Schedule executed successfully'
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Schedule execution failed: " . $e->getMessage());
        }
    }

    /**
     * Checks and runs due schedules.
     * 
     * @return array Schedule execution results
     */
    public function runDueSchedules(): array
    {
        $results = [];
        
        // Get due schedules
        $dueSchedules = $this->getDueSchedules();
        
        foreach ($dueSchedules as $schedule) {
            try {
                $result = $this->runSchedule($schedule['schedule_id']);
                $results[] = $result;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'schedule_id' => $schedule['schedule_id'],
                    'error' => $e->getMessage(),
                    'executed_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return $results;
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
        
        $validTypes = ['daily', 'weekly', 'monthly', 'quarterly', 'custom'];
        if (!in_array($scheduleData['schedule_type'], $validTypes)) {
            throw new \Exception("Invalid schedule type");
        }
        
        if (!isset($scheduleData['schedule_config'])) {
            throw new \Exception("Schedule configuration is required");
        }
        
        if (!isset($scheduleData['distribution_config'])) {
            throw new \Exception("Distribution configuration is required");
        }
        
        if (!isset($scheduleData['created_by'])) {
            throw new \Exception("Created by is required");
        }
    }

    /**
     * Calculates next run time.
     * 
     * @param array $scheduleData Schedule data
     * @return string Next run time
     */
    private function calculateNextRun(array $scheduleData): string
    {
        $scheduleType = $scheduleData['schedule_type'];
        $config = json_decode($scheduleData['schedule_config'], true);
        
        $now = time();
        
        switch ($scheduleType) {
            case 'daily':
                $nextRun = strtotime($config['time'] ?? '09:00', $now);
                if ($nextRun < $now) {
                    $nextRun = strtotime('+1 day', $nextRun);
                }
                break;
                
            case 'weekly':
                $dayOfWeek = $config['day_of_week'] ?? 1; // Monday
                $time = $config['time'] ?? '09:00';
                $nextRun = strtotime("next {$this->getDayName($dayOfWeek)} {$time}", $now);
                break;
                
            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? 1;
                $time = $config['time'] ?? '09:00';
                $nextRun = strtotime("first day of next month {$time}", $now);
                break;
                
            case 'quarterly':
                $month = $config['quarter_month'] ?? 3; // March
                $day = $config['day_of_quarter'] ?? 1;
                $time = $config['time'] ?? '09:00';
                $nextRun = strtotime("first day of {$this->getQuarterMonth($month)} {$time}", $now);
                break;
                
            case 'custom':
                $cronExpression = $config['cron_expression'] ?? '* * * * *';
                $nextRun = $this->parseCronExpression($cronExpression, $now);
                break;
                
            default:
                throw new \Exception("Unsupported schedule type: {$scheduleType}");
        }
        
        return date('Y-m-d H:i:s', $nextRun);
    }

    /**
     * Gets due schedules.
     * 
     * @return array Due schedules
     */
    private function getDueSchedules(): array
    {
        $tableName = $this->getScheduleTableName();
        $sql = "SELECT * FROM {$tableName} 
                WHERE is_active = 1 AND next_run <= NOW() 
                ORDER BY next_run ASC";
        
        $result = \db_query($sql);
        $schedules = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $schedules[] = $row;
                }
            }
        }
        
        return $schedules;
    }

    /**
     * Updates schedule run time.
     * 
     * @param int $scheduleId Schedule ID
     * @return bool Success status
     */
    private function updateScheduleRunTime(int $scheduleId): bool
    {
        $tableName = $this->getScheduleTableName();
        
        $sql = "UPDATE {$tableName} SET 
                last_run = NOW(),
                next_run = '" . $this->calculateNextRun(['schedule_type' => 'daily']) . "',
                updated_at = NOW()
                WHERE schedule_id = {$scheduleId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Inserts schedule into database.
     * 
     * @param array $scheduleData Schedule data
     * @return int Schedule ID
     */
    private function insertSchedule(array $scheduleData): int
    {
        $tableName = $this->getScheduleTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($scheduleData as $key => $value) {
            $fields[] = $key;
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = "'" . \db_escape($value) . "'";
            }
        }
        
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";

        \db_query($sql);
        return \db_insert_id($tableName);
    }

    /**
     * Updates schedule in database.
     * 
     * @param int $scheduleId Schedule ID
     * @param array $scheduleData Schedule data
     * @return bool Success status
     */
    private function updateScheduleById(int $scheduleId, array $scheduleData): bool
    {
        $tableName = $this->getScheduleTableName();
        
        $updates = [];
        foreach ($scheduleData as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE schedule_id = {$scheduleId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes schedule from database.
     * 
     * @param int $scheduleId Schedule ID
     * @return bool Success status
     */
    private function deleteScheduleById(int $scheduleId): bool
    {
        $tableName = $this->getScheduleTableName();
        $sql = "DELETE FROM {$tableName} WHERE schedule_id = {$scheduleId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets day name from day of week.
     * 
     * @param int $dayOfWeek Day of week
     * @return string Day name
     */
    private function getDayName(int $dayOfWeek): string
    {
        $days = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $days[$dayOfWeek] ?? 'Monday';
    }

    /**
     * Gets quarter month.
     * 
     * @param int $quarter Quarter number
     * @return string Month name
     */
    private function getQuarterMonth(int $quarter): string
    {
        $months = ['', 'January', 'April', 'July', 'October'];
        return $months[$quarter] ?? 'January';
    }

    /**
     * Parses cron expression.
     * 
     * @param string $cronExpression Cron expression
     * @param int $currentTime Current time
     * @return int Next run time
     */
    private function parseCronExpression(string $cronExpression, int $currentTime): int
    {
        // Simple cron parser for basic expressions
        $parts = explode(' ', $cronExpression);
        
        if (count($parts) !== 5) {
            throw new \Exception("Invalid cron expression");
        }
        
        [$minute, $hour, $day, $month, $weekday] = $parts;
        
        // For now, just return next hour (simplified)
        return strtotime('+1 hour', $currentTime);
    }

    /**
     * Gets schedule table name.
     * 
     * @return string Table name
     */
    private function getScheduleTableName(): string
    {
        return $this->tablePrefix . 'report_schedules';
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getScheduleTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                schedule_id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                schedule_type VARCHAR(50) NOT NULL,
                schedule_config JSON NOT NULL,
                distribution_config JSON NOT NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                last_run DATETIME NULL,
                next_run DATETIME NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                INDEX idx_template_id (template_id),
                INDEX idx_schedule_type (schedule_type),
                INDEX idx_is_active (is_active),
                INDEX idx_next_run (next_run),
                FOREIGN KEY (template_id) REFERENCES {$this->getTemplateTableName()}(template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }
}