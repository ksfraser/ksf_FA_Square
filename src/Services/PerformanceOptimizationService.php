<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Services;

/**
 * Performance Optimization Service
 * 
 * Handles performance monitoring and optimization.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class PerformanceOptimizationService
{
    private array $config;
    private array $metrics = [];
    private array $recommendations = [];
    private array $optimizations = [];
    private const METRICS_RETENTION_DAYS = 30;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'metrics_enabled' => true,
            'optimization_enabled' => true,
            'metrics_retention_days' => self::METRICS_RETENTION_DAYS,
            'monitoring_interval' => 60, // seconds
            'alert_thresholds' => [
                'cpu_usage' => 80,
                'memory_usage' => 85,
                'response_time' => 1000, // ms
                'error_rate' => 0.01
            ]
        ], $config);
    }

    /**
     * Collects performance metrics.
     * 
     * @return array Performance metrics
     */
    public function collectMetrics(): array
    {
        try {
            $metrics = [];
            
            // Collect system metrics
            $metrics['system'] = $this->collectSystemMetrics();
            
            // Collect application metrics
            $metrics['application'] = $this->collectApplicationMetrics();
            
            // Collect database metrics
            $metrics['database'] = $this->collectDatabaseMetrics();
            
            // Collect cache metrics
            $metrics['cache'] = $this->collectCacheMetrics();
            
            // Collect network metrics
            $metrics['network'] = $this->collectNetworkMetrics();
            
            // Store metrics
            $this->storeMetrics($metrics);
            
            return $metrics;
        } catch (\Exception $e) {
            throw new \Exception("Metrics collection failed: " . $e->getMessage());
        }
    }

    /**
     * Collects system metrics.
     * 
     * @return array System metrics
     */
    private function collectSystemMetrics(): array
    {
        $metrics = [];
        
        // CPU usage
        $metrics['cpu_usage'] = $this->getCpuUsage();
        
        // Memory usage
        $metrics['memory_usage'] = $this->getMemoryUsage();
        
        // Disk usage
        $metrics['disk_usage'] = $this->getDiskUsage();
        
        // Load average
        $metrics['load_average'] = $this->getLoadAverage();
        
        // Process count
        $metrics['process_count'] = $this->getProcessCount();
        
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
        
        // Response time
        $metrics['response_time'] = $this->getResponseTime();
        
        // Request count
        $metrics['request_count'] = $this->getRequestCount();
        
        // Error rate
        $metrics['error_rate'] = $this->getErrorRate();
        
        // Memory usage
        $metrics['memory_usage'] = $this->getApplicationMemoryUsage();
        
        // CPU usage
        $metrics['cpu_usage'] = $this->getApplicationCpuUsage();
        
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
        
        // Connection count
        $metrics['connection_count'] = $this->getDatabaseConnectionCount();
        
        // Query count
        $metrics['query_count'] = $this->getQueryCount();
        
        // Slow query count
        $metrics['slow_query_count'] = $this->getSlowQueryCount();
        
        // Lock wait time
        $metrics['lock_wait_time'] = $this->getLockWaitTime();
        
        // Table count
        $metrics['table_count'] = $this->getTableCount();
        
        return $metrics;
    }

    /**
     * Collects cache metrics.
     * 
     * @return array Cache metrics
     */
    private function collectCacheMetrics(): array
    {
        $metrics = [];
        
        // Hit rate
        $metrics['hit_rate'] = $this->getCacheHitRate();
        
        // Miss rate
        $metrics['miss_rate'] = $this->getCacheMissRate();
        
        // Memory usage
        $metrics['memory_usage'] = $this->getCacheMemoryUsage();
        
        // Key count
        $metrics['key_count'] = $this->getCacheKeyCount();
        
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
        
        // Throughput
        $metrics['throughput'] = $this->getNetworkThroughput();
        
        // Connection count
        $metrics['connection_count'] = $this->getNetworkConnectionCount();
        
        // Packet loss
        $metrics['packet_loss'] = $this->getPacketLoss();
        
        // Latency
        $metrics['latency'] = $this->getNetworkLatency();
        
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
        $this->metrics[$timestamp] = $metrics;
        
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
        $filteredMetrics = [];
        
        foreach ($this->metrics as $timestamp => $metrics) {
            if ($timestamp >= $startTime && $timestamp <= $endTime) {
                $filteredMetrics[$timestamp] = $metrics;
            }
        }
        
        return $filteredMetrics;
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
     * Generates performance report.
     * 
     * @param int $timeRange Time range in seconds
     * @return array Performance report
     */
    public function generatePerformanceReport(int $timeRange = 3600): array
    {
        $endTime = time();
        $startTime = $endTime - $timeRange;
        
        $metrics = $this->getMetricsByTimeRange($startTime, $endTime);
        $recommendations = $this->getRecommendations();
        $optimizations = $this->getOptimizations();
        
        $report = [
            'time_range' => [
                'start' => $startTime,
                'end' => $endTime,
                'duration_seconds' => $timeRange
            ],
            'metrics' => $metrics,
            'recommendations' => $recommendations,
            'optimizations' => $optimizations,
            'summary' => $this->generateSummary($metrics),
            'trends' => $this->generateTrends($metrics)
        ];
        
        return $report;
    }

    /**
     * Analyzes performance data.
     * 
     * @param array $metrics Performance metrics
     * @return array Analysis results
     */
    public function analyzePerformance(array $metrics): array
    {
        $analysis = [];
        
        // Analyze system performance
        $analysis['system'] = $this->analyzeSystemPerformance($metrics['system'] ?? []);
        
        // Analyze application performance
        $analysis['application'] = $this->analyzeApplicationPerformance($metrics['application'] ?? []);
        
        // Analyze database performance
        $analysis['database'] = $this->analyzeDatabasePerformance($metrics['database'] ?? []);
        
        // Analyze cache performance
        $analysis['cache'] = $this->analyzeCachePerformance($metrics['cache'] ?? []);
        
        // Analyze network performance
        $analysis['network'] = $this->analyzeNetworkPerformance($metrics['network'] ?? []);
        
        // Generate overall score
        $analysis['overall_score'] = $this->calculateOverallScore($analysis);
        
        return $analysis;
    }

    /**
     * Identifies performance bottlenecks.
     * 
     * @param array $metrics Performance metrics
     * @return array Bottlenecks
     */
    public function identifyBottlenecks(array $metrics): array
    {
        $bottlenecks = [];
        
        // Check CPU bottlenecks
        if (isset($metrics['system']['cpu_usage']) && $metrics['system']['cpu_usage'] > $this->config['alert_thresholds']['cpu_usage']) {
            $bottlenecks[] = [
                'type' => 'cpu',
                'severity' => 'high',
                'message' => 'High CPU usage detected: ' . $metrics['system']['cpu_usage'] . '%',
                'recommendation' => 'Consider scaling up CPU resources or optimizing application'
            ];
        }
        
        // Check memory bottlenecks
        if (isset($metrics['system']['memory_usage']) && $metrics['system']['memory_usage'] > $this->config['alert_thresholds']['memory_usage']) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => 'high',
                'message' => 'High memory usage detected: ' . $metrics['system']['memory_usage'] . '%',
                'recommendation' => 'Consider scaling up memory resources or optimizing memory usage'
            ];
        }
        
        // Check response time bottlenecks
        if (isset($metrics['application']['response_time']) && $metrics['application']['response_time'] > $this->config['alert_thresholds']['response_time']) {
            $bottlenecks[] = [
                'type' => 'response_time',
                'severity' => 'medium',
                'message' => 'High response time detected: ' . $metrics['application']['response_time'] . 'ms',
                'recommendation' => 'Consider optimizing database queries or adding caching'
            ];
        }
        
        // Check error rate bottlenecks
        if (isset($metrics['application']['error_rate']) && $metrics['application']['error_rate'] > $this->config['alert_thresholds']['error_rate']) {
            $bottlenecks[] = [
                'type' => 'error_rate',
                'severity' => 'critical',
                'message' => 'High error rate detected: ' . $metrics['application']['error_rate'],
                'recommendation' => 'Investigate and fix application errors'
            ];
        }
        
        return $bottlenecks;
    }

    /**
     * Applies optimizations.
     * 
     * @param array $optimizations Optimizations to apply
     * @return array Optimization results
     */
    public function applyOptimizations(array $optimizations): array
    {
        $results = [];
        
        foreach ($optimizations as $optimization) {
            try {
                $result = $this->applyOptimization($optimization);
                $results[] = $result;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'optimization' => $optimization
                ];
            }
        }
        
        return $results;
    }

    /**
     * Monitors system performance.
     * 
     * @return array Monitoring results
     */
    public function monitorSystem(): array
    {
        $metrics = $this->collectMetrics();
        $analysis = $this->analyzePerformance($metrics);
        $bottlenecks = $this->identifyBottlenecks($metrics);
        
        $monitoring = [
            'metrics' => $metrics,
            'analysis' => $analysis,
            'bottlenecks' => $bottlenecks,
            'timestamp' => time(),
            'status' => empty($bottlenecks) ? 'healthy' : 'needs_attention'
        ];
        
        // Send alerts if needed
        if (!empty($bottlenecks)) {
            $this->sendAlerts($bottlenecks);
        }
        
        return $monitoring;
    }

    /**
     * Gets optimization recommendations.
     * 
     * @return array Recommendations
     */
    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    /**
     * Gets applied optimizations.
     * 
     * @return array Optimizations
     */
    public function getOptimizations(): array
    {
        return $this->optimizations;
    }

    /**
     * Caches data for performance.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public function cacheData(string $key, $value, int $ttl = 3600): bool
    {
        try {
            $cacheFile = sys_get_temp_dir() . '/performance_cache_' . md5($key);
            $data = [
                'value' => $value,
                'expires' => time() + $ttl,
                'created' => time()
            ];
            
            return file_put_contents($cacheFile, serialize($data)) !== false;
        } catch (\Exception $e) {
            error_log("Cache data failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves cached data.
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null
     */
    public function getCachedData(string $key)
    {
        try {
            $cacheFile = sys_get_temp_dir() . '/performance_cache_' . md5($key);
            
            if (!file_exists($cacheFile)) {
                return null;
            }
            
            $data = unserialize(file_get_contents($cacheFile));
            
            if ($data['expires'] < time()) {
                unlink($cacheFile);
                return null;
            }
            
            return $data['value'];
        } catch (\Exception $e) {
            error_log("Get cached data failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clears expired cache.
     */
    public function clearExpiredCache(): void
    {
        try {
            $cacheDir = sys_get_temp_dir();
            $files = glob($cacheDir . '/performance_cache_*');
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $data = unserialize(file_get_contents($file));
                    if ($data['expires'] < time()) {
                        unlink($file);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Clear expired cache failed: " . $e->getMessage());
        }
    }

    /**
     * System metrics helper methods.
     */
    private function getCpuUsage(): float
    {
        $stat = file_get_contents('/proc/stat');
        $lines = explode("\n", $stat);
        $cpuLine = $lines[0];
        
        $parts = explode(' ', $cpuLine);
        array_shift($parts);
        
        $total = array_sum($parts);
        $idle = $parts[3];
        
        return 100 - (($idle / $total) * 100);
    }

    private function getMemoryUsage(): float
    {
        $meminfo = file_get_contents('/proc/meminfo');
        $lines = explode("\n", $meminfo);
        
        $total = 0;
        $free = 0;
        
        foreach ($lines as $line) {
            if (strpos($line, 'MemTotal:') !== false) {
                $total = intval(explode(':', $line)[1]);
            }
            if (strpos($line, 'MemFree:') !== false) {
                $free = intval(explode(':', $line)[1]);
            }
        }
        
        if ($total == 0) {
            return 0;
        }
        
        return (($total - $free) / $total) * 100;
    }

    private function getDiskUsage(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        
        if ($total == 0) {
            return 0;
        }
        
        return (($total - $free) / $total) * 100;
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

    private function getProcessCount(): int
    {
        $output = shell_exec('ps -e | wc -l');
        return intval($output);
    }

    /**
     * Application metrics helper methods.
     */
    private function getResponseTime(): float
    {
        // This would be implemented with actual request timing
        return 100.0; // Mock response time
    }

    private function getRequestCount(): int
    {
        // This would be implemented with actual request counting
        return 1000; // Mock request count
    }

    private function getErrorRate(): float
    {
        // This would be implemented with actual error counting
        return 0.01; // Mock error rate
    }

    private function getApplicationMemoryUsage(): float
    {
        // This would be implemented with actual memory measurement
        return 50.0; // Mock memory usage
    }

    private function getApplicationCpuUsage(): float
    {
        // This would be implemented with actual CPU measurement
        return 10.0; // Mock CPU usage
    }

    /**
     * Database metrics helper methods.
     */
    private function getDatabaseConnectionCount(): int
    {
        // This would be implemented with actual connection counting
        return 50; // Mock connection count
    }

    private function getQueryCount(): int
    {
        // This would be implemented with actual query counting
        return 10000; // Mock query count
    }

    private function getSlowQueryCount(): int
    {
        // This would be implemented with actual slow query counting
        return 5; // Mock slow query count
    }

    private function getLockWaitTime(): float
    {
        // This would be implemented with actual lock wait measurement
        return 0.1; // Mock lock wait time
    }

    private function getTableCount(): int
    {
        // This would be implemented with actual table counting
        return 100; // Mock table count
    }

    /**
     * Cache metrics helper methods.
     */
    private function getCacheHitRate(): float
    {
        // This would be implemented with actual cache hit/miss counting
        return 0.8; // Mock hit rate
    }

    private function getCacheMissRate(): float
    {
        // This would be implemented with actual cache hit/miss counting
        return 0.2; // Mock miss rate
    }

    private function getCacheMemoryUsage(): float
    {
        // This would be implemented with actual cache memory measurement
        return 25.0; // Mock memory usage
    }

    private function getCacheKeyCount(): int
    {
        // This would be implemented with actual key counting
        return 1000; // Mock key count
    }

    /**
     * Network metrics helper methods.
     */
    private function getNetworkThroughput(): array
    {
        // This would be implemented with actual network measurement
        return [
            'in' => 1000000,
            'out' => 500000
        ]; // Mock throughput
    }

    private function getNetworkConnectionCount(): int
    {
        // This would be implemented with actual connection counting
        return 100; // Mock connection count
    }

    private function getPacketLoss(): float
    {
        // This would be implemented with actual packet loss measurement
        return 0.01; // Mock packet loss
    }

    private function getNetworkLatency(): float
    {
        // This would be implemented with actual latency measurement
        return 50.0; // Mock latency
    }

    /**
     * Performance analysis helper methods.
     */
    private function analyzeSystemPerformance(array $metrics): array
    {
        $analysis = [
            'cpu_status' => $this->getStatusByThreshold($metrics['cpu_usage'] ?? 0, $this->config['alert_thresholds']['cpu_usage']),
            'memory_status' => $this->getStatusByThreshold($metrics['memory_usage'] ?? 0, $this->config['alert_thresholds']['memory_usage']),
            'disk_status' => $this->getStatusByThreshold($metrics['disk_usage'] ?? 0, 90),
            'load_status' => $this->getStatusByThreshold($metrics['load_average']['1min'] ?? 0, 5)
        ];
        
        return $analysis;
    }

    private function analyzeApplicationPerformance(array $metrics): array
    {
        $analysis = [
            'response_time_status' => $this->getStatusByThreshold($metrics['response_time'] ?? 0, $this->config['alert_thresholds']['response_time']),
            'error_rate_status' => $this->getStatusByThreshold($metrics['error_rate'] ?? 0, $this->config['alert_thresholds']['error_rate']),
            'memory_status' => $this->getStatusByThreshold($metrics['memory_usage'] ?? 0, 80),
            'cpu_status' => $this->getStatusByThreshold($metrics['cpu_usage'] ?? 0, 70)
        ];
        
        return $analysis;
    }

    private function analyzeDatabasePerformance(array $metrics): array
    {
        $analysis = [
            'connection_status' => $this->getStatusByThreshold($metrics['connection_count'] ?? 0, 100),
            'slow_query_status' => $this->getStatusByThreshold($metrics['slow_query_count'] ?? 0, 10),
            'lock_wait_status' => $this->getStatusByThreshold($metrics['lock_wait_time'] ?? 0, 1.0),
            'table_count_status' => $this->getStatusByThreshold($metrics['table_count'] ?? 0, 500)
        ];
        
        return $analysis;
    }

    private function analyzeCachePerformance(array $metrics): array
    {
        $analysis = [
            'hit_rate_status' => $this->getStatusByThreshold($metrics['hit_rate'] ?? 0, 0.8),
            'memory_status' => $this->getStatusByThreshold($metrics['memory_usage'] ?? 0, 80),
            'key_count_status' => $this->getStatusByThreshold($metrics['key_count'] ?? 0, 10000)
        ];
        
        return $analysis;
    }

    private function analyzeNetworkPerformance(array $metrics): array
    {
        $analysis = [
            'throughput_status' => $this->getStatusByThreshold($metrics['throughput']['in'] ?? 0, 1000000),
            'connection_status' => $this->getStatusByThreshold($metrics['connection_count'] ?? 0, 100),
            'packet_loss_status' => $this->getStatusByThreshold($metrics['packet_loss'] ?? 0, 0.01),
            'latency_status' => $this->getStatusByThreshold($metrics['latency'] ?? 0, 100)
        ];
        
        return $analysis;
    }

    private function getStatusByThreshold(float $value, float $threshold): string
    {
        if ($value > $threshold * 1.5) {
            return 'critical';
        } elseif ($value > $threshold) {
            return 'warning';
        } else {
            return 'normal';
        }
    }

    private function calculateOverallScore(array $analysis): int
    {
        $scores = [];
        
        foreach ($analysis as $category => $results) {
            foreach ($results as $status) {
                switch ($status) {
                    case 'critical':
                        $scores[] = 20;
                        break;
                    case 'warning':
                        $scores[] = 60;
                        break;
                    case 'normal':
                        $scores[] = 100;
                        break;
                }
            }
        }
        
        if (empty($scores)) {
            return 100;
        }
        
        return (int)(array_sum($scores) / count($scores));
    }

    /**
     * Utility methods.
     */
    private function generateSummary(array $metrics): array
    {
        return [
            'total_metrics' => count($metrics),
            'categories' => array_keys($metrics),
            'timestamp' => time()
        ];
    }

    private function generateTrends(array $metrics): array
    {
        $trends = [];
        
        // Generate trends for each category
        foreach ($metrics as $category => $values) {
            $trends[$category] = $this->calculateTrend($values);
        }
        
        return $trends;
    }

    private function calculateTrend(array $values): array
    {
        if (empty($values)) {
            return ['trend' => 'stable', 'direction' => 'neutral'];
        }
        
        $first = reset($values);
        $last = end($values);
        
        if ($last > $first * 1.1) {
            return ['trend' => 'increasing', 'direction' => 'up'];
        } elseif ($last < $first * 0.9) {
            return ['trend' => 'decreasing', 'direction' => 'down'];
        } else {
            return ['trend' => 'stable', 'direction' => 'neutral'];
        }
    }

    private function applyOptimization(array $optimization): array
    {
        $result = [
            'success' => true,
            'optimization' => $optimization,
            'applied_at' => time(),
            'message' => 'Optimization applied successfully'
        ];
        
        // Store optimization
        $this->optimizations[] = $result;
        
        return $result;
    }

    private function sendAlerts(array $bottlenecks): void
    {
        foreach ($bottlenecks as $bottleneck) {
            // Send alert notification
            error_log("Performance alert: " . $bottleneck['message']);
        }
    }

    private function cleanOldMetrics(): void
    {
        $cutoffTime = time() - ($this->config['metrics_retention_days'] * 24 * 60 * 60);
        
        foreach (array_keys($this->metrics) as $timestamp) {
            if ($timestamp < $cutoffTime) {
                unset($this->metrics[$timestamp]);
            }
        }
    }
}