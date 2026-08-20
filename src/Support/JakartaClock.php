<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Centralized clock for every time value the SingaPay API consumes.
 *
 * SingaPay mixes several time representations: the access-token signature
 * uses a YYYYMMDD date in Asia/Jakarta (UTC+7, never UTC — the official
 * Node.js example is wrong about this), X-Timestamp headers use Unix seconds,
 * payment-link expirations use 13-digit Unix milliseconds, and the identity
 * service uses RFC 3339 UTC. Funneling all conversions through this class
 * keeps those rules in one tested place.
 *
 * Uses Carbon under the hood, so tests can freeze time with
 * {@see CarbonImmutable::setTestNow()}.
 */
class JakartaClock
{
    /**
     * The canonical SingaPay timezone (WIB, UTC+7).
     */
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Current time in the Asia/Jakarta timezone.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    /**
     * Current date as the YYYYMMDD string used by the access-token signature.
     *
     * The signature is only valid for the current Jakarta calendar day;
     * between 00:00 and 07:00 WIB a UTC-based date would be one day behind
     * and the gateway would reject the signature.
     */
    public function signatureDate(): string
    {
        return $this->now()->format('Ymd');
    }

    /**
     * Current Unix timestamp in seconds, as sent in the X-Timestamp header.
     */
    public function unixSeconds(): int
    {
        return $this->now()->getTimestamp();
    }

    /**
     * Current Unix timestamp in milliseconds (13 digits).
     */
    public function unixMilliseconds(): int
    {
        return (int) $this->now()->getTimestampMs();
    }

    /**
     * Convert a date to the 13-digit Unix millisecond format used by
     * payment-link `expired_at` and VA transaction filters.
     */
    public function toMilliseconds(DateTimeInterface $date): int
    {
        return (int) CarbonImmutable::instance($date)->getTimestampMs();
    }

    /**
     * Current time as an RFC 3339 UTC string with second precision
     * (e.g. 2026-05-26T07:30:00Z), used by the identity-verification
     * token exchange. Note: UTC, unlike every payment-API time value.
     */
    public function rfc3339Utc(): string
    {
        return $this->now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
