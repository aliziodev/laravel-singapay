<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\Support\SingaPayConfig;

/**
 * Base class for endpoint groups.
 *
 * Endpoint classes are thin, typed wrappers: they know paths, path-parameter
 * types, and which calls require the money-out signature — everything else
 * (auth, signing, retries, envelopes, errors) lives in the client.
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly SingaPayClientInterface $client,
        protected readonly SingaPayConfig $config,
    ) {}

    /**
     * Execute a request through the configured client.
     */
    protected function send(ApiRequest $request): Response
    {
        return $this->client->send($request);
    }

    /**
     * Resolve an explicit account ULID or fall back to the configured
     * default (`singapay.account_id`), URL-encoded for safe interpolation
     * into a request path. Use {@see withAccountId()} for body values.
     */
    protected function accountId(?string $accountId): string
    {
        return $this->segment($accountId ?? $this->config->requireAccountId());
    }

    /**
     * URL-encode a path segment.
     *
     * Every identifier interpolated into a path goes through this, so a
     * value that originated from user input (an ID with "../" or "?",
     * for example) can never break out of its path segment, reach a
     * different endpoint, or smuggle query parameters into the signed
     * ENDPOINT string.
     */
    protected function segment(string|int $value): string
    {
        return rawurlencode((string) $value);
    }

    /**
     * Merge the default account ULID into a request body when absent.
     *
     * Used by v2 endpoints that carry `account_id` in the body instead of
     * the path.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withAccountId(array $data): array
    {
        $data['account_id'] ??= $this->config->requireAccountId();

        return $data;
    }
}
