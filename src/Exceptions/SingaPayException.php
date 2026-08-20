<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use RuntimeException;

/**
 * Base exception for every error raised by the SingaPay SDK.
 *
 * Catching this class is sufficient to handle any failure originating from
 * this package, whether it is a transport error, a signature problem, or a
 * gateway-reported business error.
 */
class SingaPayException extends RuntimeException {}
