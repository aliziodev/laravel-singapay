<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

use Aliziodev\Singapay\Charges\Charges;
use Aliziodev\Singapay\Endpoints\Card;
use Aliziodev\Singapay\Exceptions\ChargeException;

/**
 * Money-in payment methods supported by the unified charge API
 * ({@see Charges}).
 *
 * These four are *SDK charge builders*, not SingaPay's catalogue of payment
 * methods — do not confuse the two. The catalogue is the ~20 codes the
 * gateway returns from `paymentLinks()->paymentMethods()`
 * (`NICEPAY_CARD`, `EWALLET_DANA`, `ALFAMART`, `VA_BRI`, …), which belong in
 * `whitelisted_payment_method` on a payment link. It is deliberately not
 * modelled as an enum: it is per-merchant, changes as SingaPay adds
 * channels, and freezing it in code would rot. Read it from the gateway.
 *
 * Three things are deliberately absent from `pay()`:
 *
 * - **Card.** Available as {@see Card::payment()}.
 *   Keeping it out of the convenience API is a safety decision: `pay()` is
 *   the easy path, and card puts your server in PCI-DSS scope.
 * - **Retail outlet** (Alfamart/Indomaret). It has no endpoint of its own —
 *   reach it through `whitelisted_payment_method` on a payment link.
 * - **Direct debit**, which is a bind-then-charge lifecycle rather than a
 *   one-shot charge, and which SingaPay has not released yet.
 */
enum PaymentMethod: string
{
    case PaymentLink = 'payment_link';
    case VirtualAccount = 'virtual_account';
    case Qris = 'qris';
    case Ewallet = 'ewallet';

    /**
     * Resolve a method from an enum instance or a forgiving string alias.
     *
     * Accepted aliases (case-insensitive, "-"/" " treated as "_"):
     * `payment_link`, `paymentlink`, `pl`, `link`, `virtual_account`,
     * `virtualaccount`, `va`, `qris`, `qr`, `ewallet`, `e_wallet`, `wallet`.
     *
     * @throws ChargeException When the value matches no known method.
     */
    public static function parse(self|string $method): self
    {
        if ($method instanceof self) {
            return $method;
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', trim($method)));

        return match ($normalized) {
            'payment_link', 'paymentlink', 'pl', 'link' => self::PaymentLink,
            'virtual_account', 'virtualaccount', 'va' => self::VirtualAccount,
            'qris', 'qr' => self::Qris,
            'ewallet', 'e_wallet', 'wallet' => self::Ewallet,
            default => throw ChargeException::unknownMethod($method),
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PaymentLink => 'Payment Link',
            self::VirtualAccount => 'Virtual Account',
            self::Qris => 'QRIS',
            self::Ewallet => 'E-Wallet',
        };
    }
}
