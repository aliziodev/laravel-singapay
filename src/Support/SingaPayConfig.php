<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Support;

use Aliziodev\Singapay\Enums\Environment;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Immutable, typed snapshot of the `singapay` config.
 *
 * Built once by the service provider so the rest of the SDK never touches
 * the string-keyed config repository. Credentials may be null at boot (for
 * example in CI); the `require*` accessors throw a descriptive
 * {@see ConfigurationException} the moment a credential is actually needed.
 */
final readonly class SingaPayConfig
{
    /**
     * The connection name used when `singapay.default` is not set.
     */
    public const DEFAULT_CONNECTION = 'main';

    /**
     * Keys a `connections.*` entry may override.
     *
     * A connection is a *credential set*, so only credential-shaped keys are
     * accepted. Everything else — the environment, base URLs, the money-out
     * guard, webhooks, logging, timeouts — is application policy and stays
     * top-level, shared by every connection. Rejecting the rest is
     * deliberate: silently ignoring a `money_out` key nested in a connection
     * would be a dangerous surprise.
     *
     * @var list<string>
     */
    public const CONNECTION_KEYS = [
        'client_id',
        'client_secret',
        'partner_id',
        'account_id',
        'hmac_key',
        'auth_version',
        'identity',
        'biller',
    ];

    /**
     * @param  array<string, array{sandbox: string, production: string}>  $baseUrls  Base URLs per host group.
     * @param  list<string>  $webhookExtraSecrets  Extra keys accepted when verifying inbound webhook signatures.
     */
    public function __construct(
        public Environment $environment,
        public ?string $clientId,
        #[SensitiveParameter] public ?string $clientSecret,
        public ?string $partnerId,
        public ?string $accountId,
        #[SensitiveParameter] public ?string $hmacKey,
        public string $authVersion,
        public array $baseUrls,
        public ?string $identityClientId,
        #[SensitiveParameter] public ?string $identityClientSecret,
        public ?string $billerClientId,
        #[SensitiveParameter] public ?string $billerClientSecret,
        public ?string $billerPartnerId,
        public int $timeout,
        public int $retryTimes,
        public int $retrySleep,
        public bool $moneyOutEnabled,
        public bool $webhooksEnabled,
        public string $webhookPath,
        public bool $webhookVerifySignature,
        public int $webhookTolerance,
        public bool $webhookIdempotency,
        #[SensitiveParameter] public array $webhookExtraSecrets,
        public bool $loggingEnabled,
        public ?string $loggingChannel,
        public string $connection = self::DEFAULT_CONNECTION,
    ) {}

    /**
     * Build the config object from the `singapay` config array.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfigurationException When the environment or auth version is unrecognized.
     */
    public static function fromArray(array $config, string $connection = self::DEFAULT_CONNECTION): self
    {
        $environment = Environment::tryFrom((string) ($config['environment'] ?? 'sandbox'))
            ?? throw ConfigurationException::invalid('environment', 'must be "sandbox" or "production"');

        $authVersion = (string) ($config['auth_version'] ?? '1.1');

        if (! in_array($authVersion, ['1.0', '1.1'], true)) {
            throw ConfigurationException::invalid('auth_version', 'must be "1.0" or "1.1"');
        }

        return new self(
            environment: $environment,
            clientId: self::stringOrNull($config['client_id'] ?? null),
            clientSecret: self::stringOrNull($config['client_secret'] ?? null),
            partnerId: self::stringOrNull($config['partner_id'] ?? null),
            accountId: self::stringOrNull($config['account_id'] ?? null),
            hmacKey: self::stringOrNull($config['hmac_key'] ?? null),
            authVersion: $authVersion,
            baseUrls: $config['base_urls'] ?? [],
            identityClientId: self::stringOrNull($config['identity']['client_id'] ?? null),
            identityClientSecret: self::stringOrNull($config['identity']['client_secret'] ?? null),
            billerClientId: self::stringOrNull($config['biller']['client_id'] ?? null),
            billerClientSecret: self::stringOrNull($config['biller']['client_secret'] ?? null),
            billerPartnerId: self::stringOrNull($config['biller']['partner_id'] ?? null),
            timeout: (int) ($config['timeout'] ?? 30),
            retryTimes: (int) ($config['retry']['times'] ?? 2),
            retrySleep: (int) ($config['retry']['sleep'] ?? 200),
            moneyOutEnabled: (bool) ($config['money_out']['enabled'] ?? false),
            webhooksEnabled: (bool) ($config['webhooks']['enabled'] ?? true),
            webhookPath: (string) ($config['webhooks']['path'] ?? 'webhooks/singapay'),
            webhookVerifySignature: (bool) ($config['webhooks']['verify_signature'] ?? true),
            webhookTolerance: (int) ($config['webhooks']['tolerance'] ?? 300),
            webhookIdempotency: (bool) ($config['webhooks']['idempotency'] ?? true),
            webhookExtraSecrets: self::secretList($config['webhooks']['secrets'] ?? null),
            loggingEnabled: (bool) ($config['logging']['enabled'] ?? true),
            loggingChannel: self::stringOrNull($config['logging']['channel'] ?? null),
            connection: $connection,
        );
    }

    /**
     * Build the config for one named connection.
     *
     * A merchant can hold several dashboard credentials — a merchant-wide
     * Default one plus Specific ones bound to particular sub-accounts — and
     * SP403 forces calls for an assigned account onto the credential that
     * owns it. Each is declared under `connections`, and only the keys in
     * {@see CONNECTION_KEYS} may be set there; the rest is inherited from
     * the top level.
     *
     * The top-level credential keys are themselves the connection named by
     * `default`, so adding a second connection never disturbs the first.
     *
     * @param  array<string, mixed>  $config  The whole `singapay` config array.
     * @param  string|null  $name  Connection name; defaults to `singapay.default`.
     *
     * @throws ConfigurationException When the connection is unknown or declares a key it may not.
     */
    public static function forConnection(array $config, ?string $name = null): self
    {
        $default = self::stringOrNull($config['default'] ?? null) ?? self::DEFAULT_CONNECTION;
        $name ??= $default;

        $connections = is_array($config['connections'] ?? null) ? $config['connections'] : [];
        $overrides = $connections[$name] ?? null;

        if ($overrides === null) {
            if ($name === $default) {
                return self::fromArray($config, $name);
            }

            throw ConfigurationException::invalid('connections', "no connection named [{$name}] is configured");
        }

        if (! is_array($overrides)) {
            throw ConfigurationException::invalid("connections.{$name}", 'must be an array of credential keys');
        }

        foreach (array_keys($overrides) as $key) {
            if (! in_array($key, self::CONNECTION_KEYS, true)) {
                throw ConfigurationException::invalid(
                    "connections.{$name}.{$key}",
                    'is not a per-connection key; only '.implode(', ', self::CONNECTION_KEYS).' may be set on a connection'
                );
            }
        }

        return self::fromArray(array_replace($config, $overrides), $name);
    }

    /**
     * Every configured connection name, the default one first.
     *
     * @param  array<string, mixed>  $config  The whole `singapay` config array.
     * @return non-empty-list<string>
     */
    public static function connectionNames(array $config): array
    {
        $default = self::stringOrNull($config['default'] ?? null) ?? self::DEFAULT_CONNECTION;
        $connections = is_array($config['connections'] ?? null) ? $config['connections'] : [];

        $names = [$default];

        foreach (array_keys($connections) as $name) {
            if (is_string($name) && $name !== $default) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Resolve the base URL for a host in the current environment.
     *
     * @throws ConfigurationException When no URL is configured for the host.
     */
    public function baseUrl(Host $host): string
    {
        $url = $this->baseUrls[$host->value][$this->environment->value] ?? null;

        if (! is_string($url) || $url === '') {
            throw ConfigurationException::missing("base_urls.{$host->value}.{$this->environment->value}");
        }

        return rtrim($url, '/');
    }

    /**
     * @throws ConfigurationException When SINGAPAY_CLIENT_ID is not set.
     */
    public function requireClientId(): string
    {
        return $this->clientId ?? throw ConfigurationException::missing('client_id');
    }

    /**
     * @throws ConfigurationException When SINGAPAY_CLIENT_SECRET is not set.
     */
    public function requireClientSecret(): string
    {
        return $this->clientSecret ?? throw ConfigurationException::missing('client_secret');
    }

    /**
     * The candidate keys for verifying inbound webhook signatures: the
     * dashboard's HMAC Validation Key (when configured), the client secret,
     * and any extra keys from `webhooks.secrets`. The official docs name the
     * client secret, but the dashboard issues a dedicated validation key —
     * accepting either (each compared in constant time) keeps verification
     * correct whichever one the gateway actually signs with.
     *
     * The extra keys matter because one callback URL can receive deliveries
     * from more than one dashboard credential, each signing with its own
     * client secret. Verified in sandbox: a disbursement made with a Specific
     * credential was notified by the merchant Default credential, whose
     * X-PARTNER-ID it carried and whose client secret signed it. Without the
     * owning credential's secret in this list such deliveries are rejected.
     *
     * @return non-empty-list<string>
     *
     * @throws ConfigurationException When no key is configured.
     */
    public function webhookSecrets(): array
    {
        $secrets = array_values(array_unique(array_filter(
            [$this->hmacKey, $this->clientSecret, ...$this->webhookExtraSecrets]
        )));

        if ($secrets === []) {
            throw ConfigurationException::missing('client_secret');
        }

        return $secrets;
    }

    /**
     * @throws ConfigurationException When SINGAPAY_PARTNER_ID is not set.
     */
    public function requirePartnerId(): string
    {
        return $this->partnerId ?? throw ConfigurationException::missing('partner_id');
    }

    /**
     * The client id for a host. The biller is a separate product and may hold
     * its own credential set; when it is not configured the payment
     * credentials are used, which is right for merchants whose biller access
     * rides on the same keys.
     *
     * @throws ConfigurationException When neither the host-specific nor the payment value is set.
     */
    public function clientIdFor(Host $host): string
    {
        if ($host === Host::Biller && $this->billerClientId !== null) {
            return $this->billerClientId;
        }

        return $this->requireClientId();
    }

    /**
     * The client secret for a host. See {@see clientIdFor()}.
     *
     * @throws ConfigurationException When neither the host-specific nor the payment value is set.
     */
    public function clientSecretFor(Host $host): string
    {
        if ($host === Host::Biller && $this->billerClientSecret !== null) {
            return $this->billerClientSecret;
        }

        return $this->requireClientSecret();
    }

    /**
     * The partner id for a host. See {@see clientIdFor()}.
     *
     * @throws ConfigurationException When neither the host-specific nor the payment value is set.
     */
    public function partnerIdFor(Host $host): string
    {
        if ($host === Host::Biller && $this->billerPartnerId !== null) {
            return $this->billerPartnerId;
        }

        return $this->requirePartnerId();
    }

    /**
     * The default account ULID used when an endpoint call omits the account.
     *
     * @throws ConfigurationException When SINGAPAY_ACCOUNT_ID is not set either.
     */
    public function requireAccountId(): string
    {
        return $this->accountId ?? throw ConfigurationException::missing('account_id');
    }

    /**
     * @throws ConfigurationException When the identity-verification credentials are not set.
     */
    public function requireIdentityClientId(): string
    {
        return $this->identityClientId ?? throw ConfigurationException::missing('identity.client_id');
    }

    /**
     * @throws ConfigurationException When the identity-verification credentials are not set.
     */
    public function requireIdentityClientSecret(): string
    {
        return $this->identityClientSecret ?? throw ConfigurationException::missing('identity.client_secret');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Parse the extra webhook secrets, accepting either a comma-separated
     * string (the env form) or an array (the published-config form).
     *
     * @return list<string>
     */
    private static function secretList(mixed $value): array
    {
        $items = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => [],
        };

        $secrets = [];

        foreach ($items as $item) {
            if (is_string($item) && ($trimmed = trim($item)) !== '') {
                $secrets[] = $trimmed;
            }
        }

        return array_values(array_unique($secrets));
    }
}
