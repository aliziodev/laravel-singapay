<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Charges;

use Aliziodev\Singapay\Enums\PaymentMethod;
use Aliziodev\Singapay\Http\Response;

/**
 * Result of a unified charge: the method it was created on, the full
 * gateway {@see Response}, and typed accessors for the artifact the
 * customer needs in order to pay — a checkout URL, a QR string, or a
 * virtual account number, depending on the method.
 */
final readonly class ChargeResult
{
    public function __construct(
        public PaymentMethod $method,
        public Response $response,
    ) {}

    /**
     * Whether the gateway accepted the charge creation. As everywhere in
     * this SDK: the payment itself is only confirmed by a webhook.
     */
    public function successful(): bool
    {
        return $this->response->successful();
    }

    /**
     * Dot-notation access into the response's data section.
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        return $this->response->data($key, $default);
    }

    /**
     * The URL to send the customer to: `payment_url` for payment links,
     * `checkout_url` for e-wallets. Null for VA and QRIS charges.
     *
     * **Null for OVO too.** OVO is push-to-pay: the gateway pushes a payment
     * request to the customer's app using `customer_phone` (which OVO, alone
     * among the vendors, requires) and returns no URL at all — verified in
     * sandbox 2026-08-21. DANA returns a web URL only; GoPay and ShopeePay
     * return a web URL plus an app deeplink in `checkout_url_app`. So always
     * branch on null instead of redirecting blindly, or an OVO checkout
     * sends the customer nowhere.
     */
    public function checkoutUrl(): ?string
    {
        return $this->stringData(match ($this->method) {
            PaymentMethod::PaymentLink => 'payment_url',
            PaymentMethod::Ewallet => 'checkout_url',
            default => null,
        });
    }

    /**
     * The EMV QR payload to render, for QRIS charges. Null otherwise.
     */
    public function qrString(): ?string
    {
        return $this->method === PaymentMethod::Qris ? $this->stringData('qr_data') : null;
    }

    /**
     * The virtual account number the customer transfers to, for VA
     * charges. Null otherwise.
     */
    public function vaNumber(): ?string
    {
        return $this->method === PaymentMethod::VirtualAccount ? $this->stringData('number') : null;
    }

    private function stringData(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $value = $this->response->data($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
