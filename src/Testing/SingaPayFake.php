<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Testing;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Closure;
use Illuminate\Support\Str;

/**
 * Drop-in replacement for the {@see SingaPay} manager used in tests.
 *
 * Installed by `SingaPay::fake()`: all endpoint groups keep working exactly
 * as in production, but requests are recorded instead of sent, and
 * responses come from fixtures. Assertions inspect the recorded requests:
 *
 * ```php
 * $fake = SingaPay::fake([
 *     '*payment-link-manage*' => ['payment_url' => 'https://pay.test/x'],
 * ]);
 *
 * $this->post('/checkout', [...])->assertOk();
 *
 * $fake->assertPaymentLinkCreated(fn (array $body) => $body['reff_no'] === 'INV-001');
 * ```
 */
class SingaPayFake extends SingaPay
{
    public function __construct(
        private readonly FakeSingaPayClient $fakeClient,
        SingaPayConfig $config,
    ) {
        parent::__construct($fakeClient, $config);
    }

    /**
     * Register (or replace) a fixture after faking.
     *
     * @param  array<array-key, mixed>|Response|Closure  $response
     */
    public function stub(string $pathPattern, array|Response|Closure $response): self
    {
        $this->fakeClient->stub($pathPattern, $response);

        return $this;
    }

    /**
     * The recorded requests, optionally filtered.
     *
     * @param  (callable(ApiRequest): bool)|null  $filter
     * @return list<ApiRequest>
     */
    public function recorded(?callable $filter = null): array
    {
        return $this->fakeClient->recorded($filter);
    }

    /**
     * Assert at least one request was sent matching a path pattern
     * (with `*` wildcards) or a callback receiving the {@see ApiRequest}.
     *
     * @param  string|callable(ApiRequest): bool  $matcher
     */
    public function assertSent(string|callable $matcher): void
    {
        $this->fakeClient->assertSent($matcher);
    }

    /**
     * Assert no request matching the constraint was sent.
     *
     * @param  string|callable(ApiRequest): bool  $matcher
     */
    public function assertNotSent(string|callable $matcher): void
    {
        $this->fakeClient->assertNotSent($matcher);
    }

    /**
     * Assert no requests were sent at all.
     */
    public function assertNothingSent(): void
    {
        $this->fakeClient->assertNothingSent();
    }

    /**
     * Assert exactly this many requests were sent.
     */
    public function assertSentCount(int $count): void
    {
        $this->fakeClient->assertSentCount($count);
    }

    /**
     * Assert a payment link was created, optionally constrained by a
     * callback receiving the request body.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertPaymentLinkCreated(?callable $callback = null): void
    {
        $this->fakeClient->assertSent(
            fn (ApiRequest $request): bool => $request->method === 'POST'
                && Str::is('/api/v2.0/payment-link/*', $request->path)
                && ($callback === null || $callback($request->body ?? []))
        );
    }

    /**
     * Assert a disbursement transfer was requested, optionally constrained
     * by a callback receiving the request body.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertDisbursementRequested(?callable $callback = null): void
    {
        $this->fakeClient->assertSent(
            fn (ApiRequest $request): bool => $request->path === '/api/v2.0/disbursement/transfer'
                && ($callback === null || $callback($request->body ?? []))
        );
    }
}
