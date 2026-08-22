<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Config;

use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;

class Settings implements SettingsInterface
{
    /**
     * @var array
     */
    private $config;

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
            'default_tax_group' => null,
            // Absorbed from ISU — source-specific import config
            'gl_account' => 0,
            'cash_gl' => 0,
            'xfer_to_gl' => 0,
            'bank_account' => 0,
            'xfer_to_bank' => 0,
            'cash_bank' => 0,
            'default_pay_card' => 0,
            'default_pay_cash' => 0,
            'useCardAsBranch' => 0,
            'allowSkuChange' => 0,
            'default_pricebook' => '',
        ], $config);
    }

    public static function fromFADatabase(string $tablePrefix): self
    {
        $config = [];

        $result = \db_query("SELECT `name`, `value` FROM {$tablePrefix}square");
        if ($result !== false) {
            while ($row = \db_fetch($result)) {
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

    /**
     * Gets a description of which token source is being used.
     * Useful for display/notification purposes.
     * 
     * @return string Description like 'sandbox_access_token', 'production_access_token', or 'access_token (legacy fallback)'
     */
    public function getTokenSourceDescription(): string
    {
        $env = $this->getEnvironment();

        if ($env === 'sandbox' && $this->getSandboxAccessToken() !== null) {
            return 'sandbox_access_token';
        } elseif ($env === 'production' && $this->getProductionAccessToken() !== null) {
            return 'production_access_token';
        } else {
            return 'access_token (legacy fallback)';
        }
    }

    /**
     * Gets the effective token type being used.
     * 
     * @return string 'sandbox', 'production', or 'legacy'
     */
    public function getTokenType(): string
    {
        $env = $this->getEnvironment();

        if ($env === 'sandbox' && $this->getSandboxAccessToken() !== null) {
            return 'sandbox';
        } elseif ($env === 'production' && $this->getProductionAccessToken() !== null) {
            return 'production';
        } else {
            return 'legacy';
        }
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

    /**
     * Gets the default FA tax group used to resolve the catalog tax rate
     * pushed to Square.
     *
     * @return int|null Tax group id or null when not configured
     *
     * @since 2.4.4
     */
    public function getDefaultTaxGroup(): ?int
    {
        $id = $this->config['default_tax_group'] ?? null;
        return $id !== null ? (int)$id : null;
    }

    /**
     * Sets the default FA tax group used to resolve the catalog tax rate
     * pushed to Square.
     *
     * @param int $taxGroupId Tax group id
     * @return void
     *
     * @since 2.4.4
     */
    public function setDefaultTaxGroup(int $taxGroupId): void
    {
        $this->config['default_tax_group'] = $taxGroupId;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    // -- Absorbed import config fields ------------------------------------

    public function getGlAccount(): int
    {
        return (int)($this->config['gl_account'] ?? 0);
    }

    public function setGlAccount(int $gl): void
    {
        $this->config['gl_account'] = $gl;
    }

    public function getCashGl(): int
    {
        return (int)($this->config['cash_gl'] ?? 0);
    }

    public function setCashGl(int $gl): void
    {
        $this->config['cash_gl'] = $gl;
    }

    public function getXferToGl(): int
    {
        return (int)($this->config['xfer_to_gl'] ?? 0);
    }

    public function setXferToGl(int $gl): void
    {
        $this->config['xfer_to_gl'] = $gl;
    }

    public function getBankAccount(): int
    {
        return (int)($this->config['bank_account'] ?? 0);
    }

    public function setBankAccount(int $bank): void
    {
        $this->config['bank_account'] = $bank;
    }

    public function getXferToBank(): int
    {
        return (int)($this->config['xfer_to_bank'] ?? 0);
    }

    public function setXferToBank(int $bank): void
    {
        $this->config['xfer_to_bank'] = $bank;
    }

    public function getCashBank(): int
    {
        return (int)($this->config['cash_bank'] ?? 0);
    }

    public function setCashBank(int $bank): void
    {
        $this->config['cash_bank'] = $bank;
    }

    public function getDefaultPayCard(): int
    {
        return (int)($this->config['default_pay_card'] ?? 0);
    }

    public function setDefaultPayCard(int $payType): void
    {
        $this->config['default_pay_card'] = $payType;
    }

    public function getDefaultPayCash(): int
    {
        return (int)($this->config['default_pay_cash'] ?? 0);
    }

    public function setDefaultPayCash(int $payType): void
    {
        $this->config['default_pay_cash'] = $payType;
    }

    public function isUseCardAsBranch(): bool
    {
        return (bool)($this->config['useCardAsBranch'] ?? false);
    }

    public function setUseCardAsBranch(bool $flag): void
    {
        $this->config['useCardAsBranch'] = $flag ? 1 : 0;
    }

    public function isAllowSkuChange(): bool
    {
        return (bool)($this->config['allowSkuChange'] ?? false);
    }

    public function setAllowSkuChange(bool $flag): void
    {
        $this->config['allowSkuChange'] = $flag ? 1 : 0;
    }

    public function getDefaultPricebook(): string
    {
        return (string)($this->config['default_pricebook'] ?? '');
    }

    public function setDefaultPricebook(string $pricebook): void
    {
        $this->config['default_pricebook'] = $pricebook;
    }

    /**
     * Builds a SourceConfig-compatible array for ISU processing.
     *
     * @return array<string, mixed>
     */
    public function toSourceConfigArray(): array
    {
        return [
            'source'            => 'square',
            'gl_account'        => $this->getGlAccount(),
            'cash_gl'           => $this->getCashGl(),
            'xfer_to_gl'        => $this->getXferToGl(),
            'bank_account'      => $this->getBankAccount(),
            'xfer_to_bank'      => $this->getXferToBank(),
            'cash_bank'         => $this->getCashBank(),
            'default_pay_card'  => $this->getDefaultPayCard(),
            'default_pay_cash'  => $this->getDefaultPayCash(),
            'useCardAsBranch'   => $this->isUseCardAsBranch(),
            'allowSkuChange'    => $this->isAllowSkuChange(),
            'default_pricebook' => $this->getDefaultPricebook(),
        ];
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
        $escapedName = \db_escape($name);
        $escapedValue = \db_escape((string)$value);

        $sql = "SELECT COUNT(*) AS cnt FROM {$table} WHERE name = {$escapedName}";
        $result = \db_query($sql);
        if ($result !== false) {
            $row = \db_fetch_assoc($result);
            if ((int)$row['cnt'] > 0) {
                $sql = "UPDATE {$table} SET value = {$escapedValue} WHERE name = {$escapedName}";
            } else {
                $sql = "INSERT INTO {$table} (name, value) VALUES ({$escapedName}, {$escapedValue})";
            }
            \db_query($sql);
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
