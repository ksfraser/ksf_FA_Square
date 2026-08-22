<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use ksfraser\FrontAccounting\Square\Config\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new Settings();
        $this->assertNull($settings->getAccessToken());
        $this->assertSame('sandbox', $settings->getEnvironment());
        $this->assertNull($settings->getLastImportDate());
        $this->assertNull($settings->getDestinationCustomer());
        $this->assertNull($settings->getDefaultLocation());
        $this->assertNull($settings->getDefaultTaxGroup());
    }

    public function testSetAndGetAccessToken(): void
    {
        $settings = new Settings();
        $settings->setAccessToken('legacy-token');
        $this->assertSame('legacy-token', $settings->getAccessToken());
    }

    public function testSandboxTokenTakesPriorityInSandboxEnv(): void
    {
        $settings = new Settings([
            'access_token' => 'legacy',
            'sandbox_access_token' => 'sandbox-specific',
            'environment' => 'sandbox',
        ]);
        $this->assertSame('sandbox-specific', $settings->getAccessToken());
    }

    public function testProductionTokenTakesPriorityInProductionEnv(): void
    {
        $settings = new Settings([
            'access_token' => 'legacy',
            'production_access_token' => 'prod-specific',
            'environment' => 'production',
        ]);
        $this->assertSame('prod-specific', $settings->getAccessToken());
    }

    public function testFallsBackToLegacyTokenWhenEnvSpecificMissing(): void
    {
        $settings = new Settings([
            'access_token' => 'legacy',
            'environment' => 'production',
        ]);
        $this->assertSame('legacy', $settings->getAccessToken());
    }

    public function testSetAndGetSandboxAccessToken(): void
    {
        $settings = new Settings();
        $settings->setSandboxAccessToken('sb-token');
        $this->assertSame('sb-token', $settings->getSandboxAccessToken());
    }

    public function testSetAndGetProductionAccessToken(): void
    {
        $settings = new Settings();
        $settings->setProductionAccessToken('prod-token');
        $this->assertSame('prod-token', $settings->getProductionAccessToken());
    }

    public function testSetAndGetEnvironment(): void
    {
        $settings = new Settings();
        $settings->setEnvironment('production');
        $this->assertSame('production', $settings->getEnvironment());
    }

    public function testSetAndGetLastImportDate(): void
    {
        $settings = new Settings();
        $date = new \DateTimeImmutable('2026-01-15 10:00:00');
        $settings->setLastImportDate($date);
        $retrieved = $settings->getLastImportDate();
        $this->assertNotNull($retrieved);
        $this->assertSame('2026-01-15', $retrieved->format('Y-m-d'));
    }

    public function testSetAndGetDestinationCustomer(): void
    {
        $settings = new Settings();
        $settings->setDestinationCustomer(42);
        $this->assertSame(42, $settings->getDestinationCustomer());
    }

    public function testSetAndGetDefaultLocation(): void
    {
        $settings = new Settings();
        $settings->setDefaultLocation('LOC_001');
        $this->assertSame('LOC_001', $settings->getDefaultLocation());
    }

    public function testSetAndGetDefaultTaxGroup(): void
    {
        $settings = new Settings();
        $settings->setDefaultTaxGroup(3);
        $this->assertSame(3, $settings->getDefaultTaxGroup());
    }

    public function testConstructorAcceptsDefaultTaxGroup(): void
    {
        $settings = new Settings(['default_tax_group' => '2']);

        $this->assertSame(2, $settings->getDefaultTaxGroup());
    }

    public function testFromFADatabaseLoadsDefaultTaxGroup(): void
    {
        $GLOBALS['__fa_table'] = [
            ['name' => 'default_tax_group', 'value' => '5'],
        ];

        $settings = Settings::fromFADatabase('0_');

        $this->assertSame(5, $settings->getDefaultTaxGroup());
    }

    public function testConstructorAcceptsConfig(): void
    {
        $settings = new Settings([
            'access_token' => 'pre-set-token',
            'environment' => 'production',
        ]);
        $this->assertSame('pre-set-token', $settings->getAccessToken());
        $this->assertSame('production', $settings->getEnvironment());
    }

    public function testToArray(): void
    {
        $settings = new Settings(['access_token' => 'tok']);
        $config = $settings->toArray();
        $this->assertArrayHasKey('access_token', $config);
        $this->assertSame('tok', $config['access_token']);
    }

    public function testFromFADatabaseLoadsFullNames(): void
    {
        $GLOBALS['__fa_table'] = [
            ['name' => 'access_token', 'value' => 'legacy-tok'],
            ['name' => 'sandbox_access_token', 'value' => 'sb-tok'],
            ['name' => 'production_access_token', 'value' => 'prod-tok'],
        ];

        $settings = Settings::fromFADatabase('0_');

        $this->assertSame('sb-tok', $settings->getSandboxAccessToken());
        $this->assertSame('prod-tok', $settings->getProductionAccessToken());
        $this->assertSame('sb-tok', $settings->getAccessToken());
    }

    public function testFromFADatabaseHandlesTruncatedNames(): void
    {
        $GLOBALS['__fa_table'] = [
            ['name' => 'sandbox_access_', 'value' => 'truncated-sb-tok'],
            ['name' => 'production_acce', 'value' => 'truncated-prod-tok'],
        ];

        $settings = Settings::fromFADatabase('0_');

        $this->assertSame('truncated-sb-tok', $settings->getSandboxAccessToken());
        $this->assertSame('truncated-prod-tok', $settings->getProductionAccessToken());
    }

    public function testFromFADatabaseHandlesMissingTable(): void
    {
        $GLOBALS['__fa_table'] = [];

        $settings = Settings::fromFADatabase('0_');

        $this->assertNull($settings->getAccessToken());
        $this->assertNull($settings->getSandboxAccessToken());
        $this->assertNull($settings->getProductionAccessToken());
        $this->assertSame('sandbox', $settings->getEnvironment());
    }

    public function testFromFADatabaseLoadsAdditionalConfigKeys(): void
    {
        $GLOBALS['__fa_table'] = [
            ['name' => 'lastdate', 'value' => '2026-01-15 10:00:00'],
            ['name' => 'destCust', 'value' => '42'],
            ['name' => 'environment', 'value' => 'production'],
        ];

        $settings = Settings::fromFADatabase('0_');

        $this->assertSame('production', $settings->getEnvironment());
        $this->assertSame(42, $settings->getDestinationCustomer());
        $this->assertSame('2026-01-15', $settings->getLastImportDate()->format('Y-m-d'));
    }

    public function testAbsorbedImportConfigDefaults(): void
    {
        $settings = new Settings();
        $this->assertSame(0, $settings->getGlAccount());
        $this->assertSame(0, $settings->getCashGl());
        $this->assertSame(0, $settings->getXferToGl());
        $this->assertSame(0, $settings->getBankAccount());
        $this->assertSame(0, $settings->getXferToBank());
        $this->assertSame(0, $settings->getCashBank());
        $this->assertSame(0, $settings->getDefaultPayCard());
        $this->assertSame(0, $settings->getDefaultPayCash());
        $this->assertFalse($settings->isUseCardAsBranch());
        $this->assertFalse($settings->isAllowSkuChange());
        $this->assertSame('', $settings->getDefaultPricebook());
    }

    public function testAbsorbedImportConfigSetAndGet(): void
    {
        $settings = new Settings();
        $settings->setGlAccount(1010);
        $settings->setCashGl(1020);
        $settings->setXferToGl(1030);
        $settings->setBankAccount(5);
        $settings->setXferToBank(6);
        $settings->setCashBank(7);
        $settings->setDefaultPayCard(3);
        $settings->setDefaultPayCash(4);
        $settings->setUseCardAsBranch(true);
        $settings->setAllowSkuChange(true);
        $settings->setDefaultPricebook('pricebook_1');

        $this->assertSame(1010, $settings->getGlAccount());
        $this->assertSame(1020, $settings->getCashGl());
        $this->assertSame(1030, $settings->getXferToGl());
        $this->assertSame(5, $settings->getBankAccount());
        $this->assertSame(6, $settings->getXferToBank());
        $this->assertSame(7, $settings->getCashBank());
        $this->assertSame(3, $settings->getDefaultPayCard());
        $this->assertSame(4, $settings->getDefaultPayCash());
        $this->assertTrue($settings->isUseCardAsBranch());
        $this->assertTrue($settings->isAllowSkuChange());
        $this->assertSame('pricebook_1', $settings->getDefaultPricebook());
    }

    public function testToSourceConfigArray(): void
    {
        $settings = new Settings([
            'gl_account' => 1010,
            'cash_gl' => 1020,
            'bank_account' => 5,
            'xfer_to_bank' => 6,
            'useCardAsBranch' => 1,
        ]);

        $arr = $settings->toSourceConfigArray();

        $this->assertSame('square', $arr['source']);
        $this->assertSame(1010, $arr['gl_account']);
        $this->assertSame(1020, $arr['cash_gl']);
        $this->assertSame(5, $arr['bank_account']);
        $this->assertSame(6, $arr['xfer_to_bank']);
        $this->assertTrue($arr['useCardAsBranch']);
    }

    public function testAbsorbedImportConfigFromConstructor(): void
    {
        $settings = new Settings([
            'gl_account' => 2010,
            'bank_account' => 10,
            'allowSkuChange' => 1,
            'default_pricebook' => 'pb_main',
        ]);

        $this->assertSame(2010, $settings->getGlAccount());
        $this->assertSame(10, $settings->getBankAccount());
        $this->assertTrue($settings->isAllowSkuChange());
        $this->assertSame('pb_main', $settings->getDefaultPricebook());
    }
}
