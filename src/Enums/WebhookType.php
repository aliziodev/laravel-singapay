<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

use Aliziodev\Singapay\Events;
use Illuminate\Support\Arr;

/**
 * The thirteen SingaPay webhook types.
 *
 * Several types share one callback URL (`transaction_notif_url` for money-in,
 * `disbursement_notif_url` for money-out), so deliveries are discriminated by
 * payload — primarily the top-level `event` field — never by URL.
 * {@see WebhookType::fromPayload()} implements the official discrimination
 * rules plus the documented fallback for payment-link deliveries, whose
 * example payload carries no `event` field at all.
 */
enum WebhookType: string
{
    case VirtualAccount = 'va-transaction';
    case QrisAcquirer = 'qris-acquirer-transaction';
    case PaymentLink = 'payment-link-transaction';
    case EwalletMoneyIn = 'ewallet-native-transaction';
    case SubscriptionCycle = 'subscription-cycle';
    case Disbursement = 'disbursement';
    case EwalletTopup = 'ewallet-topup';
    case QrisIssuer = 'qris-issuer';
    case Settlement = 'settlement';
    case DirectDebit = 'direct-debit';
    case PaymentLinkInquiry = 'payment-link-inquiry';
    case ProductExpiration = 'product-expiration';
    case TransactionExpiration = 'transaction-expiration';

    /**
     * Identify the webhook type from a decoded payload.
     *
     * Returns null for payloads that match no known type; the generic
     * {@see Events\WebhookReceived} event is still dispatched for those, so
     * new webhook types SingaPay introduces are never silently dropped.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $event = $payload['event'] ?? null;

        if (is_string($event) && $event !== '') {
            $type = self::fromEventName($event);

            if ($type instanceof self) {
                return $type;
            }
        }

        // Payment-link transaction deliveries may omit the `event` field;
        // fall back to the documented payload-shape discriminators.
        if (Arr::get($payload, 'data.transaction.type') === 'pl'
            || Arr::get($payload, 'data.payment.method') === 'payment_link') {
            return self::PaymentLink;
        }

        return null;
    }

    /**
     * Map an `event` field value onto a webhook type.
     */
    private static function fromEventName(string $event): ?self
    {
        $exact = self::tryFrom($event);

        if ($exact instanceof self) {
            return $exact;
        }

        // The docs spell the two expiration events with underscores while the
        // gateway sends hyphens (verified against a live delivery). Normalise
        // rather than bet on one spelling.
        $normalized = str_replace('_', '-', $event);

        if ($normalized !== $event) {
            $fallback = self::tryFrom($normalized);

            if ($fallback instanceof self) {
                return $fallback;
            }
        }

        return match (true) {
            str_starts_with($event, 'subscription.') => self::SubscriptionCycle,
            str_starts_with($event, 'settlement.') => self::Settlement,
            str_starts_with($event, 'payment_link.inquiry') => self::PaymentLinkInquiry,
            str_starts_with($event, 'direct-debit') || str_starts_with($event, 'direct_debit') => self::DirectDebit,
            default => null,
        };
    }

    /**
     * Instantiate the dedicated event class for this webhook type.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function makeEvent(array $payload): Events\WebhookReceived
    {
        $class = $this->eventClass();

        return new $class($payload);
    }

    /**
     * The dedicated event class dispatched for this webhook type.
     *
     * @return class-string<Events\WebhookReceived>
     */
    public function eventClass(): string
    {
        return match ($this) {
            self::VirtualAccount => Events\VirtualAccountPaid::class,
            self::QrisAcquirer => Events\QrisAcquirerPaid::class,
            self::PaymentLink => Events\PaymentLinkPaid::class,
            self::EwalletMoneyIn => Events\EwalletPaid::class,
            self::SubscriptionCycle => Events\SubscriptionCycleProcessed::class,
            self::Disbursement => Events\DisbursementProcessed::class,
            self::EwalletTopup => Events\EwalletTopupProcessed::class,
            self::QrisIssuer => Events\QrisIssuerProcessed::class,
            self::Settlement => Events\SettlementProcessed::class,
            self::DirectDebit => Events\DirectDebitBindingUpdated::class,
            self::PaymentLinkInquiry => Events\PaymentLinkInquiryReceived::class,
            self::ProductExpiration => Events\ProductsExpired::class,
            self::TransactionExpiration => Events\MoneyInTransactionsExpired::class,
        };
    }
}
