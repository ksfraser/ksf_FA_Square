<?php
declare(strict_types=1);

/**
 * Monitoring Service
 * 
 * Handles system monitoring and metrics collection.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class MonitoringService
{
    private array $config;
    private array $metrics = [];
    private array $alerts = [];
    private array $thresholds = [];
    private const METRICS_RETENTION_DAYS = 30;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'metrics_enabled' => true,
            'metrics_retention_days' => self::METRICS_RETENTION_DAYS,
            'alerts_enabled' => true,
            'log_level' => 'INFO',
            'log_file' => sys_get_temp_dir() . '/monitoring.log'
        ], $config);
        
        $this->initializeThresholds();
    }

    /**
     * Collects system metrics.
     * 
     * @return array Collected metrics
     */
    public function collectMetrics(): array
    {
        try {
            $metrics = [];
            
            // Collect CPU metrics
            $metrics['cpu'] = $this->collectCpuMetrics();
            
            // Collect memory metrics
            $metrics['memory'] = $this->collectMemoryMetrics();
            
            // Collect disk metrics
            $metrics['disk'] = $this->collectDiskMetrics();
            
            // Collect network metrics
            $metrics['network'] = $this->collectNetworkMetrics();
            
            // Collect process metrics
            $metrics['processes'] = $this->collectProcessMetrics();
            
            // Collect application metrics
            $metrics['application'] = $this->collectApplicationMetrics();
            
            // Collect database metrics
            $metrics['database'] = $this->collectDatabaseMetrics();
            
            // Store metrics
            $this->storeMetrics($metrics);
            
            return $metrics;
        } catch (\Exception $e) {
            throw new \Exception("Metrics collection failed: " . $e->getMessage());
        }
    }

    /**
     * Collects CPU metrics.
     * 
     * @return array CPU metrics
     */
    private function collectCpuMetrics(): array
    {
        $metrics = [];
        
        // Get CPU usage percentage
        $cpuUsage = $this->getCpuUsage();
        $metrics['usage_percent'] = $cpuUsage;
        
        // Get CPU load average
        $loadAverage = $this->getLoadAverage();
        $metrics['load_average'] = $loadAverage;
        
        // Get CPU temperature
        $temperature = $this->getCpuTemperature();
        $metrics['temperature_celsius'] = $temperature;
        
        // Get CPU frequency
        $frequency = $this->getCpuFrequency();
        $metrics['frequency_mhz'] = $frequency;
        
        return $metrics;
    }

    /**
     * Collects memory metrics.
     * 
     * @return array Memory metrics
     */
    private function collectMemoryMetrics(): array
    {
        $metrics = [];
        
        // Get total memory
        $totalMemory = $this->getTotalMemory();
        $metrics['total_bytes'] = $totalMemory;
        
        // Get used memory
        $usedMemory = $this->getUsedMemory();
        $metrics['used_bytes'] = $usedMemory;
        
        // Get free memory
        $freeMemory = $this->getFreeMemory();
        $metrics['free_bytes'] = $freeMemory;
        
        // Get memory usage percentage
        $metrics['usage_percent'] = ($usedMemory / $totalMemory) * 100;
        
        // Get swap usage
        $swapUsage = $this->getSwapUsage();
        $metrics['swap_usage_bytes'] = $swapUsage['used'];
        $metrics['swap_total_bytes'] = $swapUsage['total'];
        $metrics['swap_usage_percent'] = ($swapUsage['used'] / $swapUsage['total']) * 100;
        
        return $metrics;
    }

    /**
     * Collects disk metrics.
     * 
     * @return array Disk metrics
     */
    private function collectDiskMetrics(): array
    {
        $metrics = [];
        
        // Get disk usage
        $diskUsage = $this->getDiskUsage();
        $metrics['total_bytes'] = $diskUsage['total'];
        $metrics['used_bytes'] = $diskUsage['used'];
        $metrics['free_bytes'] = $diskUsage['free'];
        $metrics['usage_percent'] = ($diskUsage['used'] / $diskUsage['total']) * 100;
        
        // Get disk I/O
        $diskIo = $this->getDiskIo();
        $metrics['read_bytes'] = $diskIo['read'];
        $metrics['write_bytes'] = $diskIo['write'];
        $metrics['read_operations'] = $diskIo['read_operations'];
        $metrics['write_operations'] = $diskIo['write_operations'];
        
        // Get disk I/O wait time
        $metrics['io_wait_percent'] = $this->getIoWaitPercentage();
        
        return $metrics;
    }

    /**
     * Collects network metrics.
     * 
     * @return array Network metrics
     */
    private function collectNetworkMetrics(): array
    {
        $metrics = [];
        
        // Get network interface statistics
        $interfaces = $this->getNetworkInterfaces();
        $metrics['interfaces'] = $interfaces;
        
        // Get network throughput
        $throughput = $this->getNetworkThroughput();
        $metrics['throughput_bytes'] = $throughput;
        
        // Get packet statistics
        $packets = $this->getPacketStatistics();
        $metrics['packets_in'] = $packets['in'];
        $metrics['packets_out'] = $packets['out'];
        $metrics['packets_errors'] = $packets['errors'];
        
        // Get connection statistics
        $connections = $this->getConnectionStatistics();
        $metrics['active_connections'] = $connections['active'];
        $metrics['total_connections'] = $connections['total'];
        
        return $metrics;
    }

    /**
     * Collects process metrics.
     * 
     * @return array Process metrics
     */
    private function collectProcessMetrics(): array
    {
        $metrics = [];
        
        // Get process count
        $metrics['total_processes'] = $this->getProcessCount();
        
        // Get zombie processes
        $metrics['zombie_processes'] = $this->getZombieProcessCount();
        
        // Get memory usage by process
        $processMemory = $this->getProcessMemoryUsage();
        $metrics['process_memory_usage'] = $processMemory;
        
        // Get CPU usage by process
        $processCpu = $this->getProcessCpuUsage();
        $metrics['process_cpu_usage'] = $processCpu;
        
        // Get running processes
        $metrics['running_processes'] = $this->getRunningProcessCount();
        
        return $metrics;
    }

    /**
     * Collects application metrics.
     * 
     * @return array Application metrics
     */
    private function collectApplicationMetrics(): array
    {
        $metrics = [];
        
        // Get application uptime
        $metrics['uptime_seconds'] = $this->getApplicationUptime();
        
        // Get response time
        $metrics['response_time_ms'] = $this->getApplicationResponseTime();
        
        // Get error rate
        $metrics['error_rate'] = $this->getApplicationErrorRate();
        
        // Get request count
        $metrics['request_count'] = $this->getRequestCount();
        
        // Get active sessions
        $metrics['active_sessions'] = $this->getActiveSessionCount();
        
        // Get cache hit rate
        $metrics['cache_hit_rate'] = $this->getCacheHitRate();
        
        return $metrics;
    }

    /**
     * Collects database metrics.
     * 
     * @return array Database metrics
     */
    private function collectDatabaseMetrics(): array
    {
        $metrics = [];
        
        // Get database connection count
        $metrics['active_connections'] = $this->getDatabaseConnectionCount();
        
        // Get query count
        $metrics['query_count'] = $this->getQueryCount();
        
        // Get slow query count
        $metrics['slow_query_count'] = $this->getSlowQueryCount();
        
        // Get database size
        $metrics['database_size_bytes'] = $this->getDatabaseSize();
        
        // Get table count
        $metrics['table_count'] = $this->getTableCount();
        
        // Get index usage
        $metrics['index_usage'] = $this->getIndexUsage();
        
        return $metrics;
    }

    /**
     * Stores metrics.
     * 
     * @param array $metrics Metrics to store
     */
    private function storeMetrics(array $metrics): void
    {
        $timestamp = time();
        
        foreach ($metrics as $category => $values) {
            $this->metrics[$timestamp][$category] = $values;
        }
        
        // Clean old metrics
        $this->cleanOldMetrics();
    }

    /**
     * Gets metrics by time range.
     * 
     * @param int $startTime Start timestamp
     * @param int $endTime End timestamp
     * @return array Metrics in time range
     */
    public function getMetricsByTimeRange(int $startTime, int $endTime): array
    {
        $metrics = [];
        
        foreach ($this->metrics as $timestamp => $data) {
            if ($timestamp >= $startTime && $timestamp <= $endTime) {
                $metrics[$timestamp] = $data;
            }
        }
        
        return $metrics;
    }

    /**
     * Gets current metrics.
     * 
     * @return array Current metrics
     */
    public function getCurrentMetrics(): array
    {
        if (empty($this->metrics)) {
            return $this->collectMetrics();
        }
        
        $latestTimestamp = max(array_keys($this->metrics));
        return $this->metrics[$latestTimestamp] ?? [];
    }

    /**
     * Sets monitoring thresholds.
     * 
     * @param array $thresholds Threshold configuration
     */
    public function setThresholds(array $thresholds): void
    {
        $this->thresholds = array_merge($this->thresholds, $thresholds);
    }

    /**
     * Checks thresholds and triggers alerts.
     * 
     * @param array $metrics Current metrics
     * @return array Alert results
     */
    public function checkThresholds(array $metrics): array
    {
        $alerts = [];
        
        foreach ($metrics as $category => $values) {
            if (isset($this->thresholds[$category])) {
                $categoryThresholds = $this->thresholds[$category];
                
                foreach ($values as $metric => $value) {
                    if (isset($categoryThresholds[$metric])) {
                        $threshold = $categoryThresholds[$metric];
                        
                        if ($value > $threshold['warning']) {
                            $alert = [
                                'metric' => "{$category}.{$metric}",
                                'value' => $value,
                                'threshold' => $threshold,
                                'severity' => $value > $threshold['critical'] ? 'critical' : 'warning',
                                'timestamp' => time(),
                                'message' => "{$metric} is {$value} (threshold: {$threshold['warning']})"
                            ];
                            
                            $alerts[] = $alert;
                            $this->triggerAlert($alert);
                        }
                    }
                }
            }
        }
        
        return $alerts;
    }

    /**
     * Triggers an alert.
     * 
     * @param array $alert Alert data
     */
    private function triggerAlert(array $alert): void
    {
        $this->alerts[] = $alert;
        
        // Log alert
        $this->logAlert($alert);
        
        // Send notifications if configured
        if ($this->config['alerts_enabled']) {
            $this->sendAlertNotification($alert);
        }
    }

    /**
     * Gets active alerts.
     * 
     * @return array Active alerts
     */
    public function getActiveAlerts(): array
    {
        return $this->alerts;
    }

    /**
     * Clears alerts.
     */
    public function clearAlerts(): void
    {
        $this->alerts = [];
    }

    /**
     * Generates monitoring report.
     * 
     * @param int $timeRange Time range in seconds
     * @return array Monitoring report
     */
    public function generateReport(int $timeRange = 3600): array
    {
        $endTime = time();
        $startTime = $endTime - $timeRange;
        
        $metrics = $this->getMetricsByTimeRange($startTime, $endTime);
        $alerts = $this->getActiveAlerts();
        
        $report = [
            'time_range' => [
                'start' => $startTime,
                'end' => $endTime,
                'duration_seconds' => $timeRange
            ],
            'metrics' => $metrics,
            'alerts' => $alerts,
            'summary' => $this->generateSummary($metrics, $alerts),
            'recommendations' => $this->generateRecommendations($metrics, $alerts)
        ];
        
        return $report;
    }

    /**
     * Gets system health status.
     * 
     * @return array System health status
     */
    public function getSystemHealth(): array
    {
        $metrics = $this->getCurrentMetrics();
        $alerts = $this->getActiveAlerts();
        
        $healthScore = $this->calculateHealthScore($metrics, $alerts);
        $status = $this->determineHealthStatus($healthScore, $alerts);
        
        return [
            'status' => $status,
            'score' => $healthScore,
            'metrics' => $metrics,
            'alerts' => $alerts,
            'last_updated' => time()
        ];
    }

    /**
     * Initializes default thresholds.
     */
    private function initializeThresholds(): void
    {
        $this->thresholds = [
            'cpu' => [
                'usage_percent' => ['warning' => 80, 'critical' => 95],
                'load_average' => ['warning' => 5, 'critical' => 10],
                'temperature_celsius' => ['warning' => 70, 'critical' => 85]
            ],
            'memory' => [
                'usage_percent' => ['warning' => 80, 'critical' => 95],
                'swap_usage_percent' => ['warning' => 50, 'critical' => 80]
            ],
            'disk' => [
                'usage_percent' => ['warning' => 80, 'critical' => 95],
                'io_wait_percent' => ['warning' => 20, 'critical' => 50]
            ],
            'network' => [
                'throughput_bytes' => ['warning' => 1000000000, 'critical' => 2000000000]
            ],
            'processes' => [
                'zombie_processes' => ['warning' => 5, 'critical' => 10]
            ],
            'application' => [
                'response_time_ms' => ['warning' => 500, 'critical' => 1000],
                'error_rate' => ['warning' => 0.05, 'critical' => 0.1]
            ],
            'database' => [
                'active_connections' => ['warning' => 100, 'critical' => 200],
                'slow_query_count' => ['warning' => 10, 'critical' => 50]
            ]
        ];
    }

    /**
     * CPU-related helper methods.
     */
    private function getCpuUsage(): float
    {
        // Get CPU usage from /proc/stat
        $stat1 = $this->readProcStat();
        sleep(1);
        $stat2 = $this->readProcStat();
        
        $usage = $this->calculateCpuUsage($stat1, $stat2);
        return $usage;
    }

    private function getLoadAverage(): array
    {
        $load = sys_getloadavg();
        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }

    private function getCpuTemperature(): float
    {
        // Read from /sys/class/thermal/thermal_zone0/temp
        if (file_exists('/sys/class/thermal/thermal_zone0/temp')) {
            $temp = file_get_contents('/sys/class/thermal/thermal_zone0/temp');
            return $temp / 1000;
        }
        return 0;
    }

    private function getCpuFrequency(): int
    {
        // Read from /proc/cpuinfo
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match('/cpu MHz\s*:\s*([\d.]+)/', $cpuinfo, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }

    private function readProcStat(): array
    {
        $stat = file_get_contents('/proc/stat');
        $lines = explode("\n", $stat);
        $cpuLine = $lines[0];
        
        $parts = explode(' ', $cpuLine);
        array_shift($parts); // Remove 'cpu'
        
        return array_map('intval', $parts);
    }

    private function calculateCpuUsage(array $stat1, array $stat2): float
    {
        $diff = array_map(function($a, $b) { return $b - $a; }, $stat1, $stat2);
        $total = array_sum($diff);
        $idle = $diff[3];
        
        if ($total == 0) {
            return 0;
        }
        
        return 100 - (($idle / $total) * 100);
    }

    /**
     * Memory-related helper methods.
     */
    private function getTotalMemory(): int
    {
        $meminfo = $this->readMeminfo();
        return isset($meminfo['MemTotal']) ? $meminfo['MemTotal'] * 1024 : 0;
    }

    private function getUsedMemory(): int
    {
        $meminfo = $this->readMeminfo();
        return isset($meminfo['MemTotal']) ? ($meminfo['MemTotal'] - $meminfo['MemFree']) * 1024 : 0;
    }

    private function getFreeMemory(): int
    {
        $meminfo = $this->readMeminfo();
        return isset($meminfo['MemFree']) ? $meminfo['MemFree'] * 1024 : 0;
    }

    private function getSwapUsage(): array
    {
        $meminfo = $this->readMeminfo();
        return [
            'total' => isset($meminfo['SwapTotal']) ? $meminfo['SwapTotal'] * 1024 : 0,
            'used' => isset($meminfo['SwapTotal']) ? ($meminfo['SwapTotal'] - $meminfo['SwapFree']) * 1024 : 0,
            'free' => isset($meminfo['SwapFree']) ? $meminfo['SwapFree'] * 1024 : 0
        ];
    }

    private function readMeminfo(): array
    {
        $meminfo = file_get_contents('/proc/meminfo');
        $lines = explode("\n", $meminfo);
        $result = [];
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = intval(trim($value));
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Disk-related helper methods.
     */
    private function getDiskUsage(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free
        ];
    }

    private function getDiskIo(): array
    {
        // Read from /proc/diskstats
        $diskstats = file_get_contents('/proc/diskstats');
        $lines = explode("\n", $diskstats);
        
        $totalRead = 0;
        $totalWrite = 0;
        $totalReadOps = 0;
        $totalWriteOps = 0;
        
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 13) {
                $totalRead += $parts[5] * 512;
                $totalWrite += $parts[9] * 512;
                $totalReadOps += $parts[3];
                $totalWriteOps += $parts[7];
            }
        }
        
        return [
            'read' => $totalRead,
            'write' => $totalWrite,
            'read_operations' => $totalReadOps,
            'write_operations' => $totalWriteOps
        ];
    }

    private function getIoWaitPercentage(): float
    {
        // Read from /proc/stat
        $stat = file_get_contents('/proc/stat');
        $lines = explode("\n", $stat);
        
        foreach ($lines as $line) {
            if (strpos($line, 'cpu') === 0) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 5) {
                    $total = array_sum(array_slice($parts, 1));
                    $idle = $parts[4];
                    return ($idle / $total) * 100;
                }
            }
        }
        
        return 0;
    }

    /**
     * Network-related helper methods.
     */
    private function getNetworkInterfaces(): array
    {
        $interfaces = [];
        
        $procNetDev = file_get_contents('/proc/net/dev');
        $lines = explode("\n", $procNetDev);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($interface, $stats) = explode(':', $line, 2);
                $interface = trim($interface);
                $stats = preg_split('/\s+/', trim($stats));
                
                $interfaces[$interface] = [
                    'bytes_received' => $stats[0],
                    'packets_received' => $stats[1],
                    'errors_received' => $stats[2],
                    'packets_dropped' => $stats[3]
                ];
            }
        }
        
        return $interfaces;
    }

    private function getNetworkThroughput(): array
    {
        $interfaces = $this->getNetworkInterfaces();
        $totalThroughput = 0;
        
        foreach ($interfaces as $interface) {
            $totalThroughput += $interface['bytes_received'] + $interface['bytes_transmitted'];
        }
        
        return $totalThroughput;
    }

    private function getPacketStatistics(): array
    {
        $interfaces = $this->getNetworkInterfaces();
        $totalIn = 0;
        $totalOut = 0;
        $totalErrors = 0;
        
        foreach ($interfaces as $interface) {
            $totalIn += $interface['packets_received'];
            $totalOut += $interface['packets_transmitted'];
            $totalErrors += $interface['errors_received'];
        }
        
        return [
            'in' => $totalIn,
            'out' => $totalOut,
            'errors' => $totalErrors
        ];
    }

    private function getConnectionStatistics(): array
    {
        // Count active connections
        $netstatOutput = shell_exec('netstat -an | grep ESTABLISHED | wc -l');
        $activeConnections = intval($netstatOutput);
        
        // Count total connections
        $totalConnections = shell_exec('netstat -an | wc -l');
        
        return [
            'active' => $activeConnections,
            'total' => intval($totalConnections)
        ];
    }

    /**
     * Process-related helper methods.
     */
    private function getProcessCount(): int
    {
        $processes = shell_exec('ps -e | wc -l');
        return intval($processes);
    }

    private function getZombieProcessCount(): int
    {
        $zombies = shell_exec('ps -e | grep Z | wc -l');
        return intval($zombies);
    }

    private function getProcessMemoryUsage(): array
    {
        $output = shell_exec('ps -eo pid,rss,comm --sort=-rss | head -10');
        $lines = explode("\n", $output);
        
        $processes = [];
        foreach ($lines as $line) {
            if (trim($line)) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    $processes[] = [
                        'pid' => $parts[0],
                        'rss' => $parts[1],
                        'command' => $parts[2]
                    ];
                }
            }
        }
        
        return $processes;
    }

    private function getProcessCpuUsage(): array
    {
        $output = shell_exec('ps -eo pid,pcpu,comm --sort=-pcpu | head -10');
        $lines = explode("\n", $output);
        
        $processes = [];
        foreach ($lines as $line) {
            if (trim($line)) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    $processes[] = [
                        'pid' => $parts[0],
                        'cpu_percent' => $parts[1],
                        'command' => $parts[2]
                    ];
                }
            }
        }
        
        return $processes;
    }

    private function getRunningProcessCount(): int
    {
        $output = shell_exec('ps -eo state | grep -c R');
        return intval($output);
    }

    /**
     * Application-related helper methods.
     */
    private function getApplicationUptime(): int
    {
        $uptime = shell_exec('ps -o etimes= -p $(cat /var/run/httpd.pid 2>/dev/null) 2>/dev/null');
        return intval($uptime) ?: 0;
    }

    private function getApplicationResponseTime(): float
    {
        // This would be implemented with actual HTTP requests
        return 100.0; // Mock response time
    }

    private function getApplicationErrorRate(): float
    {
        // This would be implemented with actual error tracking
        return 0.01; // Mock error rate
    }

    private function getRequestCount(): int
    {
        // This would be implemented with actual request tracking
        return 1000; // Mock request count
    }

    private function getActiveSessionCount(): int
    {
        // This would be implemented with actual session tracking
        return 100; // Mock session count
    }

    private function getCacheHitRate(): float
    {
        // This would be implemented with actual cache tracking
        return 0.8; // Mock cache hit rate
    }

    /**
     * Database-related helper methods.
     */
    private function getDatabaseConnectionCount(): int
    {
        // This would be implemented with actual database connection tracking
        return 50; // Mock connection count
    }

    private function getQueryCount(): int
    {
        // This would be implemented with actual query tracking
        return 10000; // Mock query count
    }

    private function getSlowQueryCount(): int
    {
        // This would be implemented with actual slow query tracking
        return 5; // Mock slow query count
    }

    private function getDatabaseSize(): int
    {
        // This would be implemented with actual database size tracking
        return 1000000000; // Mock database size
    }

    private function getTableCount(): int
    {
        // This would be implemented with actual table counting
        return 100; // Mock table count
    }

    private function getIndexUsage(): array
    {
        // This would be implemented with actual index usage tracking
        return [
            'used_indexes' => 80,
            'total_indexes' => 100,
            'usage_percent' => 80
        ];
    }

    /**
     * Utility methods.
     */
    private function cleanOldMetrics(): void
    {
        $cutoffTime = time() - ($this->config['metrics_retention_days'] * 24 * 60 * 60);
        
        foreach (array_keys($this->metrics) as $timestamp) {
            if ($timestamp < $cutoffTime) {
                unset($this->metrics[$timestamp]);
            }
        }
    }

    private function logAlert(array $alert): void
    {
        $logMessage = sprintf(
            "[%s] [%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            $alert['severity'],
            $alert['metric'],
            $alert['message']
        );
        
        file_put_contents($this->config['log_file'], $logMessage, FILE_APPEND);
    }

    private function sendAlertNotification(array $alert): void
    {
        // This would be implemented with actual alert notification system
        error_log("Alert triggered: " . json_encode($alert));
    }

    private function generateSummary(array $metrics, array $alerts): array
    {
        $summary = [
            'total_metrics' => count($metrics),
            'total_alerts' => count($alerts),
            'critical_alerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'critical')),
            'warning_alerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
            'categories' => array_keys($metrics)
        ];
        
        return $summary;
    }

    private function generateRecommendations(array $metrics, array $alerts): array
    {
        $recommendations = [];
        
        // Generate recommendations based on alerts
        foreach ($alerts as $alert) {
            $recommendations[] = [
                'category' => $alert['metric'],
                'priority' => $alert['severity'],
                'action' => "Investigate {$alert['metric']} which is at {$alert['value']}"
            ];
        }
        
        // Generate recommendations based on metrics
        if (isset($metrics['cpu']['usage_percent'])) {
            if ($metrics['cpu']['usage_percent'] > 80) {
                $recommendations[] = [
                    'category' => 'cpu',
                    'priority' => 'high',
                    'action' => 'Consider scaling up CPU resources or optimizing application'
                ];
            }
        }
        
        if (isset($metrics['memory']['usage_percent'])) {
            if ($metrics['memory']['usage_percent'] > 80) {
                $recommendations[] = [
                    'category' => 'memory',
                    'priority' => 'high',
                    'action' => 'Consider scaling up memory resources or optimizing memory usage'
                ];
            }
        }
        
        return $recommendations;
    }

    private function calculateHealthScore(array $metrics, array $alerts): int
    {
        $score = 100;
        
        // Deduct points for alerts
        foreach ($alerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $score -= 10;
            } else {
                $score -= 5;
            }
        }
        
        // Deduct points for resource usage
        if (isset($metrics['cpu']['usage_percent'])) {
            $score -= min(20, $metrics['cpu']['usage_percent'] / 5);
        }
        
        if (isset($metrics['memory']['usage_percent'])) {
            $score -= min(20, $metrics['memory']['usage_percent'] / 5);
        }
        
        return max(0, $score);
    }

    private function determineHealthStatus(int $score, array $alerts): string
    {
        if ($score >= 90) {
            return 'healthy';
        } elseif ($score >= 70) {
            return 'warning';
        } elseif ($score >= 50) {
            return 'critical';
        } else {
            return 'down';
        }
    }
}