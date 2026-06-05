<?php
declare(strict_types=1);

namespace Ksfraser\Frontaccounting\SquareUp\Infrastructure;

use Ksfraser\Frontaccounting\SquareUp\Contracts\SettingsInterface;
use Ksfraser\Frontaccounting\SquareUp\Exceptions\SquareException;
use Square\SquareClient;
use Square\Environment;

class SquareClientFactory
{
    public static function create(SettingsInterface $settings): SquareClient
    {
        $accessToken = $settings->getAccessToken();
        if ($accessToken === null || $accessToken === '') {
            throw SquareException::configurationError('access_token');
        }

        return new SquareClient([
            'accessToken' => $accessToken,
            'environment' => $settings->getEnvironment() === 'production'
                ? Environment::PRODUCTION
                : Environment::SANDBOX,
        ]);
    }
}
