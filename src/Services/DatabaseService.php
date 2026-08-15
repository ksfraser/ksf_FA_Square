<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Database Service
 * 
 * Handles database operations and optimization.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class DatabaseService
{
    private array $config;
    private ?PDO $pdo = null;
    private array $queryStats = [];
    private const MAX_CONNECTIONS = 100;
    private const QUERY_TIMEOUT = 30;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'frontaccounting',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'driver_options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_TIMEOUT => self::QUERY_TIMEOUT
            ]
        ], $config);
    }

    /**
     * Gets PDO connection.
     * 
     * @return PDO Database connection
     * @throws \Exception on connection failure
     */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            try {
                $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};charset={$this->config['charset']}";
                $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $this->config['driver_options']);
            } catch (\PDOException $e) {
                throw new \Exception("Database connection failed: " . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    /**
     * Executes a query.
     * 
     * @param string $sql SQL query
     * @param array $parameters Query parameters
     * @return PDOStatement Statement object
     * @throws \Exception on query execution failure
     */
    public function query(string $sql, array $parameters = []): PDOStatement
    {
        try {
            $startTime = microtime(true);
            $statement = $this->getConnection()->prepare($sql);
            
            foreach ($parameters as $key => $value) {
                $statement->bindValue($key, $value, $this->getPdoType($value));
            }
            
            $statement->execute();
            
            // Track query statistics
            $executionTime = microtime(true) - $startTime;
            $this->trackQuery($sql, $executionTime, count($parameters));
            
            return $statement;
        } catch (\PDOException $e) {
            throw new \Exception("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Executes a query and returns all results.
     * 
     * @param string $sql SQL query
     * @param array $parameters Query parameters
     * @return array Query results
     */
    public function queryAll(string $sql, array $parameters = []): array
    {
        $statement = $this->query($sql, $parameters);
        return $statement->fetchAll();
    }

    /**
     * Executes a query and returns the first result.
     * 
     * @param string $sql SQL query
     * @param array $parameters Query parameters
     * @return array|null Query result or null
     */
    public function queryOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->query($sql, $parameters);
        $result = $statement->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Executes a query and returns a single value.
     * 
     * @param string $sql SQL query
     * @param array $parameters Query parameters
     * @return mixed|null Single value or null
     */
    public function queryValue(string $sql, array $parameters = [])
    {
        $statement = $this->query($sql, $parameters);
        $result = $statement->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Executes an insert query.
     * 
     * @param string $table Table name
     * @param array $data Data to insert
     * @return int Inserted ID
     * @throws \Exception on insertion failure
     */
    public function insert(string $table, array $data): int
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($field) => ":{$field}", $fields);
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->query($sql, $data);
        return (int)$this->getConnection()->lastInsertId();
    }

    /**
     * Executes an update query.
     * 
     * @param string $table Table name
     * @param array $data Data to update
     * @param string $where WHERE clause
     * @param array $whereParameters WHERE parameters
     * @return int Number of affected rows
     * @throws \Exception on update failure
     */
    public function update(string $table, array $data, string $where, array $whereParameters = []): int
    {
        $setFields = array_map(fn($field) => "{$field} = :{$field}", array_keys($data));
        $sql = "UPDATE {$table} SET " . implode(', ', $setFields) . " WHERE {$where}";
        
        $parameters = array_merge($data, $whereParameters);
        $statement = $this->query($sql, $parameters);
        
        return $statement->rowCount();
    }

    /**
     * Executes a delete query.
     * 
     * @param string $table Table name
     * @param string $where WHERE clause
     * @param array $whereParameters WHERE parameters
     * @return int Number of affected rows
     * @throws \Exception on deletion failure
     */
    public function delete(string $table, string $where, array $whereParameters = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $statement = $this->query($sql, $whereParameters);
        
        return $statement->rowCount();
    }

    /**
     * Begins a transaction.
     * 
     * @return bool Success status
     */
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commits a transaction.
     * 
     * @return bool Success status
     */
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    /**
     * Rolls back a transaction.
     * 
     * @return bool Success status
     */
    public function rollback(): bool
    {
        return $this->getConnection()->rollback();
    }

    /**
     * Gets table list.
     * 
     * @return array Table list
     */
    public function getTableList(): array
    {
        $sql = "SHOW TABLES";
        $result = $this->queryAll($sql);
        return array_column($result, array_keys($result[0])[0]);
    }

    /**
     * Gets table information.
     * 
     * @param string $table Table name
     * @return array Table information
     */
    public function getTableInfo(string $table): array
    {
        $sql = "SHOW COLUMNS FROM {$table}";
        $columns = $this->queryAll($sql);
        
        $sql = "SHOW TABLE STATUS LIKE '{$table}'";
        $status = $this->queryOne($sql);
        
        return [
            'columns' => $columns,
            'status' => $status
        ];
    }

    /**
     * Analyzes a table.
     * 
     * @param string $table Table name
     * @return bool Success status
     */
    public function analyzeTable(string $table): bool
    {
        $sql = "ANALYZE TABLE {$table}";
        $this->query($sql);
        return true;
    }

    /**
     * Optimizes a table.
     * 
     * @param string $table Table name
     * @return bool Success status
     */
    public function optimizeTable(string $table): bool
    {
        $sql = "OPTIMIZE TABLE {$table}";
        $this->query($sql);
        return true;
    }

    /**
     * Rebuilds table indexes.
     * 
     * @param string $table Table name
     * @return bool Success status
     */
    public function rebuildTableIndexes(string $table): bool
    {
        $tableInfo = $this->getTableInfo($table);
        
        // Drop existing indexes
        foreach ($tableInfo['columns'] as $column) {
            if ($column['Key'] !== 'PRI' && $column['Key'] !== '') {
                $sql = "DROP INDEX {$column['Key']} ON {$table}";
                $this->query($sql);
            }
        }
        
        // Rebuild indexes
        foreach ($tableInfo['columns'] as $column) {
            if ($column['Key'] !== 'PRI' && $column['Key'] !== '') {
                $sql = "ALTER TABLE {$table} ADD INDEX {$column['Key']} ({$column['Field']})";
                $this->query($sql);
            }
        }
        
        return true;
    }

    /**
     * Gets query statistics.
     * 
     * @return array Query statistics
     */
    public function getQueryStats(): array
    {
        return $this->queryStats;
    }

    /**
     * Clears query statistics.
     */
    public function clearQueryStats(): void
    {
        $this->queryStats = [];
    }

    /**
     * Gets database size.
     * 
     * @return array Database size information
     */
    public function getDatabaseSize(): array
    {
        $sql = "SELECT 
                table_name AS table_name,
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = '{$this->config['database']}'
                ORDER BY size_mb DESC";
        
        $tables = $this->queryAll($sql);
        
        $totalSize = 0;
        foreach ($tables as $table) {
            $totalSize += $table['size_mb'];
        }
        
        return [
            'total_size_mb' => $totalSize,
            'tables' => $tables
        ];
    }

    /**
     * Gets slow queries.
     * 
     * @param float $minExecutionTime Minimum execution time in seconds
     * @return array Slow queries
     */
    public function getSlowQueries(float $minExecutionTime = 1.0): array
    {
        $slowQueries = [];
        
        foreach ($this->queryStats as $query) {
            if ($query['execution_time'] >= $minExecutionTime) {
                $slowQueries[] = $query;
            }
        }
        
        return $slowQueries;
    }

    /**
     * Tracks query statistics.
     * 
     * @param string $sql SQL query
     * @param float $executionTime Execution time
     * @param int $parameterCount Parameter count
     */
    private function trackQuery(string $sql, float $executionTime, int $parameterCount): void
    {
        $this->queryStats[] = [
            'sql' => $sql,
            'execution_time' => $executionTime,
            'parameter_count' => $parameterCount,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Gets PDO type for a value.
     * 
     * @param mixed $value Value to get type for
     * @return int PDO type constant
     */
    private function getPdoType($value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            return PDO::PARAM_BOOL;
        } elseif (is_null($value)) {
            return PDO::PARAM_NULL;
        } else {
            return PDO::PARAM_STR;
        }
    }

    /**
     * Gets connection status.
     * 
     * @return array Connection status
     */
    public function getConnectionStatus(): array
    {
        try {
            $connection = $this->getConnection();
            $info = $connection->getAttribute(PDO::ATTR_SERVER_INFO);
            
            return [
                'connected' => true,
                'server_info' => $info,
                'database' => $this->config['database'],
                'host' => $this->config['host']
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Closes the database connection.
     */
    public function closeConnection(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null;
        }
    }

    /**
     * Gets database version.
     * 
     * @return string Database version
     */
    public function getDatabaseVersion(): string
    {
        try {
            $version = $this->queryValue("SELECT VERSION()");
            return $version ?? 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Gets current database variables.
     * 
     * @return array Database variables
     */
    public function getDatabaseVariables(): array
    {
        try {
            $sql = "SHOW VARIABLES";
            $result = $this->queryAll($sql);
            
            $variables = [];
            foreach ($result as $row) {
                $variables[$row['Variable_name']] = $row['Value'];
            }
            
            return $variables;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Gets current database status.
     * 
     * @return array Database status
     */
    public function getDatabaseStatus(): array
    {
        try {
            $sql = "SHOW STATUS";
            $result = $this->queryAll($sql);
            
            $status = [];
            foreach ($result as $row) {
                $status[$row['Variable_name']] = $row['Value'];
            }
            
            return $status;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Creates a backup of the database.
     * 
     * @param string $backupFile Backup file path
     * @return bool Success status
     */
    public function createBackup(string $backupFile): bool
    {
        try {
            $command = sprintf(
                "mysqldump --host=%s --port=%d --user=%s --password=%s %s > %s",
                escapeshellarg($this->config['host']),
                escapeshellarg($this->config['port']),
                escapeshellarg($this->config['username']),
                escapeshellarg($this->config['password']),
                escapeshellarg($this->config['database']),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return true;
            } else {
                throw new \Exception("Backup failed with return code: " . $returnCode);
            }
        } catch (\Exception $e) {
            throw new \Exception("Database backup failed: " . $e->getMessage());
        }
    }

    /**
     * Restores a database backup.
     * 
     * @param string $backupFile Backup file path
     * @return bool Success status
     */
    public function restoreBackup(string $backupFile): bool
    {
        try {
            $command = sprintf(
                "mysql --host=%s --port=%d --user=%s --password=%s %s < %s",
                escapeshellarg($this->config['host']),
                escapeshellarg($this->config['port']),
                escapeshellarg($this->config['username']),
                escapeshellarg($this->config['password']),
                escapeshellarg($this->config['database']),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return true;
            } else {
                throw new \Exception("Restore failed with return code: " . $returnCode);
            }
        } catch (\Exception $e) {
            throw new \Exception("Database restore failed: " . $e->getMessage());
        }
    }

    /**
     * Gets table statistics.
     * 
     * @param string $table Table name
     * @return array Table statistics
     */
    public function getTableStatistics(string $table): array
    {
        try {
            $sql = "SHOW TABLE STATUS LIKE '{$table}'";
            $status = $this->queryOne($sql);
            
            if ($status) {
                return [
                    'name' => $status['Name'],
                    'engine' => $status['Engine'],
                    'rows' => $status['Rows'],
                    'data_size' => $status['Data_length'],
                    'index_size' => $status['Index_length'],
                    'total_size' => $status['Data_length'] + $status['Index_length'],
                    'collation' => $status['Collation'],
                    'auto_increment' => $status['Auto_increment'],
                    'create_time' => $status['Create_time'],
                    'update_time' => $status['Update_time']
                ];
            }
            
            return [];
        } catch (\Exception $e) {
            throw new \Exception("Failed to get table statistics: " . $e->getMessage());
        }
    }

    /**
     * Gets database schema information.
     * 
     * @return array Database schema information
     */
    public function getSchemaInfo(): array
    {
        try {
            $info = [
                'database' => $this->config['database'],
                'version' => $this->getDatabaseVersion(),
                'tables' => $this->getTableList(),
                'size' => $this->getDatabaseSize(),
                'connection_status' => $this->getConnectionStatus(),
                'variables' => $this->getDatabaseVariables(),
                'status' => $this->getDatabaseStatus()
            ];
            
            return $info;
        } catch (\Exception $e) {
            throw new \Exception("Failed to get schema info: " . $e->getMessage());
        }
    }

    /**
     * Validates database connection.
     * 
     * @return bool True if connection is valid
     */
    public function isValidConnection(): bool
    {
        try {
            $this->getConnection()->query("SELECT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Escapes a string for use in SQL queries.
     * 
     * @param string $string String to escape
     * @return string Escaped string
     */
    public function escape(string $string): string
    {
        return $this->getConnection()->quote($string);
    }

    /**
     * Gets database error information.
     * 
     * @return array Error information
     */
    public function getErrorInfo(): array
    {
        if ($this->pdo !== null) {
            return $this->pdo->errorInfo();
        }
        return [];
    }

    /**
     * Gets database error message.
     * 
     * @return string Error message
     */
    public function getErrorMessage(): string
    {
        $errorInfo = $this->getErrorInfo();
        return $errorInfo[2] ?? 'Unknown database error';
    }
}