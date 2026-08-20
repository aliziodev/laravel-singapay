<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Auth;

use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Cache-aware access-token provider for the payment and biller hosts.
 *
 * Tokens are cached with a TTL of `expires_in` minus a safety buffer, and
 * refreshes run under an atomic lock so concurrent workers never stampede
 * the token endpoint. The payment host supports both token schemes:
 *
 * - v1.1 (default): HMAC-SHA512 signed request ({@see AccessTokenSigner}).
 * - v1.0 (legacy): HTTP Basic authentication.
 *
 * The biller host only speaks v1.0. The identity-verification host has a
 * completely different scheme — see {@see IdentityTokenManager}.
 */
final class AccessTokenManager
{
    /**
     * Seconds subtracted from `expires_in` so a token is refreshed before
     * it can expire mid-request.
     */
    private const TTL_BUFFER = 60;

    public function __construct(
        private readonly TokenRepositoryInterface $repository,
        private readonly HttpFactory $http,
        private readonly AccessTokenSigner $signer,
        private readonly SingaPayConfig $config,
    ) {}

    /**
     * Return a valid bearer token for the host, requesting a new one only
     * when the cached token is missing or expired.
     *
     * @throws AuthenticationException When SingaPay rejects the credentials.
     * @throws IpNotWhitelistedException When SingaPay rejects this server's IP.
     * @throws ConnectionException When the token endpoint cannot be reached.
     */
    public function token(Host $host = Host::Payment): string
    {
        $key = $this->cacheKey($host);

        if ($token = $this->repository->get($key)) {
            return $token;
        }

        return $this->repository->withLock($key, function () use ($key, $host): string {
            // Another process may have refreshed while we waited for the lock.
            if ($token = $this->repository->get($key)) {
                return $token;
            }

            [$token, $ttl] = $this->requestToken($host);

            $this->repository->put($key, $token, $ttl);

            return $token;
        });
    }

    /**
     * Discard the cached token so the next call fetches a fresh one.
     * Used after the gateway reports SP013 (unauthorized).
     */
    public function forget(Host $host = Host::Payment): void
    {
        $this->repository->forget($this->cacheKey($host));
    }

    /**
     * Request a fresh token from the gateway.
     *
     * @return array{0: string, 1: int} The token and its cache TTL in seconds.
     *
     * @throws AuthenticationException
     * @throws IpNotWhitelistedException
     * @throws ConnectionException
     */
    private function requestToken(Host $host): array
    {
        $useSigned = $host === Host::Payment && $this->config->authVersion === '1.1';
        $path = $useSigned ? '/api/v1.1/access-token/b2b' : '/api/v1.0/access-token/b2b';
        $baseUrl = $this->config->baseUrl($host);

        try {
            $response = $this->pendingRequest($baseUrl, $useSigned)
                ->post($path, ['grant_type' => 'client_credentials']);
        } catch (HttpConnectionException $exception) {
            throw ConnectionException::to($baseUrl.$path, $exception);
        }

        $result = Response::fromHttp($response);

        if ($result->failed() || ! is_string($result->data('access_token'))) {
            // A non-whitelisted server never gets past the token exchange, so
            // this is where an IP rejection actually surfaces in practice.
            // Raise the dedicated exception rather than a generic auth
            // failure — the cause and the fix are completely different.
            if ($result->rejectedIp()) {
                throw RequestException::fromResponse($result);
            }

            throw new AuthenticationException(
                $result,
                "SingaPay access-token request failed [HTTP {$result->status}]: {$result->message}"
            );
        }

        // expires_in arrives as a string (e.g. "216000"); keep a refresh
        // buffer, but never cache longer than the token actually lives —
        // a floor above the real lifetime would serve dead tokens.
        $expiresIn = (int) $result->data('expires_in', 0);
        $ttl = max(min($expiresIn - self::TTL_BUFFER, $expiresIn), 1);

        return [(string) $result->data('access_token'), $ttl];
    }

    private function pendingRequest(string $baseUrl, bool $useSigned): PendingRequest
    {
        $request = $this->http
            ->baseUrl($baseUrl)
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->withHeaders(['X-PARTNER-ID' => $this->config->requirePartnerId()]);

        if ($useSigned) {
            return $request->withHeaders([
                'X-CLIENT-ID' => $this->config->requireClientId(),
                'X-Signature' => $this->signer->sign(
                    $this->config->requireClientId(),
                    $this->config->requireClientSecret(),
                ),
            ]);
        }

        return $request->withBasicAuth(
            $this->config->requireClientId(),
            $this->config->requireClientSecret(),
        );
    }

    private function cacheKey(Host $host): string
    {
        $clientHash = hash('sha256', $this->config->requireClientId());

        return "singapay:token:{$this->config->environment->value}:{$host->value}:{$clientHash}";
    }
}
