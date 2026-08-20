<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

use Aliziodev\Singapay\Charges\Charges;
use Aliziodev\Singapay\Exceptions\ChargeException;

/**
 * Money-in payment methods supported by the unified charge API
 * ({@see Charges}).
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
