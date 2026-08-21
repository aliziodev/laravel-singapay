<?php

declare(strict_types=1);

namespace Aliziodev\Singapay;

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\AccessTokenSigner;
use Aliziodev\Singapay\Auth\CacheTokenRepository;
use Aliziodev\Singapay\Auth\IdentitySigner;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Console\Commands\InstallCommand;
use Aliziodev\Singapay\Console\Commands\PingCommand;
use Aliziodev\Singapay\Console\Commands\TokenCommand;
use Aliziodev\Singapay\Console\Commands\VerifySignatureCommand;
use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Http\Controllers\WebhookController;
use Aliziodev\Singapay\Http\Middleware\VerifyWebhookSignature;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the SDK into the Laravel container and registers the webhook route,
 * publishable assets, and artisan commands.
 */
class SingaPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/singapay.php', 'singapay');

        $this->app->singleton(SingaPayManager::class, fn (Application $app): SingaPayManager => new SingaPayManager($app));

        // The default connection stays directly injectable, so type-hinting
        // any of these keeps resolving what it always did.
        $this->app->singleton(SingaPayConfig::class, fn (Application $app): SingaPayConfig => $app->make(SingaPayManager::class)->config());

        $this->app->singleton(JsonNormalizerInterface::class, JsonNormalizer::class);

        $this->app->singleton(TokenRepositoryInterface::class, function (Application $app): CacheTokenRepository {
            return new CacheTokenRepository(
                $app->make(CacheFactory::class)->store(),
                // The refresh lock must outlive the slowest token request.
                lockSeconds: $app->make(SingaPayConfig::class)->timeout + 15,
            );
        });

        // Stateless — no credential is baked in, so every connection shares them.
        $this->app->singleton(JakartaClock::class);
        $this->app->singleton(AccessTokenSigner::class);
        $this->app->singleton(RequestSigner::class);
        $this->app->singleton(IdentitySigner::class);

        $this->app->singleton(AccessTokenManager::class, fn (Application $app): AccessTokenManager => $app->make(SingaPayManager::class)->tokens());
        $this->app->singleton(IdentityTokenManager::class, fn (Application $app): IdentityTokenManager => $app->make(SingaPayManager::class)->identityTokens());
        // Bound with a "connection" parameter so every connection's client is
        // built through the container and picks up any registered extender.
        // Passing a parameter bypasses the shared instance, so only the
        // default connection is cached — which is what callers expect.
        $this->app->singleton(SingaPayClientInterface::class, function (Application $app, array $parameters): SingaPayClientInterface {
            $connection = $parameters['connection'] ?? null;

            return $app->make(SingaPayManager::class)->buildClient(is_string($connection) ? $connection : null);
        });
        // Built from the container's own client and config so the default
        // connection shares their identity: app(SingaPay::class)->client()
        // is app(SingaPayClientInterface::class), as it always was.
        $this->app->singleton(SingaPay::class, fn (Application $app): SingaPay => new SingaPay(
            $app->make(SingaPayClientInterface::class),
            $app->make(SingaPayConfig::class),
        ));

        $this->app->alias(SingaPayManager::class, 'singapay');
    }

    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerWebhookRoute();
        $this->registerCommands();
    }

    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/singapay.php' => $this->app->configPath('singapay.php'),
        ], 'singapay-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'singapay-migrations');
    }

    /**
     * Register the webhook route WITHOUT the "web" middleware group.
     *
     * The "web" group includes CSRF verification; SingaPay posts without a
     * CSRF token, so a web-grouped route would reject every delivery with
     * 419 and leave it looping in the gateway's retry queue.
     */
    protected function registerWebhookRoute(): void
    {
        $config = $this->app->make(SingaPayConfig::class);

        if (! $config->webhooksEnabled) {
            return;
        }

        /** @var array<int, mixed> $extra */
        $extra = $this->app->make('config')->get('singapay.webhooks.middleware', []);

        $this->app->make(Router::class)
            ->post($config->webhookPath, WebhookController::class)
            ->middleware([VerifyWebhookSignature::class, ...$extra])
            ->name('singapay.webhook');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            TokenCommand::class,
            PingCommand::class,
            VerifySignatureCommand::class,
        ]);
    }
}
