<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Square\Infrastructure;

use ksfraser\FrontAccounting\Square\Contracts\SettingsInterface;
use ksfraser\FrontAccounting\Square\Exceptions\SquareException;
use Square\SquareClient;
use Square\Environment;

class SquareClientFactory
{
    /**
     * @since 2.0.0
     * @BABOK Related: FR-SQ-001
     */
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
