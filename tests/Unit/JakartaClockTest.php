<?php

declare(strict_types=1);

use Aliziodev\Singapay\Support\JakartaClock;
use Carbon\CarbonImmutable;

covers(JakartaClock::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('uses the Asia/Jakarta calendar day for the signature date', function (): void {
    // 23:30 UTC on Aug 19 is already 06:30 WIB on Aug 20 — the UTC date
    // would be one day behind and the gateway would reject the signature.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 23:30:00', 'UTC'));

    expect((new JakartaClock)->signatureDate())->toBe('20260820');
});

it('returns unix seconds independent of timezone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC'));

    expect((new JakartaClock)->unixSeconds())->toBe(1_787_184_000);
});

it('returns 13-digit unix milliseconds', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 00:00:00', 'UTC'));

    expect((new JakartaClock)->unixMilliseconds())->toBe(1_787_184_000_000);
});

it('converts arbitrary dates to unix milliseconds', function (): void {
    // 12:00 WIB (UTC+7) on 2026-08-20 is 05:00 UTC.
    $date = CarbonImmutable::parse('2026-08-20 12:00:00', 'Asia/Jakarta');

    expect((new JakartaClock)->toMilliseconds($date))->toBe(1_787_202_000_000);
});

it('formats the identity timestamp as RFC 3339 UTC with second precision', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 14:30:00', 'Asia/Jakarta'));

    expect((new JakartaClock)->rfc3339Utc())->toBe('2026-05-26T07:30:00Z');
});

it('reports now in the Jakarta timezone', function (): void {
    expect((new JakartaClock)->now()->timezoneName)->toBe('Asia/Jakarta');
});
