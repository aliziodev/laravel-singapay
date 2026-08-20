<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised for SP003: the account balance cannot cover the transaction.
 * Retrying is pointless until the balance is topped up.
 */
class InsufficientBalanceException extends RequestException {}
