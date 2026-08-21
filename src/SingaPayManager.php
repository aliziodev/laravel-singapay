<?php

declare(strict_types=1);

namespace Aliziodev\Singapay;

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\AccessTokenSigner;
use Aliziodev\Singapay\Auth\IdentitySigner;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Exceptions\ConfigurationException;
use Aliziodev\Singapay\Http\Client;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves the SDK per credential set ("connection").
 *
 * A merchant can hold several dashboard credentials: a merchant-wide
 * **Default** one, plus **Specific** ones bound to particular sub-accounts.
 * SP403 refuses the Default credential for an account that has its own, so
 * an application serving several accounts genuinely needs several credential
 * sets. Each is declared under `singapay.connections` and reached by name:
 *
 * ```php
 * SingaPay::paymentLinks()->create([...]);              // the default connection
 * SingaPay::connection('payouts')->disbursement()->transfer([...]);
 * ```
 *
 * Calls not addressed to a connection are forwarded to the default one, so
 * every existing call site keeps working unchanged.
 *
 * Only credential-shaped state is per connection. The environment, base
 * URLs, the money-out guard, webhook settings and logging are application
 * policy and stay shared — see {@see SingaPayConfig::CONNECTION_KEYS}.
 *
 * Nothing is memoized here, deliberately. Building a connection is pure
 * array work plus a handful of allocations — access tokens live in the
 * shared {@see TokenRepositoryInterface} cache, not in the objects built
 * here, so a rebuild never costs a round trip. Caching them instead would
 * buy nothing and introduce a real trap: config changed after the first
 * resolution (which is routine in tests) would be silently ignored.
 *
 * @mixin SingaPay
 */
class SingaPayManager
{
    /**
     * Set by {@see swap()} — a stand-in returned for every connection.
     */
    private ?SingaPay $override = null;

    public function __construct(private readonly Container $app) {}

    /**
     * The SDK bound to one credential set.
     *
     * @param  string|null  $name  Connection name; defaults to `singapay.default`.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function connection(?string $name = null): SingaPay
    {
        if ($this->override instanceof SingaPay) {
            return $this->override;
        }

        return new SingaPay($this->client($name), $this->config($name));
    }

    /**
     * The config snapshot for one connection.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function config(?string $name = null): SingaPayConfig
    {
        return SingaPayConfig::forConnection($this->configArray(), $name);
    }

    /**
     * The HTTP client for one connection.
     *
     * Resolved through the container rather than constructed here, so a
     * decorator registered with `$app->extend(SingaPayClientInterface::class,
     * ...)` wraps *every* connection's client, not just the default one.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function client(?string $name = null): SingaPayClientInterface
    {
        return $name === null
            ? $this->app->make(SingaPayClientInterface::class)
            : $this->app->make(SingaPayClientInterface::class, ['connection' => $name]);
    }

    /**
     * Assemble a client from the shared stateless services plus this
     * connection's own config and token managers.
     *
     * Called by the container binding for {@see SingaPayClientInterface};
     * use {@see Client()} instead, which runs it through the container.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function buildClient(?string $name = null): Client
    {
        $config = $this->config($name);

        return new Client(
            http: $this->app->make(HttpFactory::class),
            config: $config,
            tokens: $this->tokens($name),
            identityTokens: $this->identityTokens($name),
            signer: $this->app->make(RequestSigner::class),
            normalizer: $this->app->make(JsonNormalizerInterface::class),
            clock: $this->app->make(JakartaClock::class),
            logger: $this->logger($config),
        );
    }

    /**
     * The access token manager for one connection.
     *
     * Tokens are cached under a key that already includes a hash of the
     * client id, so two connections never share a token.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function tokens(?string $name = null): AccessTokenManager
    {
        return new AccessTokenManager(
            $this->app->make(TokenRepositoryInterface::class),
            $this->app->make(HttpFactory::class),
            $this->app->make(AccessTokenSigner::class),
            $this->config($name),
        );
    }

    /**
     * The identity (KYC) token manager for one connection.
     *
     * @throws ConfigurationException When no such connection is configured.
     */
    public function identityTokens(?string $name = null): IdentityTokenManager
    {
        return new IdentityTokenManager(
            $this->app->make(TokenRepositoryInterface::class),
            $this->app->make(HttpFactory::class),
            $this->app->make(IdentitySigner::class),
            $this->config($name),
        );
    }

    /**
     * The connection used when none is named.
     */
    public function getDefaultConnection(): string
    {
        $default = $this->configArray()['default'] ?? null;

        return is_string($default) && $default !== '' ? $default : SingaPayConfig::DEFAULT_CONNECTION;
    }

    /**
     * Every configured connection name, the default one first.
     *
     * @return non-empty-list<string>
     */
    public function connectionNames(): array
    {
        return SingaPayConfig::connectionNames($this->configArray());
    }

    /**
     * Every key that may have signed an inbound webhook: the candidates of
     * every connection, plus the extra keys from `webhooks.secrets`.
     *
     * One callback URL can receive deliveries signed by more than one
     * credential — money-out notifications come from the merchant Default
     * credential even when the transfer was made with a Specific one — so
     * verification has to consider every credential the merchant holds, not
     * only the one the current call happens to use.
     *
     * Connections that are not fully configured are skipped rather than
     * allowed to break verification for the ones that are.
     *
     * @return non-empty-list<string>
     *
     * @throws ConfigurationException When no connection yields a usable key.
     */
    public function webhookSecrets(): array
    {
        $secrets = [];

        foreach ($this->connectionNames() as $name) {
            try {
                foreach ($this->config($name)->webhookSecrets() as $secret) {
                    $secrets[] = $secret;
                }
            } catch (ConfigurationException) {
                continue;
            }
        }

        $secrets = array_values(array_unique($secrets));

        if ($secrets === []) {
            throw ConfigurationException::missing('client_secret');
        }

        return $secrets;
    }

    /**
     * Return the given instance for every connection.
     *
     * Used by {@see Facades\SingaPay::fake()} so a faked SDK answers
     * whichever credential set the code under test asks for.
     */
    public function swap(SingaPay $sdk): void
    {
        $this->override = $sdk;
    }

    /**
     * Forward everything else to the default connection.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }

    /**
     * The live `singapay` config array.
     *
     * Read on every use rather than captured once, so configuration changed
     * after boot — routine in tests — is always honoured.
     *
     * @return array<string, mixed>
     */
    private function configArray(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->app->make(ConfigRepository::class)->get('singapay', []);

        return $config;
    }

    /**
     * Build the logger the client writes request metadata to.
     */
    private function logger(SingaPayConfig $config): LoggerInterface
    {
        if (! $config->loggingEnabled) {
            return new NullLogger;
        }

        return $this->app->make(LogManager::class)->channel($config->loggingChannel);
    }
}
