<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Exceptions\WebhookVerificationException;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Aliziodev\Singapay\Webhooks\WebhookVerifier;
use Carbon\CarbonImmutable;

covers(WebhookVerifier::class, WebhookVerificationException::class);

const WEBHOOK_SECRET = 'webhook-client-secret';
const WEBHOOK_TOKEN = 'random-gateway-token';
const WEBHOOK_ENDPOINT = '/webhooks/singapay';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));
    $this->verifier = new WebhookVerifier(new RequestSigner(new JsonNormalizer), new JakartaClock);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Compute the signature exactly as the gateway's documented sample does:
 * parse, recursively ksort, re-encode unescaped, SHA-256, then HMAC-SHA512
 * over "POST:{endpoint}:{token}:{hash}:{timestamp}".
 */
function gatewaySignature(string $rawBody, ?int $timestamp = null, string $endpoint = WEBHOOK_ENDPOINT): string
{
    $timestamp ??= CarbonImmutable::now()->getTimestamp();

    $body = json_decode($rawBody, true);
    $sort = function (array &$value) use (&$sort): void {
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $sort($item);
            }
        }
    };
    $sort($body);

    $hash = hash('sha256', (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return hash_hmac('sha512', "POST:{$endpoint}:".WEBHOOK_TOKEN.":{$hash}:{$timestamp}", WEBHOOK_SECRET);
}

function verifyDelivery(WebhookVerifier $verifier, string $rawBody, ?string $signature = null, ?string $timestamp = null, string $endpoint = WEBHOOK_ENDPOINT): void
{
    $now = (string) CarbonImmutable::now()->getTimestamp();

    $verifier->verify(
        rawBody: $rawBody,
        signature: $signature ?? gatewaySignature($rawBody, (int) ($timestamp ?? $now), $endpoint),
        timestamp: $timestamp ?? $now,
        authorization: 'Bearer '.WEBHOOK_TOKEN,
        endpoint: $endpoint,
        clientSecret: WEBHOOK_SECRET,
        toleranceSeconds: 300,
    );
}

it('accepts a correctly signed delivery', function (): void {
    verifyDelivery($this->verifier, '{"event":"va-transaction","data":{"transaction":{"reff_no":"INV-1"}}}');

    expect(true)->toBeTrue();
});

it('accepts unsorted and unicode bodies by re-normalizing before hashing', function (): void {
    verifyDelivery($this->verifier, '{"z":"last","a":"first","nama":"Café — Hanafi","url":"https://gonsu.id/x"}');

    expect(true)->toBeTrue();
});

it('accepts a signature computed over the raw body bytes (fallback)', function (): void {
    $rawBody = '{"b":2,"a":1}';
    $timestamp = CarbonImmutable::now()->getTimestamp();

    $rawHash = hash('sha256', $rawBody);
    $signature = hash_hmac('sha512', 'POST:'.WEBHOOK_ENDPOINT.':'.WEBHOOK_TOKEN.":{$rawHash}:{$timestamp}", WEBHOOK_SECRET);

    verifyDelivery($this->verifier, $rawBody, signature: $signature);

    expect(true)->toBeTrue();
});

it('includes the query string in the signed endpoint', function (): void {
    verifyDelivery($this->verifier, '{"a":1}', endpoint: '/webhooks/singapay?source=id');

    expect(true)->toBeTrue();
});

it('rejects a delivery whose body was tampered with', function (): void {
    $original = '{"amount":100000}';
    $tampered = '{"amount":999999}';

    verifyDelivery($this->verifier, $tampered, signature: gatewaySignature($original));
})->throws(WebhookVerificationException::class, 'does not match');

it('rejects a wrong signature', function (): void {
    verifyDelivery($this->verifier, '{"a":1}', signature: str_repeat('ab', 64));
})->throws(WebhookVerificationException::class, 'does not match');

it('rejects a signature made with the wrong secret', function (): void {
    $rawBody = '{"a":1}';
    $timestamp = CarbonImmutable::now()->getTimestamp();
    $hash = hash('sha256', $rawBody);
    $signature = hash_hmac('sha512', 'POST:'.WEBHOOK_ENDPOINT.':'.WEBHOOK_TOKEN.":{$hash}:{$timestamp}", 'wrong-secret');

    verifyDelivery($this->verifier, $rawBody, signature: $signature);
})->throws(WebhookVerificationException::class);

it('rejects a stale timestamp outside the tolerance window', function (): void {
    $old = (string) CarbonImmutable::now()->subMinutes(10)->getTimestamp();

    verifyDelivery($this->verifier, '{"a":1}', timestamp: $old);
})->throws(WebhookVerificationException::class, 'tolerance');

it('rejects missing headers', function (): void {
    $this->verifier->verify('{"a":1}', null, null, null, WEBHOOK_ENDPOINT, WEBHOOK_SECRET);
})->throws(WebhookVerificationException::class, 'missing');

it('rejects a non-numeric timestamp', function (): void {
    verifyDelivery($this->verifier, '{"a":1}', timestamp: 'yesterday');
})->throws(WebhookVerificationException::class);

it('rejects a body that is not valid JSON', function (): void {
    $timestamp = (string) CarbonImmutable::now()->getTimestamp();

    $this->verifier->verify('not-json', str_repeat('a', 128), $timestamp, 'Bearer x', WEBHOOK_ENDPOINT, WEBHOOK_SECRET);
})->throws(WebhookVerificationException::class, 'not valid JSON');
