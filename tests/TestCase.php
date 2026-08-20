<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Tests;

use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\SingaPayServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Deterministic test credentials shared by the whole suite.
     */
    public const CLIENT_ID = 'test-client-id';

    public const CLIENT_SECRET = 'test-client-secret';

    public const PARTNER_ID = 'test-partner-id';

    public const ACCOUNT_ID = '01J5XW8LD0R6M9CJEXAMPLE01';

    protected function getPackageProviders($app): array
    {
        return [SingaPayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SingaPay' => SingaPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('singapay', array_merge($app['config']->get('singapay', []), [
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'partner_id' => self::PARTNER_ID,
            'account_id' => self::ACCOUNT_ID,
            'identity' => [
                'client_id' => 'test-identity-client',
                'client_secret' => 'test-identity-secret',
            ],
        ]));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
