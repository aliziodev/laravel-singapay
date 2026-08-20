<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Testing;

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Recording transport used by `SingaPay::fake()`.
 *
 * Every {@see ApiRequest} is recorded instead of being sent, and a response
 * is resolved from the registered fixtures. Fixtures are keyed by a path
 * pattern (with `*` wildcards) and may be:
 *
 * - an array — becomes the `data` section of a successful response,
 * - a {@see Response} — returned as-is,
 * - a Closure receiving the {@see ApiRequest} — returning either of the above.
 *
 * Requests that match no fixture succeed with an empty data section, so
 * consumer code under test keeps flowing without ceremony.
 */
final class FakeSingaPayClient implements SingaPayClientInterface
{
    /**
     * @var list<ApiRequest>
     */
    private array $recorded = [];

    /**
     * @param  array<string, array<array-key, mixed>|Response|Closure>  $fixtures
     */
    public function __construct(private array $fixtures = []) {}

    public function send(ApiRequest $request): Response
    {
        $this->recorded[] = $request;

        return $this->resolveResponse($request);
    }

    /**
     * Register (or replace) a fixture after construction.
     *
     * @param  array<array-key, mixed>|Response|Closure  $response
     */
    public function stub(string $pathPattern, array|Response|Closure $response): self
    {
        $this->fixtures[$pathPattern] = $response;

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
        return $filter === null
            ? $this->recorded
            : array_values(array_filter($this->recorded, $filter));
    }

    /**
     * Assert at least one request was sent matching the path pattern or callback.
     *
     * ```php
     * $fake->assertSent('*payment-link-manage*');
     * $fake->assertSent(fn (ApiRequest $r) => $r->body['reff_no'] === 'INV-001');
     * ```
     *
     * @param  string|callable(ApiRequest): bool  $matcher
     */
    public function assertSent(string|callable $matcher): void
    {
        Assert::assertNotEmpty(
            $this->matching($matcher),
            'Expected a SingaPay request matching the given constraint, but none was sent.'
        );
    }

    /**
     * Assert no request matching the path pattern or callback was sent.
     *
     * @param  string|callable(ApiRequest): bool  $matcher
     */
    public function assertNotSent(string|callable $matcher): void
    {
        Assert::assertEmpty(
            $this->matching($matcher),
            'Unexpected SingaPay request matching the given constraint was sent.'
        );
    }

    /**
     * Assert no requests were sent at all.
     */
    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->recorded, 'Expected no SingaPay requests, but '.count($this->recorded).' were sent.');
    }

    /**
     * Assert exactly this many requests were sent.
     */
    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded);
    }

    /**
     * @param  string|callable(ApiRequest): bool  $matcher
     * @return list<ApiRequest>
     */
    private function matching(string|callable $matcher): array
    {
        $filter = is_string($matcher)
            ? fn (ApiRequest $request): bool => Str::is($matcher, $request->path)
            : $matcher;

        return $this->recorded($filter);
    }

    private function resolveResponse(ApiRequest $request): Response
    {
        foreach ($this->fixtures as $pattern => $fixture) {
            if (! Str::is($pattern, $request->path)) {
                continue;
            }

            if ($fixture instanceof Closure) {
                $fixture = $fixture($request);
            }

            if ($fixture instanceof Response) {
                return $fixture;
            }

            return $this->successResponse(is_array($fixture) ? $fixture : []);
        }

        return $this->successResponse([]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function successResponse(array $data): Response
    {
        return new Response(
            status: 200,
            code: ResponseCode::Success,
            message: 'Successfully',
            data: $data,
            raw: ['response_code' => 'SP000', 'response_message' => 'Successfully', 'data' => $data],
        );
    }
}
