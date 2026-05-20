<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Config\Settings;
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
    }

    public function testSetAndGetAccessToken(): void
    {
        $settings = new Settings();
        $settings->setAccessToken('test-token-123');
        $this->assertSame('test-token-123', $settings->getAccessToken());
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
}
