<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Charges;

use Aliziodev\Singapay\Enums\PaymentMethod;
use Aliziodev\Singapay\Exceptions\ChargeException;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Support\Amount;
use Aliziodev\Singapay\Support\JakartaClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Unified money-in charge API.
 *
 * One normalized input shape creates a charge on any supported method —
 * payment link, virtual account, QRIS, or e-wallet — and the builder
 * absorbs the per-method quirks (13-digit millisecond expirations vs
 * ISO 8601, `reff_no` vs `merchant_reff_no`, item synthesis, vendor code
 * prefixes) so calling code doesn't have to:
 *
 * ```php
 * $charge = SingaPay::pay('qris', [
 *     'amount' => 150_000,
 *     'reference' => 'INV-2026-0001',
 *     'expires_at' => now()->addHour(),
 * ]);
 *
 * $charge->qrString();
 * ```
 *
 * Normalized input keys:
 * - `amount` (required) — int, {@see Amount}, or whole numeric string.
 * - `reference` — merchant reference; required for payment links.
 * - `expires_at` — DateTimeInterface, parseable string, or 13-digit
 *   millisecond string; converted to whatever the method expects.
 * - `customer` — `['name' => ..., 'email' => ..., 'phone' => ...]`.
 * - `title`, `items`, `max_usage`, `redirect_url` — payment-link extras.
 * - `bank_code` — required for virtual accounts.
 * - `vendor` — required for e-wallets (e.g. "DANA"; the EWALLET_ prefix
 *   is added automatically).
 * - `options` — raw fields merged into the final payload last, as an
 *   escape hatch for anything the normalized shape doesn't cover.
 */
class Charges
{
    public function __construct(
        protected readonly SingaPay $singapay,
        protected readonly JakartaClock $clock,
    ) {}

    /**
     * Create a charge on the given payment method.
     *
     * @param  PaymentMethod|string  $method  Enum case or alias ("va", "qris", "pl", "ewallet", ...).
     * @param  array<string, mixed>  $data  Normalized input — see the class doc block.
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     *
     * @throws ChargeException When the input cannot be mapped onto the method.
     */
    public function create(PaymentMethod|string $method, array $data, ?string $accountId = null): ChargeResult
    {
        $method = PaymentMethod::parse($method);
        $rawAmount = $this->requireField($method, $data, 'amount');

        if (is_float($rawAmount)) {
            throw ChargeException::invalidField('amount', 'floats are rejected — pass whole rupiah as an integer or Amount');
        }

        if (! is_int($rawAmount) && ! is_string($rawAmount) && ! $rawAmount instanceof Amount) {
            throw ChargeException::invalidField('amount', 'pass an integer, a whole numeric string, or an Amount');
        }

        $amount = Amount::from($rawAmount);

        $response = match ($method) {
            PaymentMethod::PaymentLink => $this->createPaymentLink($data, $amount, $accountId),
            PaymentMethod::VirtualAccount => $this->createVirtualAccount($data, $amount, $accountId),
            PaymentMethod::Qris => $this->createQris($data, $amount, $accountId),
            PaymentMethod::Ewallet => $this->createEwallet($data, $amount, $accountId),
        };

        return new ChargeResult($method, $response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPaymentLink(array $data, Amount $amount, ?string $accountId): Response
    {
        $reference = (string) $this->requireField(PaymentMethod::PaymentLink, $data, 'reference');

        // v2 computes the total from `items` when asked to, so line items no
        // longer have to be synthesized just to satisfy a total==sum rule.
        $payload = isset($data['items'])
            ? ['payment_link_type' => 'items', 'items' => $data['items']]
            : ['payment_link_type' => 'total', 'total_amount' => $amount->value];

        $payload['reff_no'] = $reference;
        $payload['max_usage'] = (int) ($data['max_usage'] ?? 1);

        if (isset($data['title'])) {
            $payload['description'] = (string) $data['title'];
        }

        if (($expires = $this->expiresAt($data)) instanceof CarbonImmutable) {
            // v2 takes any parseable date string; v1 wanted 13-digit millis.
            $payload['expired_at'] = $expires->toIso8601String();
        }

        if (isset($data['redirect_url'])) {
            $payload['success_redirect_url'] = $data['redirect_url'];
        }

        return $this->singapay->paymentLinks()->create($this->withOptions($payload, $data), $accountId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createVirtualAccount(array $data, Amount $amount, ?string $accountId): Response
    {
        $expires = $this->expiresAt($data);

        $payload = [
            'bank_code' => (string) $this->requireField(PaymentMethod::VirtualAccount, $data, 'bank_code'),
            // An expiring charge maps naturally onto a temporary VA.
            'kind' => $expires instanceof CarbonImmutable ? 'temporary' : 'permanent',
            'amount_type' => 'closed',
            'amount' => $amount->value,
        ];

        if ($expires instanceof CarbonImmutable) {
            $payload['expired_at'] = (string) $this->clock->toMilliseconds($expires);
            $payload['max_usage'] = (int) ($data['max_usage'] ?? 1);
        }

        if (isset($data['reference'])) {
            $payload['merchant_reff_no'] = (string) $data['reference'];
        }

        if ($name = $this->customer($data, 'name')) {
            $payload['name'] = $name;
        }

        return $this->singapay->virtualAccounts()->create($this->withOptions($payload, $data), $accountId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createQris(array $data, Amount $amount, ?string $accountId): Response
    {
        $payload = ['amount' => $amount->value];

        if (isset($data['reference'])) {
            $payload['merchant_reff_no'] = (string) $data['reference'];
        }

        if (($expires = $this->expiresAt($data)) instanceof CarbonImmutable) {
            $payload['expired_at'] = $expires->toIso8601String();
        }

        return $this->singapay->qris()->generate($this->withOptions($payload, $data), $accountId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createEwallet(array $data, Amount $amount, ?string $accountId): Response
    {
        $vendor = strtoupper((string) $this->requireField(PaymentMethod::Ewallet, $data, 'vendor'));

        $payload = [
            'amount' => $amount->value,
            'ewallet_vendor' => str_starts_with($vendor, 'EWALLET_') ? $vendor : "EWALLET_{$vendor}",
        ];

        if (isset($data['reference'])) {
            $payload['merchant_reff_no'] = (string) $data['reference'];
        }

        if (($expires = $this->expiresAt($data)) instanceof CarbonImmutable) {
            $payload['expired_at'] = $expires->toIso8601String();
        }

        foreach (['name', 'email', 'phone'] as $field) {
            if ($value = $this->customer($data, $field)) {
                $payload["customer_{$field}"] = $value;
            }
        }

        if (isset($data['redirect_url'])) {
            $payload['merchant_redirect_url'] = $data['redirect_url'];
        }

        if ($accountId !== null) {
            $payload['account_id'] = $accountId;
        }

        return $this->singapay->ewallet()->createOrder($this->withOptions($payload, $data));
    }

    /**
     * Interpret the normalized `expires_at` value.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ChargeException
     */
    private function expiresAt(array $data): ?CarbonImmutable
    {
        $value = $data['expires_at'] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return match (strlen($value)) {
                13 => CarbonImmutable::createFromTimestampMs((int) $value, JakartaClock::TIMEZONE),
                10 => CarbonImmutable::createFromTimestamp((int) $value, JakartaClock::TIMEZONE),
                default => throw ChargeException::invalidField(
                    'expires_at',
                    'digit-only strings must be 10-digit Unix seconds or 13-digit milliseconds'
                ),
            };
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value, JakartaClock::TIMEZONE);
            } catch (\Throwable) {
                // Never leak a third-party parse exception for a caller mistake.
                throw ChargeException::invalidField('expires_at', "could not parse [{$value}] as a date");
            }
        }

        throw ChargeException::invalidField(
            'expires_at',
            'pass a DateTimeInterface, a parseable date string, or a 10/13-digit Unix timestamp string'
        );
    }

    /**
     * Read a customer sub-field from the normalized `customer` array.
     *
     * @param  array<string, mixed>  $data
     */
    private function customer(array $data, string $field): ?string
    {
        $customer = $data['customer'] ?? [];
        $value = is_array($customer) ? ($customer[$field] ?? null) : null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Merge the caller's raw `options` escape hatch over the built payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withOptions(array $payload, array $data): array
    {
        $options = $data['options'] ?? [];

        return is_array($options) ? array_replace($payload, $options) : $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ChargeException
     */
    private function requireField(PaymentMethod $method, array $data, string $field): mixed
    {
        $value = $data[$field] ?? null;

        if ($value === null || $value === '') {
            throw ChargeException::missingField($method, $field);
        }

        return $value;
    }
}
