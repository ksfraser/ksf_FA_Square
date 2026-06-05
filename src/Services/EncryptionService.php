<?php
declare(strict_types=1);

/**
 * Encryption Service
 * 
 * Handles encryption and decryption operations.
 * 
 * @UML Note: Class diagram in ProjectDocs/UML.md
 */
class EncryptionService
{
    private array $config;
    private string $encryptionKey;
    private string $algorithm;
    private int $iterations;
    private const ALGORITHM = 'AES-256-CBC';
    const METHOD = 'AES-256-CBC';

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'algorithm' => self::ALGORITHM,
            'iterations' => 10000,
            'key_length' => 32,
            'iv_length' => 16,
            'hash_algorithm' => 'sha256'
        ], $config);
        
        $this->algorithm = $this->config['algorithm'];
        $this->iterations = $this->config['iterations'];
        
        // Initialize encryption key
        $this->initializeEncryptionKey();
    }

    /**
     * Encrypts data.
     * 
     * @param string $data Data to encrypt
     * @param string|null $key Optional encryption key
     * @return string Encrypted data
     * @throws \Exception on encryption failure
     */
    public function encrypt(string $data, ?string $key = null): string
    {
        try {
            $key = $key ?? $this->encryptionKey;
            
            // Generate initialization vector
            $iv = random_bytes($this->config['iv_length']);
            
            // Encrypt data
            $encrypted = openssl_encrypt(
                $data,
                $this->algorithm,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($encrypted === false) {
                throw new \Exception("Encryption failed: " . openssl_error_string());
            }
            
            // Combine IV and encrypted data
            $result = base64_encode($iv . $encrypted);
            
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Encryption failed: " . $e->getMessage());
        }
    }

    /**
     * Decrypts data.
     * 
     * @param string $encryptedData Encrypted data
     * @param string|null $key Optional encryption key
     * @return string Decrypted data
     * @throws \Exception on decryption failure
     */
    public function decrypt(string $encryptedData, ?string $key = null): string
    {
        try {
            $key = $key ?? $this->encryptionKey;
            
            // Decode the encrypted data
            $data = base64_decode($encryptedData);
            if ($data === false) {
                throw new \Exception("Invalid base64 input");
            }
            
            // Extract IV and encrypted data
            $iv = substr($data, 0, $this->config['iv_length']);
            $encrypted = substr($data, $this->config['iv_length']);
            
            if (empty($iv) || empty($encrypted)) {
                throw new \Exception("Invalid encrypted data format");
            }
            
            // Decrypt data
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->algorithm,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new \Exception("Decryption failed: " . openssl_error_string());
            }
            
            return $decrypted;
        } catch (\Exception $e) {
            throw new \Exception("Decryption failed: " . $e->getMessage());
        }
    }

    /**
     * Hashes a password.
     * 
     * @param string $password Password to hash
     * @param string|null $salt Optional salt
     * @return string Hashed password
     * @throws \Exception on hashing failure
     */
    public function hashPassword(string $password, ?string $salt = null): string
    {
        try {
            $salt = $salt ?? $this->generateSalt();
            
            // Generate hash using PBKDF2
            $hash = hash_pbkdf2(
                $this->config['hash_algorithm'],
                $password,
                $salt,
                $this->iterations,
                64,
                true
            );
            
            return base64_encode($hash);
        } catch (\Exception $e) {
            throw new \Exception("Password hashing failed: " . $e->getMessage());
        }
    }

    /**
     * Verifies a password against a hash.
     * 
     * @param string $password Password to verify
     * @param string $hash Hash to verify against
     * @param string|null $salt Optional salt
     * @return bool True if password matches hash
     */
    public function verifyPassword(string $password, string $hash, ?string $salt = null): bool
    {
        try {
            $salt = $salt ?? $this->extractSaltFromHash($hash);
            $newHash = $this->hashPassword($password, $salt);
            
            return hash_equals($hash, $newHash);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generates a random salt.
     * 
     * @param int $length Salt length
     * @return string Generated salt
     */
    public function generateSalt(int $length = 32): string
    {
        return random_bytes($length);
    }

    /**
     * Generates a random token.
     * 
     * @param int $length Token length
     * @return string Generated token
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Generates a secure random string.
     * 
     * @param int $length String length
     * @return string Generated random string
     */
    public function generateRandomString(int $length = 32): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        
        return $randomString;
    }

    /**
     * Encrypts a file.
     * 
     * @param string $filePath Path to file
     * @param string|null $outputPath Optional output path
     * @param string|null $key Optional encryption key
     * @return string Path to encrypted file
     * @throws \Exception on file encryption failure
     */
    public function encryptFile(string $filePath, ?string $outputPath = null, ?string $key = null): string
    {
        try {
            $key = $key ?? $this->encryptionKey;
            
            if (!file_exists($filePath)) {
                throw new \Exception("File not found: {$filePath}");
            }
            
            $outputPath = $outputPath ?? $filePath . '.encrypted';
            
            // Read file contents
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new \Exception("Failed to read file: {$filePath}");
            }
            
            // Encrypt contents
            $encrypted = $this->encrypt($contents, $key);
            
            // Write encrypted file
            $result = file_put_contents($outputPath, $encrypted);
            if ($result === false) {
                throw new \Exception("Failed to write encrypted file: {$outputPath}");
            }
            
            return $outputPath;
        } catch (\Exception $e) {
            throw new \Exception("File encryption failed: " . $e->getMessage());
        }
    }

    /**
     * Decrypts a file.
     * 
     * @param string $filePath Path to encrypted file
     * @param string|null $outputPath Optional output path
     * @param string|null $key Optional encryption key
     * @return string Path to decrypted file
     * @throws \Exception on file decryption failure
     */
    public function decryptFile(string $filePath, ?string $outputPath = null, ?string $key = null): string
    {
        try {
            $key = $key ?? $this->encryptionKey;
            
            if (!file_exists($filePath)) {
                throw new \Exception("File not found: {$filePath}");
            }
            
            $outputPath = $outputPath ?? $filePath . '.decrypted';
            
            // Read encrypted file contents
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                throw new \Exception("Failed to read encrypted file: {$filePath}");
            }
            
            // Decrypt contents
            $decrypted = $this->decrypt($contents, $key);
            
            // Write decrypted file
            $result = file_put_contents($outputPath, $decrypted);
            if ($result === false) {
                throw new \Exception("Failed to write decrypted file: {$outputPath}");
            }
            
            return $outputPath;
        } catch (\Exception $e) {
            throw new \Exception("File decryption failed: " . $e->getMessage());
        }
    }

    /**
     * Creates a secure hash.
     * 
     * @param string $data Data to hash
     * @param string|null $salt Optional salt
     * @return string Secure hash
     */
    public function createHash(string $data, ?string $salt = null): string
    {
        $salt = $salt ?? $this->generateSalt();
        $hash = hash_hmac($this->config['hash_algorithm'], $data, $salt);
        return $salt . ':' . $hash;
    }

    /**
     * Verifies a hash.
     * 
     * @param string $data Data to verify
     * @param string $hash Hash to verify against
     * @return bool True if hash is valid
     */
    public function verifyHash(string $data, string $hash): bool
    {
        try {
            $parts = explode(':', $hash, 2);
            if (count($parts) !== 2) {
                return false;
            }
            
            $salt = $parts[0];
            $expectedHash = $parts[1];
            
            $actualHash = hash_hmac($this->config['hash_algorithm'], $data, $salt);
            
            return hash_equals($expectedHash, $actualHash);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Encrypts data for secure transmission.
     * 
     * @param array $data Data to encrypt
     * @param string|null $key Optional encryption key
     * @return string Encrypted data
     */
    public function encryptForTransmission(array $data, ?string $key = null): string
    {
        try {
            // Convert array to JSON
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                throw new \Exception("Failed to encode data to JSON");
            }
            
            // Encrypt data
            $encrypted = $this->encrypt($jsonData, $key);
            
            // Add metadata
            $metadata = [
                'algorithm' => $this->algorithm,
                'timestamp' => time(),
                'version' => '1.0'
            ];
            
            // Combine metadata and encrypted data
            $result = json_encode($metadata) . '::' . $encrypted;
            
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Data encryption for transmission failed: " . $e->getMessage());
        }
    }

    /**
     * Decrypts data from secure transmission.
     * 
     * @param string $encryptedData Encrypted data
     * @param string|null $key Optional encryption key
     * @return array Decrypted data
     */
    public function decryptFromTransmission(string $encryptedData, ?string $key = null): array
    {
        try {
            // Split metadata and encrypted data
            $parts = explode('::', $encryptedData, 2);
            if (count($parts) !== 2) {
                throw new \Exception("Invalid encrypted data format");
            }
            
            $metadata = json_decode($parts[0], true);
            $encrypted = $parts[1];
            
            if ($metadata === null) {
                throw new \Exception("Invalid metadata format");
            }
            
            // Decrypt data
            $decrypted = $this->decrypt($encrypted, $key);
            
            // Convert JSON to array
            $data = json_decode($decrypted, true);
            if ($data === null) {
                throw new \Exception("Failed to decode decrypted data from JSON");
            }
            
            return $data;
        } catch (\Exception $e) {
            throw new \Exception("Data decryption from transmission failed: " . $e->getMessage());
        }
    }

    /**
     * Rotates encryption keys.
     * 
     * @return array Key rotation results
     */
    public function rotateEncryptionKeys(): array
    {
        try {
            $oldKey = $this->encryptionKey;
            $newKey = $this->generateEncryptionKey();
            
            // Store old key for potential decryption
            $this->storeOldKey($oldKey);
            
            // Update encryption key
            $this->encryptionKey = $newKey;
            
            return [
                'success' => true,
                'old_key' => $oldKey,
                'new_key' => $newKey,
                'rotated_at' => time()
            ];
        } catch (\Exception $e) {
            throw new \Exception("Key rotation failed: " . $e->getMessage());
        }
    }

    /**
     * Gets encryption algorithm information.
     * 
     * @return array Algorithm information
     */
    public function getAlgorithmInfo(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'key_length' => $this->config['key_length'],
            'iv_length' => $this->config['iv_length'],
            'iterations' => $this->iterations,
            'hash_algorithm' => $this->config['hash_algorithm']
        ];
    }

    /**
     * Validates encryption configuration.
     * 
     * @return array Validation results
     */
    public function validateConfiguration(): array
    {
        $results = [];
        
        try {
            // Test encryption and decryption
            $testData = "Test encryption data";
            $encrypted = $this->encrypt($testData);
            $decrypted = $this->decrypt($encrypted);
            
            $results['encryption'] = [
                'valid' => $testData === $decrypted,
                'test_data' => $testData,
                'encrypted' => $encrypted,
                'decrypted' => $decrypted
            ];
            
            // Test password hashing
            $password = "test_password";
            $hash = $this->hashPassword($password);
            $verified = $this->verifyPassword($password, $hash);
            
            $results['password_hashing'] = [
                'valid' => $verified,
                'password' => $password,
                'hash' => $hash
            ];
            
            // Test hash verification
            $data = "test_data";
            $hash = $this->createHash($data);
            $verified = $this->verifyHash($data, $hash);
            
            $results['hash_verification'] = [
                'valid' => $verified,
                'data' => $data,
                'hash' => $hash
            ];
            
            $results['overall'] = [
                'valid' => $results['encryption']['valid'] && 
                          $results['password_hashing']['valid'] && 
                          $results['hash_verification']['valid'],
                'message' => $results['overall']['valid'] ? 'All tests passed' : 'Some tests failed'
            ];
            
        } catch (\Exception $e) {
            $results['overall'] = [
                'valid' => false,
                'message' => 'Configuration validation failed: ' . $e->getMessage()
            ];
        }
        
        return $results;
    }

    /**
     * Initializes encryption key.
     * 
     * @throws \Exception on key initialization failure
     */
    private function initializeEncryptionKey(): void
    {
        try {
            $this->encryptionKey = $this->generateEncryptionKey();
        } catch (\Exception $e) {
            throw new \Exception("Failed to initialize encryption key: " . $e->getMessage());
        }
    }

    /**
     * Generates encryption key.
     * 
     * @return string Generated encryption key
     * @throws \Exception on key generation failure
     */
    private function generateEncryptionKey(): string
    {
        try {
            return random_bytes($this->config['key_length']);
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate encryption key: " . $e->getMessage());
        }
    }

    /**
     * Extracts salt from hash.
     * 
     * @param string $hash Hash to extract salt from
     * @return string Extracted salt
     */
    private function extractSaltFromHash(string $hash): string
    {
        // This is a simplified implementation
        // In a real system, you would need to know the exact format
        return substr($hash, 0, 32);
    }

    /**
     * Stores old encryption key.
     * 
     * @param string $oldKey Old encryption key
     */
    private function storeOldKey(string $oldKey): void
    {
        // In a real system, you would store this securely
        // For now, we'll just store it in memory
        $this->oldKeys[] = $oldKey;
    }

    /**
     * Gets current encryption key.
     * 
     * @return string Current encryption key
     */
    public function getEncryptionKey(): string
    {
        return $this->encryptionKey;
    }

    /**
     * Sets encryption key.
     * 
     * @param string $key Encryption key to set
     */
    public function setEncryptionKey(string $key): void
    {
        $this->encryptionKey = $key;
    }

    /**
     * Gets encryption algorithm.
     * 
     * @return string Encryption algorithm
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Sets encryption algorithm.
     * 
     * @param string $algorithm Encryption algorithm to set
     */
    public function setAlgorithm(string $algorithm): void
    {
        $this->algorithm = $algorithm;
    }

    /**
     * Gets encryption iterations.
     * 
     * @return int Encryption iterations
     */
    public function getIterations(): int
    {
        return $this->iterations;
    }

    /**
     * Sets encryption iterations.
     * 
     * @param int $iterations Encryption iterations to set
     */
    public function setIterations(int $iterations): void
    {
        $this->iterations = $iterations;
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
}