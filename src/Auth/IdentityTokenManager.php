<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Token provider for the identity-verification (KYC) service.
 *
 * Kept fully separate from {@see AccessTokenManager} on purpose: the KYC
 * service lives on its own host, uses its own credential pair, signs with
 * HMAC-SHA256 over "{client_id}:{rfc3339_utc_timestamp}" (scheme D), and
 * returns a flat token payload instead of the v1 envelope. Mixing the two
 * schemes is the most likely integration mistake, so the SDK isolates them.
 */
final class IdentityTokenManager
{
    private const TOKEN_PATH = '/api/v1/kyc/auth/get-auth-token';

    private const TTL_BUFFER = 60;

    public function __construct(
        private readonly TokenRepositoryInterface $repository,
        private readonly HttpFactory $http,
        private readonly IdentitySigner $signer,
        private readonly SingaPayConfig $config,
    ) {}

    /**
     * Return a valid KYC bearer token, exchanging credentials only when the
     * cached token is missing or expired.
     *
     * @throws AuthenticationException When the KYC service rejects the credentials.
     * @throws ConnectionException When the KYC service cannot be reached.
     */
    public function token(): string
    {
        $key = $this->cacheKey();

        if ($token = $this->repository->get($key)) {
            return $token;
        }

        return $this->repository->withLock($key, function () use ($key): string {
            if ($token = $this->repository->get($key)) {
                return $token;
            }

            [$token, $ttl] = $this->exchangeCredentials();

            $this->repository->put($key, $token, $ttl);

            return $token;
        });
    }

    /**
     * Discard the cached token so the next call performs a fresh exchange.
     */
    public function forget(): void
    {
        $this->repository->forget($this->cacheKey());
    }

    /**
     * @return array{0: string, 1: int} The token and its cache TTL in seconds.
     *
     * @throws AuthenticationException
     * @throws ConnectionException
     */
    private function exchangeCredentials(): array
    {
        $clientId = $this->config->requireIdentityClientId();
        $timestamp = $this->signer->timestamp();
        $baseUrl = $this->config->baseUrl(Host::Identity);

        try {
            $response = $this->http
                ->baseUrl($baseUrl)
                ->timeout($this->config->timeout)
                ->acceptJson()
                ->post(self::TOKEN_PATH, [
                    'client_id' => $clientId,
                    'timestamp' => $timestamp,
                    'signature' => $this->signer->sign(
                        $clientId,
                        $timestamp,
                        $this->config->requireIdentityClientSecret(),
                    ),
                ]);
        } catch (HttpConnectionException $exception) {
            throw ConnectionException::to($baseUrl.self::TOKEN_PATH, $exception);
        }

        $result = Response::fromHttp($response);

        if ($result->failed() || ! is_string($result->data('access_token'))) {
            throw new AuthenticationException(
                $result,
                "SingaPay identity token exchange failed [HTTP {$result->status}]: {$result->message}"
            );
        }

        $ttl = max((int) $result->data('expires_in', 0) - self::TTL_BUFFER, self::TTL_BUFFER);

        return [(string) $result->data('access_token'), $ttl];
    }

    private function cacheKey(): string
    {
        $clientHash = hash('sha256', $this->config->requireIdentityClientId());

        return "singapay:token:{$this->config->environment->value}:identity:{$clientHash}";
    }
}
