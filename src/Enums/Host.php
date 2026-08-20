<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

/**
 * The three SingaPay service hosts.
 *
 * SingaPay is split across separate hosts with different authentication:
 * the payment B2B host (access token v1.1/v1.0), the biller host (access
 * token v1.0 Basic auth), and the identity-verification host (its own
 * HMAC-SHA256 credential exchange — see scheme D).
 */
enum Host: string
{
    case Payment = 'payment';
    case Biller = 'biller';
    case Identity = 'identity';
}
