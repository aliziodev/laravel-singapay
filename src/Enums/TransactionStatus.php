<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

/**
 * SingaPay transaction status codes (`transaction_status.code`).
 *
 * These describe the state of the *transaction* itself, as opposed to
 * {@see ResponseCode} which describes the outcome of an API request.
 */
enum TransactionStatus: string
{
    case Success = '00';
    case Initiated = '01';
    case Paying = '02';
    case Pending = '03';
    case Refunded = '04';
    case Canceled = '05';
    case Failed = '06';
    case NotFound = '07';

    /**
     * Whether the status is terminal — it will never transition further.
     * Non-terminal transactions (Initiated/Paying/Pending) may still advance
     * and should be re-checked via the relevant inquiry-status endpoint.
     */
    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Initiated, self::Paying, self::Pending], true);
    }

    /**
     * Whether funds actually moved successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }
}
