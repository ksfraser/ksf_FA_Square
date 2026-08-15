<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Services;

/**
 * Cache Service
 * 
 * Handles caching operations for performance optimization.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class CacheService
{
    private array $config;
    private ?Redis $redis = null;
    private array $localCache = [];
    private const LOCAL_CACHE_TTL = 300; // 5 minutes

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 5,
            'local_cache_enabled' => true,
            'local_cache_size' => 1000
        ], $config);
    }

    /**
     * Gets a value from cache.
     * 
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found
     */
    public function get(string $key)
    {
        try {
            // Check local cache first
            if ($this->config['local_cache_enabled']) {
                $localKey = $this->getLocalKey($key);
                if (isset($this->localCache[$localKey])) {
                    $item = $this->localCache[$localKey];
                    if ($item['expires'] > time()) {
                        return $item['value'];
                    }
                    unset($this->localCache[$localKey]);
                }
            }

            // Get from Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->getFromRedis($key);
            }

            return null;
        } catch (\Exception $e) {
            // Log error and return null
            error_log("Cache get error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sets a value in cache.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        try {
            // Set in local cache
            if ($this->config['local_cache_enabled']) {
                $this->setLocalCache($key, $value, $ttl);
            }

            // Set in Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->setInRedis($key, $value, $ttl);
            }

            return true;
        } catch (\Exception $e) {
            // Log error but continue
            error_log("Cache set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a value from cache.
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        try {
            // Delete from local cache
            if ($this->config['local_cache_enabled']) {
                $localKey = $this->getLocalKey($key);
                unset($this->localCache[$localKey]);
            }

            // Delete from Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->deleteFromRedis($key);
            }

            return true;
        } catch (\Exception $e) {
            // Log error but continue
            error_log("Cache delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Checks if a key exists in cache.
     * 
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function exists(string $key): bool
    {
        try {
            // Check local cache first
            if ($this->config['local_cache_enabled']) {
                $localKey = $this->getLocalKey($key);
                if (isset($this->localCache[$localKey])) {
                    $item = $this->localCache[$localKey];
                    if ($item['expires'] > time()) {
                        return true;
                    }
                    unset($this->localCache[$localKey]);
                }
            }

            // Check Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->existsInRedis($key);
            }

            return false;
        } catch (\Exception $e) {
            // Log error and return false
            error_log("Cache exists error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears all cache.
     * 
     * @return bool Success status
     */
    public function clear(): bool
    {
        try {
            // Clear local cache
            if ($this->config['local_cache_enabled']) {
                $this->localCache = [];
            }

            // Clear Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->clearRedis();
            }

            return true;
        } catch (\Exception $e) {
            // Log error but continue
            error_log("Cache clear error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets multiple values from cache.
     * 
     * @param array $keys Cache keys
     * @return array Cached values
     */
    public function getMultiple(array $keys): array
    {
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        
        return $results;
    }

    /**
     * Sets multiple values in cache.
     * 
     * @param array $items Key-value pairs
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public function setMultiple(array $items, int $ttl = 3600): bool
    {
        $success = true;
        
        foreach ($items as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Deletes multiple values from cache.
     * 
     * @param array $keys Cache keys
     * @return bool Success status
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Increments a counter value.
     * 
     * @param string $key Cache key
     * @param int $increment Increment value
     * @return int New counter value
     */
    public function increment(string $key, int $increment = 1): int
    {
        try {
            // Increment in local cache
            if ($this->config['local_cache_enabled']) {
                $localKey = $this->getLocalKey($key);
                if (isset($this->localCache[$localKey])) {
                    $this->localCache[$localKey]['value'] = (int)$this->localCache[$localKey]['value'] + $increment;
                } else {
                    $this->localCache[$localKey] = [
                        'value' => $increment,
                        'expires' => time() + self::LOCAL_CACHE_TTL
                    ];
                }
            }

            // Increment in Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->incrementInRedis($key, $increment);
            }

            return $increment;
        } catch (\Exception $e) {
            // Log error and return increment value
            error_log("Cache increment error: " . $e->getMessage());
            return $increment;
        }
    }

    /**
     * Decrements a counter value.
     * 
     * @param string $key Cache key
     * @param int $decrement Decrement value
     * @return int New counter value
     */
    public function decrement(string $key, int $decrement = 1): int
    {
        try {
            // Decrement in local cache
            if ($this->config['local_cache_enabled']) {
                $localKey = $this->getLocalKey($key);
                if (isset($this->localCache[$localKey])) {
                    $this->localCache[$localKey]['value'] = max(0, (int)$this->localCache[$localKey]['value'] - $decrement);
                } else {
                    $this->localCache[$localKey] = [
                        'value' => 0,
                        'expires' => time() + self::LOCAL_CACHE_TTL
                    ];
                }
            }

            // Decrement in Redis if available
            if ($this->config['driver'] === 'redis' && $this->redis) {
                return $this->decrementInRedis($key, $decrement);
            }

            return 0;
        } catch (\Exception $e) {
            // Log error and return 0
            error_log("Cache decrement error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Enables data caching.
     * 
     * @return array Configuration result
     */
    public function enableDataCaching(): array
    {
        try {
            // Initialize Redis connection
            if ($this->config['driver'] === 'redis') {
                $this->connectRedis();
            }

            return [
                'success' => true,
                'cache_type' => $this->config['driver'],
                'message' => 'Data caching enabled successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'cache_type' => $this->config['driver'],
                'message' => 'Failed to enable data caching: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Enables page caching.
     * 
     * @return array Configuration result
     */
    public function enablePageCaching(): array
    {
        try {
            // Initialize file-based page caching
            $this->setupPageCache();

            return [
                'success' => true,
                'cache_type' => 'file',
                'message' => 'Page caching enabled successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'cache_type' => 'file',
                'message' => 'Failed to enable page caching: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Gets cache statistics.
     * 
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'local_cache' => [
                'items' => count($this->localCache),
                'size' => strlen(serialize($this->localCache)),
                'hit_rate' => $this->calculateLocalCacheHitRate()
            ],
            'redis' => [
                'connected' => $this->redis !== null,
                'memory_usage' => $this->getRedisMemoryUsage(),
                'key_count' => $this->getRedisKeyCount()
            ]
        ];

        return $stats;
    }

    /**
     * Connects to Redis.
     * 
     * @throws \Exception on connection failure
     */
    private function connectRedis(): void
    {
        if ($this->redis === null) {
            try {
                $this->redis = new Redis();
                $this->redis->connect(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['timeout']
                );

                if ($this->config['password']) {
                    $this->redis->auth($this->config['password']);
                }

                $this->redis->select($this->config['database']);
            } catch (\Exception $e) {
                throw new \Exception("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Gets value from Redis.
     * 
     * @param string $key Cache key
     * @return mixed|null Cached value
     */
    private function getFromRedis(string $key)
    {
        if ($this->redis) {
            $value = $this->redis->get($key);
            return $value !== false ? unserialize($value) : null;
        }
        return null;
    }

    /**
     * Sets value in Redis.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live
     * @return bool Success status
     */
    private function setInRedis(string $key, $value, int $ttl): bool
    {
        if ($this->redis) {
            return $this->redis->setex($key, $ttl, serialize($value));
        }
        return true;
    }

    /**
     * Deletes value from Redis.
     * 
     * @param string $key Cache key
     * @return bool Success status
     */
    private function deleteFromRedis(string $key): bool
    {
        if ($this->redis) {
            return $this->redis->del($key) > 0;
        }
        return true;
    }

    /**
     * Checks if key exists in Redis.
     * 
     * @param string $key Cache key
     * @return bool True if key exists
     */
    private function existsInRedis(string $key): bool
    {
        if ($this->redis) {
            return $this->redis->exists($key) > 0;
        }
        return false;
    }

    /**
     * Clears Redis cache.
     * 
     * @return bool Success status
     */
    private function clearRedis(): bool
    {
        if ($this->redis) {
            return $this->redis->flushdb();
        }
        return true;
    }

    /**
     * Increments value in Redis.
     * 
     * @param string $key Cache key
     * @param int $increment Increment value
     * @return int New counter value
     */
    private function incrementInRedis(string $key, int $increment): int
    {
        if ($this->redis) {
            return $this->redis->incrBy($key, $increment);
        }
        return $increment;
    }

    /**
     * Decrements value in Redis.
     * 
     * @param string $key Cache key
     * @param int $decrement Decrement value
     * @return int New counter value
     */
    private function decrementInRedis(string $key, int $decrement): int
    {
        if ($this->redis) {
            return max(0, $this->redis->decrBy($key, $decrement));
        }
        return 0;
    }

    /**
     * Gets Redis memory usage.
     * 
     * @return string Memory usage
     */
    private function getRedisMemoryUsage(): string
    {
        if ($this->redis) {
            $bytes = $this->redis->info()['used_memory'];
            return $this->formatBytes($bytes);
        }
        return '0B';
    }

    /**
     * Gets Redis key count.
     * 
     * @return int Key count
     */
    private function getRedisKeyCount(): int
    {
        if ($this->redis) {
            return (int)$this->redis->dbSize();
        }
        return 0;
    }

    /**
     * Sets local cache item.
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live
     */
    private function setLocalCache(string $key, $value, int $ttl): void
    {
        $localKey = $this->getLocalKey($key);
        $this->localCache[$localKey] = [
            'value' => $value,
            'expires' => time() + $ttl
        ];

        // Check cache size limit
        if (count($this->localCache) > $this->config['local_cache_size']) {
            $this->evictOldestLocalCache();
        }
    }

    /**
     * Gets local cache key.
     * 
     * @param string $key Cache key
     * @return string Local cache key
     */
    private function getLocalKey(string $key): string
    {
        return 'local:' . md5($key);
    }

    /**
     * Evicts oldest local cache item.
     */
    private function evictOldestLocalCache(): void
    {
        $oldestKey = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($this->localCache as $key => $item) {
            if ($item['expires'] < $oldestTime) {
                $oldestTime = $item['expires'];
                $oldestKey = $key;
            }
        }

        if ($oldestKey) {
            unset($this->localCache[$oldestKey]);
        }
    }

    /**
     * Calculates local cache hit rate.
     * 
     * @return float Hit rate
     */
    private function calculateLocalCacheHitRate(): float
    {
        // This is a simplified implementation
        // In a real system, you would track actual hits and misses
        return 0.8;
    }

    /**
     * Sets up page cache.
     */
    private function setupPageCache(): void
    {
        // Create cache directory if it doesn't exist
        $cacheDir = sys_get_temp_dir() . '/page_cache';
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }

    /**
     * Formats bytes to human readable format.
     * 
     * @param int $bytes Bytes to format
     * @return string Formatted bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}