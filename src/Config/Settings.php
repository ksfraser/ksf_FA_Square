<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Config;

use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;

class Settings implements SettingsInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'access_token' => null,
            'sandbox_access_token' => null,
            'production_access_token' => null,
            'environment' => 'sandbox',
            'last_import_date' => null,
            'destination_customer' => null,
            'default_location' => null,
        ], $config);
    }

    public static function fromFADatabase(string $tablePrefix): self
    {
        $config = [];

        $result = db_query("SELECT `name`, `value` FROM {$tablePrefix}square");
        if ($result !== false) {
            while ($row = db_fetch($result)) {
                $map = [
                    'access_token' => 'access_token',
                    'sandbox_access_token' => 'sandbox_access_token',
                    'sandbox_access_' => 'sandbox_access_token',
                    'production_access_token' => 'production_access_token',
                    'production_acce' => 'production_access_token',
                    'lastdate' => 'last_import_date',
                    'destCust' => 'destination_customer',
                ];

                $configKey = $map[$row['name']] ?? $row['name'];
                $config[$configKey] = $row['value'];
            }
        }

        return new self($config);
    }

    public function getAccessToken(): ?string
    {
        $env = $this->getEnvironment();

        if ($env === 'production') {
            return $this->getProductionAccessToken() ?? $this->config['access_token'] ?? null;
        }

        return $this->getSandboxAccessToken() ?? $this->config['access_token'] ?? null;
    }

    public function setAccessToken(string $token): void
    {
        $this->config['access_token'] = $token;
    }

    public function getSandboxAccessToken(): ?string
    {
        return $this->config['sandbox_access_token'] ?? null;
    }

    public function setSandboxAccessToken(string $token): void
    {
        $this->config['sandbox_access_token'] = $token;
    }

    public function getProductionAccessToken(): ?string
    {
        return $this->config['production_access_token'] ?? null;
    }

    public function setProductionAccessToken(string $token): void
    {
        $this->config['production_access_token'] = $token;
    }

    public function getEnvironment(): string
    {
        return $this->config['environment'] ?? 'sandbox';
    }

    public function setEnvironment(string $env): void
    {
        $this->config['environment'] = $env;
    }

    public function getLastImportDate(): ?\DateTimeInterface
    {
        $date = $this->config['last_import_date'] ?? null;
        if ($date === null) {
            return null;
        }
        if ($date instanceof \DateTimeInterface) {
            return $date;
        }
        return new \DateTimeImmutable($date);
    }

    public function setLastImportDate(\DateTimeInterface $date): void
    {
        $this->config['last_import_date'] = $date->format('Y-m-d H:i:s');
    }

    public function getDestinationCustomer(): ?int
    {
        $dest = $this->config['destination_customer'] ?? null;
        return $dest !== null ? (int)$dest : null;
    }

    public function setDestinationCustomer(int $debtorNo): void
    {
        $this->config['destination_customer'] = $debtorNo;
    }

    public function getDefaultLocation(): ?string
    {
        return $this->config['default_location'] ?? null;
    }

    public function setDefaultLocation(string $locationId): void
    {
        $this->config['default_location'] = $locationId;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Saves a configuration key-value pair to the database.
     * 
     * @param string $tablePrefix Database table prefix
     * @param string $name Configuration name
     * @param mixed $value Configuration value
     * @return void
     * @throws Exception if query fails
     */
    public static function saveToDatabase(string $tablePrefix, string $name, $value): void
    {
        $table = $tablePrefix . 'square';
        $escapedName = db_escape($name);
        $escapedValue = db_escape((string)$value);

        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = {$escapedName}";
        $result = db_query($sql);
        if ($result !== false) {
            $row = db_fetch_assoc($result);
            if ((int)$row['cnt'] > 0) {
                $sql = "UPDATE {$table} SET value = {$escapedValue} WHERE name = {$escapedName}";
            } else {
                $sql = "INSERT INTO {$table} (name, value) VALUES ({$escapedName}, {$escapedValue})";
            }
            db_query($sql);
        }
    }

    /**
     * Saves the current settings to the database.
     * 
     * @param string $tablePrefix Database table prefix
     * @return void
     * @throws Exception if query fails
     */
    public function saveAllToDatabase(string $tablePrefix): void
    {
        foreach ($this->config as $name => $value) {
            if ($value !== null) {
                self::saveToDatabase($tablePrefix, $name, $value);
            }
        }
    }
}
