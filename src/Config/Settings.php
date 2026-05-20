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
        while ($row = db_fetch($result)) {
            $map = [
                'access_token' => 'access_token',
                'lastdate' => 'last_import_date',
                'destCust' => 'destination_customer',
            ];

            $configKey = $map[$row['name']] ?? $row['name'];
            $config[$configKey] = $row['value'];
        }

        return new self($config);
    }

    public function getAccessToken(): ?string
    {
        return $this->config['access_token'] ?? null;
    }

    public function setAccessToken(string $token): void
    {
        $this->config['access_token'] = $token;
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
}
