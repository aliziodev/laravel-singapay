<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http;

use Aliziodev\Singapay\Enums\Host;

/**
 * Immutable description of a single SingaPay API call.
 *
 * Endpoint classes build these and hand them to the client; the fake client
 * records them, which is what powers `SingaPay::assertSent()` in tests.
 */
final readonly class ApiRequest
{
    /**
     * @param  string  $method  HTTP method, uppercase (GET, POST, PUT, PATCH, DELETE).
     * @param  string  $path  Absolute path including the /api prefix, without the host.
     * @param  array<string, mixed>  $query  Query parameters, appended to the path.
     * @param  array<string, mixed>|null  $body  JSON body, or null for body-less requests.
     * @param  bool  $signed  Whether the request signature (scheme C) is required.
     * @param  Host  $host  Which SingaPay service host receives the call.
     * @param  bool  $moneyOut  Whether this call moves funds *out* of the merchant
     *                          account, and is therefore subject to the money-out guard. Not the same
     *                          thing as `$signed`: direct-debit charge is signed but collects money,
     *                          so locking it behind the guard would force merchants to unlock real
     *                          disbursement just to accept payments.
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public ?array $body = null,
        public bool $signed = false,
        public Host $host = Host::Payment,
        public bool $moneyOut = false,
    ) {}

    /**
     * The path with the query string appended — the exact ENDPOINT component
     * used in the request signature, which must match the URL byte-for-byte.
     */
    public function endpoint(): string
    {
        if ($this->query === []) {
            return $this->path;
        }

        return $this->path.'?'.http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }
}
