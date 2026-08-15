<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Tests\Unit;

use ksfraser\FrontAccounting\Square\Infrastructure\SquareClientFactory;
use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;
use PHPUnit\Framework\TestCase;
use Square\SquareClient;
use Square\Environment;

class SquareClientFactoryTest extends TestCase
{
    public function testCreateWithSandboxEnvironment(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getAccessToken')->willReturn('sandbox-token');
        $settings->method('getEnvironment')->willReturn('sandbox');

        $client = SquareClientFactory::create($settings);
        $this->assertInstanceOf(SquareClient::class, $client);
    }

    public function testCreateWithProductionEnvironment(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getAccessToken')->willReturn('prod-token');
        $settings->method('getEnvironment')->willReturn('production');

        $client = SquareClientFactory::create($settings);
        $this->assertInstanceOf(SquareClient::class, $client);
    }

    public function testCreateThrowsExceptionWhenTokenNull(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getAccessToken')->willReturn(null);

        $this->expectException(SquareException::class);
        $this->expectExceptionMessage('access_token');
        SquareClientFactory::create($settings);
    }

    public function testCreateThrowsExceptionWhenTokenEmpty(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getAccessToken')->willReturn('');

        $this->expectException(SquareException::class);
        SquareClientFactory::create($settings);
    }
}
