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
     * default (`singapay.account_id`).
     */
    protected function accountId(?string $accountId): string
    {
        return $accountId ?? $this->config->requireAccountId();
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
