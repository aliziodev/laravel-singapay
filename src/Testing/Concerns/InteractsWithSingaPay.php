<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Testing\Concerns;

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Testing\SingaPayFake;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test helpers for applications consuming this package.
 *
 * ```php
 * use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;
 *
 * it('handles a VA payment webhook', function () {
 *     $this->postSingaPayWebhook(['event' => 'va-transaction', 'data' => [...]])
 *         ->assertOk();
 * });
 * ```
 */
trait InteractsWithSingaPay
{
    /**
     * Swap the SDK for a recording fake. Shorthand for `SingaPay::fake()`.
     *
     * @param  array<string, mixed>  $fixtures
     */
    protected function fakeSingaPay(array $fixtures = []): SingaPayFake
    {
        return SingaPay::fake($fixtures);
    }

    /**
     * POST a payload to the package's webhook route with a valid signature,
     * exactly as SingaPay would deliver it.
     *
     * @param  array<array-key, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postSingaPayWebhook(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/'.ltrim(app(SingaPayConfig::class)->webhookPath, '/'),
            server: $this->transformHeadersToServerVars($this->singaPayWebhookHeaders($body)),
            content: $body,
        );
    }

    /**
     * Build the signed headers SingaPay would send for a raw webhook body.
     *
     * @return array<string, string>
     */
    protected function singaPayWebhookHeaders(string $rawBody, string $bearerToken = 'test-webhook-token'): array
    {
        $config = app(SingaPayConfig::class);
        $timestamp = app(JakartaClock::class)->unixSeconds();
        $endpoint = '/'.ltrim($config->webhookPath, '/');

        $decoded = json_decode($rawBody, true);
        $normalized = is_array($decoded)
            ? $this->recursiveKsort($decoded)
            : $rawBody;

        $signature = app(RequestSigner::class)->signHashedBody(
            'POST',
            $endpoint,
            $bearerToken,
            hash('sha256', is_string($normalized) ? $normalized : $rawBody),
            $timestamp,
            $config->requireClientSecret(),
        );

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$bearerToken,
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $signature,
        ];
    }

    /**
     * Normalize an array the way the gateway does before hashing.
     *
     * @param  array<array-key, mixed>  $array
     */
    private function recursiveKsort(array $array): string
    {
        $sort = function (array &$value) use (&$sort): void {
            ksort($value, SORT_STRING);

            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };

        $sort($array);

        return (string) json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
