<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Report Template Service
 * 
 * Handles report template management and generation.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 * @BABOK Related: FR-09.03 - Report Templates
 */
class ReportTemplateService
{
    private string $tablePrefix;

    public function __construct(string $tablePrefix)
    {
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Creates a new report template.
     * 
     * @param array $templateData Template data
     * @return array Created template
     */
    public function createTemplate(array $templateData): array
    {
        try {
            // Validate template data
            $this->validateTemplateData($templateData);
            
            // Prepare template data for insertion
            $templateData['created_at'] = date('Y-m-d H:i:s');
            $templateData['updated_at'] = date('Y-m-d H:i:s');
            
            // Insert template
            $templateId = $this->insertTemplate($templateData);
            
            // Get created template
            $template = $this->getTemplateById($templateId);
            
            return $template;
            
        } catch (\Exception $e) {
            throw new \Exception("Template creation failed: " . $e->getMessage());
        }
    }

    /**
     * Updates an existing report template.
     * 
     * @param int $templateId Template ID
     * @param array $templateData Template data
     * @return array Updated template
     */
    public function updateTemplate(int $templateId, array $templateData): array
    {
        try {
            // Validate template data
            $this->validateTemplateData($templateData);
            
            // Check if template exists
            $existingTemplate = $this->getTemplateById($templateId);
            if (!$existingTemplate) {
                throw new \Exception("Template not found");
            }
            
            // Update template
            $templateData['updated_at'] = date('Y-m-d H:i:s');
            
            $this->updateTemplateById($templateId, $templateData);
            
            // Get updated template
            $template = $this->getTemplateById($templateId);
            
            return $template;
            
        } catch (\Exception $e) {
            throw new \Exception("Template update failed: " . $e->getMessage());
        }
    }

    /**
     * Deletes a report template.
     * 
     * @param int $templateId Template ID
     * @return bool Success status
     */
    public function deleteTemplate(int $templateId): bool
    {
        try {
            // Check if template exists
            $existingTemplate = $this->getTemplateById($templateId);
            if (!$existingTemplate) {
                throw new \Exception("Template not found");
            }
            
            // Delete template
            return $this->deleteTemplateById($templateId);
            
        } catch (\Exception $e) {
            throw new \Exception("Template deletion failed: " . $e->getMessage());
        }
    }

    /**
     * Gets a report template by ID.
     * 
     * @param int $templateId Template ID
     * @return array Template data
     */
    public function getTemplateById(int $templateId): ?array
    {
        $tableName = $this->getTemplateTableName();
        $sql = "SELECT * FROM {$tableName} WHERE template_id = {$templateId}";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Gets templates by filters.
     * 
     * @param array $filters Filter parameters
     * @return array Templates
     */
    public function getTemplates(array $filters): array
    {
        $tableName = $this->getTemplateTableName();
        
        // Build query
        $conditions = ["1=1"];
        
        if (isset($filters['template_name'])) {
            $conditions[] = "template_name LIKE '%" . \db_escape($filters['template_name']) . "%'";
        }
        
        if (isset($filters['report_type'])) {
            $conditions[] = "report_type = '{$filters['report_type']}'";
        }
        
        if (isset($filters['created_by'])) {
            $conditions[] = "created_by = {$filters['created_by']}";
        }
        
        if (isset($filters['is_active'])) {
            $conditions[] = "is_active = " . ($filters['is_active'] ? 1 : 0);
        }
        
        $sql = "SELECT * FROM {$tableName} WHERE " . implode(' AND ', $conditions) . " ORDER BY created_at DESC";
        
        $result = \db_query($sql);
        $templates = [];
        
        if ($result !== false) {
            while ($row = \db_fetch_assoc($result)) {
                if ($row !== false) {
                    $templates[] = $row;
                }
            }
        }
        
        return $templates;
    }

    /**
     * Gets template by name.
     * 
     * @param string $templateName Template name
     * @return array Template data
     */
    public function getTemplateByName(string $templateName): ?array
    {
        $tableName = $this->getTemplateTableName();
        $sql = "SELECT * FROM {$tableName} WHERE template_name = '" . \db_escape($templateName) . "'";
        
        $result = \db_query($sql);
        if ($result !== false && \db_num_rows($result) > 0) {
            $row = \db_fetch_assoc($result);
            return $row !== false ? $row : null;
        }

        return null;
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
        
        if (empty($templateData['template_name'])) {
            throw new \Exception("Template name cannot be empty");
        }
        
        if (!isset($templateData['report_type'])) {
            throw new \Exception("Report type is required");
        }
        
        $validTypes = ['sales', 'customer', 'inventory', 'financial', 'performance', 'predictive'];
        if (!in_array($templateData['report_type'], $validTypes)) {
            throw new \Exception("Invalid report type");
        }
        
        if (!isset($templateData['formatting_options'])) {
            throw new \Exception("Formatting options are required");
        }
        
        if (!isset($templateData['access_permissions'])) {
            throw new \Exception("Access permissions are required");
        }
        
        if (!isset($templateData['description'])) {
            throw new \Exception("Template description is required");
        }
        
        if (empty($templateData['description'])) {
            throw new \Exception("Template description cannot be empty");
        }
    }

    /**
     * Inserts template into database.
     * 
     * @param array $templateData Template data
     * @return int Template ID
     */
    private function insertTemplate(array $templateData): int
    {
        $tableName = $this->getTemplateTableName();
        
        // Prepare data for insertion
        $fields = [];
        $values = [];
        
        foreach ($templateData as $key => $value) {
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
     * Updates template in database.
     * 
     * @param int $templateId Template ID
     * @param array $templateData Template data
     * @return bool Success status
     */
    private function updateTemplateById(int $templateId, array $templateData): bool
    {
        $tableName = $this->getTemplateTableName();
        
        $updates = [];
        foreach ($templateData as $key => $value) {
            if ($key === 'updated_at') {
                $updates[] = "{$key} = '{$value}'";
            } else {
                $updates[] = "{$key} = " . (is_numeric($value) ? $value : "'" . \db_escape($value) . "'");
            }
        }
        
        $sql = "UPDATE {$tableName} SET " . implode(', ', $updates) . " 
                WHERE template_id = {$templateId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Deletes template from database.
     * 
     * @param int $templateId Template ID
     * @return bool Success status
     */
    private function deleteTemplateById(int $templateId): bool
    {
        $tableName = $this->getTemplateTableName();
        $sql = "DELETE FROM {$tableName} WHERE template_id = {$templateId}";
        
        return \db_query($sql) !== false;
    }

    /**
     * Gets template table name.
     * 
     * @return string Table name
     */
    private function getTemplateTableName(): string
    {
        return $this->tablePrefix . 'report_templates';
    }

    /**
     * Ensures the table exists.
     */
    public function ensureTableExists(): void
    {
        $tableName = $this->getTemplateTableName();
        
        // Check if table exists
        $checkSql = "SHOW TABLES LIKE '{$tableName}'";
        $result = \db_query($checkSql);
        
        if ($result !== false && \db_num_rows($result) === 0) {
            // Create table
            $createSql = "CREATE TABLE {$tableName} (
                template_id INT AUTO_INCREMENT PRIMARY KEY,
                template_name VARCHAR(255) NOT NULL,
                report_type VARCHAR(50) NOT NULL,
                description TEXT NOT NULL,
                formatting_options JSON NOT NULL,
                access_permissions JSON NOT NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                INDEX idx_template_name (template_name),
                INDEX idx_report_type (report_type),
                INDEX idx_created_by (created_by),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            \db_query($createSql);
        }
    }
}