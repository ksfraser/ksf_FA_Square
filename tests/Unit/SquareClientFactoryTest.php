<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Tests\Unit;

use Ksfraser\Frontaccounting\SquareUp\Infrastructure\SquareClientFactory;
use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
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
