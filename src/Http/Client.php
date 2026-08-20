<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http;

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Exceptions\MoneyOutDisabledException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP transport for the SingaPay API, wrapping the Laravel HTTP client.
 *
 * Responsibilities, in one place:
 *
 * - Resolves the correct host and bearer token (payment, biller, identity).
 * - Applies the money-out request signature (scheme C) and sends the signed
 *   requests with the *exact* normalized JSON bytes that were hashed, so the
 *   wire body can never drift from the signature.
 * - Enforces the money-out guard: signed requests throw
 *   {@see MoneyOutDisabledException} unless `singapay.money_out.enabled`.
 * - Retries transient failures for GET requests only — write operations are
 *   never retried automatically, because a blind retry of a money-out call
 *   can duplicate a real transfer.
 * - Refreshes the access token and retries exactly once on SP013/HTTP 401.
 * - Normalizes every envelope generation into {@see Response} and maps
 *   gateway errors to typed exceptions.
 * - Logs request metadata only (method, path, status, code, duration) —
 *   request and response bodies are never logged, which keeps card data and
 *   credentials out of log files by construction.
 */
final class Client implements SingaPayClientInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SingaPayConfig $config,
        private readonly AccessTokenManager $tokens,
        private readonly IdentityTokenManager $identityTokens,
        private readonly RequestSigner $signer,
        private readonly JsonNormalizerInterface $normalizer,
        private readonly JakartaClock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(ApiRequest $request): Response
    {
        if ($request->signed && ! $this->config->moneyOutEnabled) {
            throw MoneyOutDisabledException::create("{$request->method} {$request->path}");
        }

        return $this->attempt($request, allowTokenRetry: true);
    }

    /**
     * Execute the request once, optionally allowing a single token-refresh retry.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function attempt(ApiRequest $request, bool $allowTokenRetry): Response
    {
        $baseUrl = $this->config->baseUrl($request->host);
        $endpoint = $request->endpoint();
        $token = $this->tokenFor($request->host);

        $startedAt = microtime(true);

        try {
            $httpResponse = $this->dispatch($request, $baseUrl, $endpoint, $token);
        } catch (HttpConnectionException $exception) {
            $this->log($request, null, $startedAt);

            throw ConnectionException::to($baseUrl.$endpoint, $exception);
        }

        $response = Response::fromHttp($httpResponse);

        $this->log($request, $response, $startedAt);

        if ($response->successful()) {
            return $response;
        }

        if ($allowTokenRetry && $this->tokenRejected($response)) {
            $this->forgetTokenFor($request->host);

            return $this->attempt($request, allowTokenRetry: false);
        }

        throw RequestException::fromResponse($response);
    }

    /**
     * Build and fire the underlying HTTP request.
     *
     * @throws HttpConnectionException
     */
    private function dispatch(ApiRequest $request, string $baseUrl, string $endpoint, string $token): HttpResponse
    {
        $pending = $this->pendingRequest($baseUrl, $request, $token);

        if ($request->signed) {
            // Send the exact normalized bytes that were hashed for the
            // signature, so wire body and signature can never disagree.
            $timestamp = $this->clock->unixSeconds();
            $normalized = $this->normalizer->normalize($request->body ?? []);

            return $pending
                ->withHeaders([
                    'X-Timestamp' => (string) $timestamp,
                    'X-Signature' => $this->signer->signHashedBody(
                        $request->method,
                        $endpoint,
                        $token,
                        hash('sha256', $normalized),
                        $timestamp,
                        $this->config->requireClientSecret(),
                    ),
                ])
                ->withBody($normalized, 'application/json')
                ->send($request->method, $endpoint);
        }

        return $pending->send(
            $request->method,
            $endpoint,
            $request->body === null ? [] : ['json' => $request->body],
        );
    }

    private function pendingRequest(string $baseUrl, ApiRequest $request, string $token): PendingRequest
    {
        $pending = $this->http
            ->baseUrl($baseUrl)
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->withToken($token);

        if ($request->host !== Host::Identity) {
            $pending = $pending->withHeaders(['X-PARTNER-ID' => $this->config->requirePartnerId()]);
        }

        // Only idempotent reads are ever retried automatically.
        if ($request->method === 'GET' && $this->config->retryTimes > 0) {
            $pending = $pending->retry(
                $this->config->retryTimes,
                $this->config->retrySleep,
                fn (Throwable $exception): bool => $exception instanceof HttpConnectionException
                    || ($exception instanceof HttpRequestException && $exception->response->serverError()),
                throw: false,
            );
        }

        return $pending;
    }

    private function tokenFor(Host $host): string
    {
        return $host === Host::Identity
            ? $this->identityTokens->token()
            : $this->tokens->token($host);
    }

    private function forgetTokenFor(Host $host): void
    {
        if ($host === Host::Identity) {
            $this->identityTokens->forget();

            return;
        }

        $this->tokens->forget($host);
    }

    private function tokenRejected(Response $response): bool
    {
        return $response->status === 401 || ($response->code?->requiresTokenRefresh() ?? false);
    }

    /**
     * Log request metadata. Bodies are deliberately never logged: signed
     * payloads and card fields must not end up in log files.
     */
    private function log(ApiRequest $request, ?Response $response, float $startedAt): void
    {
        if (! $this->config->loggingEnabled) {
            return;
        }

        $this->logger->info('singapay.request', [
            'method' => $request->method,
            'path' => $request->path,
            'host' => $request->host->value,
            'signed' => $request->signed,
            'status' => $response?->status,
            'response_code' => $response?->code?->value,
            'successful' => $response?->successful(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
