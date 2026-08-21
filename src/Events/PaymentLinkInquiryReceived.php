<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A customer opened (or abandoned) a payment link
 * (webhook events `payment_link.inquiry`, `payment_link.inquiry.expired`).
 */
class PaymentLinkInquiryReceived extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::PaymentLinkInquiry);
    }

    /**
     * Whether this delivery reports an expired inquiry session.
     */
    public function isExpired(): bool
    {
        return $this->event() === 'payment_link.inquiry.expired';
    }

    /**
     * The inquiry history reference — the documented idempotency key, and
     * the only way to tell which method a payment link was paid with.
     *
     * The later `payment-link-transaction` delivery reports
     * `data.payment.method` as the literal `payment_link` no matter how the
     * customer actually paid, and carries nothing about the channel. This
     * value equals that delivery's `data.transaction.reff_no`, so join the
     * two on it if you need to record "paid at Alfamart". Verified against
     * a real retail payment 2026-08-21.
     */
    public function historyReffNo(): ?string
    {
        $reff = $this->data('payment_link_history.reff_no');

        return is_scalar($reff) ? (string) $reff : null;
    }

    /**
     * The human label of the chosen method — e.g. `Alfamart (Linkqu)`,
     * `Credit Card`. Display text, not a stable code: match on
     * {@see paymentMethodAdditional()} instead where one is present.
     */
    public function paymentMethodName(): ?string
    {
        $name = $this->data('payment_link_history.payment_method_name');

        return is_scalar($name) ? (string) $name : null;
    }

    /**
     * The value the customer pays with, when the method has one: the retail
     * payment code for Alfamart/Indomaret, for instance. Not always useful —
     * a card inquiry simply echoes the history `reff_no` back here.
     */
    public function paymentMethodValue(): ?string
    {
        $value = $this->data('payment_link_history.payment_method_value');

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The method's extra detail, decoded.
     *
     * The gateway sends this field as a **JSON-encoded string**, not an
     * object, so reading it with dot notation
     * (`data('payment_link_history.payment_method_additional.retail_code')`)
     * quietly returns null. This decodes it. Retail payments carry
     * `retail_code` (`ALFAMART`/`INDOMARET`) and `partner_reff`; a card
     * inquiry carries nothing at all.
     *
     * @return array<array-key, mixed>
     */
    public function paymentMethodAdditional(): array
    {
        $additional = $this->data('payment_link_history.payment_method_additional');

        if (is_array($additional)) {
            return $additional;
        }

        if (! is_string($additional) || $additional === '') {
            return [];
        }

        $decoded = json_decode($additional, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The retail outlet code (`ALFAMART`/`INDOMARET`) when the customer chose
     * to pay at a convenience store, null otherwise.
     *
     * Retail has no endpoint of its own — it is reachable only as a
     * `whitelisted_payment_method` on a payment link — so this inquiry is
     * where a retail payment announces itself.
     */
    public function retailCode(): ?string
    {
        $code = $this->paymentMethodAdditional()['retail_code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }
}
