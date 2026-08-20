<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\AccessTokenSigner;
use Aliziodev\Singapay\Auth\IdentitySigner;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\JsonNormalizer;
use Carbon\CarbonImmutable;

covers(AccessTokenSigner::class, RequestSigner::class, IdentitySigner::class);

afterEach(fn () => CarbonImmutable::setTestNow());

describe('AccessTokenSigner (scheme A)', function (): void {
    it('signs "{client_id}_{client_secret}_{YYYYMMDD}" with HMAC-SHA512', function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Jakarta'));

        $signer = new AccessTokenSigner(new JakartaClock);

        // Independent expectation, computed inline — not via the signer.
        $expected = hash_hmac('sha512', 'my-client_my-secret_20260820', 'my-secret');

        expect($signer->payload('my-client', 'my-secret'))->toBe('my-client_my-secret_20260820')
            ->and($signer->sign('my-client', 'my-secret'))->toBe($expected)
            ->and($signer->sign('my-client', 'my-secret'))->toHaveLength(128);
    });

    it('uses the Jakarta date even when UTC is still yesterday', function (): void {
        // 20:00 UTC on Aug 19 = 03:00 WIB on Aug 20.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 20:00:00', 'UTC'));

        $signer = new AccessTokenSigner(new JakartaClock);

        expect($signer->payload('cid', 'sec'))->toEndWith('_20260820');
    });
});

describe('RequestSigner (scheme C)', function (): void {
    it('builds the colon-separated string-to-sign with an uppercase method', function (): void {
        $signer = new RequestSigner(new JsonNormalizer);

        expect($signer->stringToSign('post', '/api/v2.0/disbursement/transfer?x=1', 'tok', 'hash', 1755657600))
            ->toBe('POST:/api/v2.0/disbursement/transfer?x=1:tok:hash:1755657600');
    });

    it('signs the string-to-sign with HMAC-SHA512', function (): void {
        $signer = new RequestSigner(new JsonNormalizer);

        $expected = hash_hmac('sha512', 'POST:/e:tok:h:123', 'secret');

        expect($signer->signHashedBody('POST', '/e', 'tok', 'h', 123, 'secret'))->toBe($expected);
    });

    it('hashes the normalized body when signing a full request', function (): void {
        $signer = new RequestSigner(new JsonNormalizer);

        $hashedBody = hash('sha256', '{"a":1,"b":2}');
        $expected = hash_hmac('sha512', "POST:/e:tok:{$hashedBody}:123", 'secret');

        expect($signer->sign('POST', '/e', 'tok', ['b' => 2, 'a' => 1], 123, 'secret'))->toBe($expected);
    });
});

describe('IdentitySigner (scheme D)', function (): void {
    it('signs "{client_id}:{timestamp}" with HMAC-SHA256 — not SHA-512, not underscores', function (): void {
        $signer = new IdentitySigner(new JakartaClock);

        $expected = hash_hmac('sha256', 'kc_live_a3f2c4:2026-05-26T07:30:00Z', 'kyc-secret');

        expect($signer->sign('kc_live_a3f2c4', '2026-05-26T07:30:00Z', 'kyc-secret'))->toBe($expected)
            ->and($signer->sign('kc_live_a3f2c4', '2026-05-26T07:30:00Z', 'kyc-secret'))->toHaveLength(64);
    });

    it('produces an RFC 3339 UTC timestamp', function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 14:30:45', 'Asia/Jakarta'));

        expect((new IdentitySigner(new JakartaClock))->timestamp())->toBe('2026-05-26T07:30:45Z');
    });
});
