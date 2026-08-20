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
use Aliziodev\Singapay\Http\Client;
use Aliziodev\Singapay\Http\Controllers\WebhookController;
use Aliziodev\Singapay\Http\Middleware\VerifyWebhookSignature;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the SDK into the Laravel container and registers the webhook route,
 * publishable assets, and artisan commands.
 */
class SingaPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/singapay.php', 'singapay');

        $this->app->singleton(SingaPayConfig::class, function (Application $app): SingaPayConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('singapay', []);

            return SingaPayConfig::fromArray($config);
        });

        $this->app->singleton(JsonNormalizerInterface::class, JsonNormalizer::class);

        $this->app->singleton(TokenRepositoryInterface::class, function (Application $app): CacheTokenRepository {
            return new CacheTokenRepository(
                $app->make(CacheFactory::class)->store(),
                // The refresh lock must outlive the slowest token request.
                lockSeconds: $app->make(SingaPayConfig::class)->timeout + 15,
            );
        });

        $this->app->singleton(JakartaClock::class);
        $this->app->singleton(AccessTokenSigner::class);
        $this->app->singleton(RequestSigner::class);
        $this->app->singleton(IdentitySigner::class);
        $this->app->singleton(AccessTokenManager::class);
        $this->app->singleton(IdentityTokenManager::class);

        $this->app->singleton(SingaPayClientInterface::class, function (Application $app): Client {
            $config = $app->make(SingaPayConfig::class);

            return new Client(
                http: $app->make(HttpFactory::class),
                config: $config,
                tokens: $app->make(AccessTokenManager::class),
                identityTokens: $app->make(IdentityTokenManager::class),
                signer: $app->make(RequestSigner::class),
                normalizer: $app->make(JsonNormalizerInterface::class),
                clock: $app->make(JakartaClock::class),
                logger: $this->resolveLogger($app, $config),
            );
        });

        $this->app->singleton(SingaPay::class, function (Application $app): SingaPay {
            return new SingaPay(
                $app->make(SingaPayClientInterface::class),
                $app->make(SingaPayConfig::class),
            );
        });

        $this->app->alias(SingaPay::class, 'singapay');
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

    /**
     * Resolve the logger the HTTP client writes request metadata to.
     */
    protected function resolveLogger(Application $app, SingaPayConfig $config): LoggerInterface
    {
        if (! $config->loggingEnabled) {
            return new NullLogger;
        }

        return $app->make(LogManager::class)->channel($config->loggingChannel);
    }
}
