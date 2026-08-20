<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\InvalidSignatureException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Exceptions\ValidationException;

/**
 * SingaPay SP response codes.
 *
 * Important: a response code describes the outcome of the *request*, not the
 * transaction. SP000 means "request accepted" — always check the transaction
 * status separately before treating a payment as complete.
 */
enum ResponseCode: string
{
    case Success = 'SP000';
    case TransactionFailure = 'SP001';
    case GeneralFailure = 'SP002';
    case InsufficientBalance = 'SP003';
    case DuplicateReferenceNumber = 'SP004';
    case Timeout = 'SP005';
    case ExceedBeneficiaryLimit = 'SP006';
    case ExceedAccountLimit = 'SP007';
    case InvalidReferenceNumber = 'SP008';
    case TransactionNotFound = 'SP009';
    case BeneficiaryAccountNotFound = 'SP010';
    case BeneficiaryVendorNotActive = 'SP011';
    case BadRequest = 'SP012';
    case Unauthorized = 'SP013';
    case NotFound = 'SP014';
    case Forbidden = 'SP015';
    case SignatureInvalid = 'SP016';
    case UnauthorizedIp = 'SP017';
    case ValidationError = 'SP018';
    case GeneralError = 'SP019';
    case MerchantAccountNotFound = 'SP020';

    /**
     * Undocumented, but returned in practice: a Specific (per-account)
     * credential is required for this account and the merchant-wide Default
     * credential was used instead. Observed on money-out endpoints.
     */
    case AccountCredentialRequired = 'SP403';

    /**
     * Official description of the code.
     */
    public function description(): string
    {
        return match ($this) {
            self::Success => 'The request was accepted and processed.',
            self::TransactionFailure => 'The transaction failed during processing.',
            self::GeneralFailure => 'An internal server error occurred.',
            self::InsufficientBalance => 'The account does not have enough balance to complete the transaction.',
            self::DuplicateReferenceNumber => 'A transaction with this reference number already exists.',
            self::Timeout => 'The upstream provider did not respond in time.',
            self::ExceedBeneficiaryLimit => 'The beneficiary has reached their daily or per-transaction limit.',
            self::ExceedAccountLimit => 'Your account has reached its transaction limit.',
            self::InvalidReferenceNumber => 'The reference number does not match any transaction.',
            self::TransactionNotFound => 'No transaction exists for the given ID.',
            self::BeneficiaryAccountNotFound => 'The destination bank account could not be found.',
            self::BeneficiaryVendorNotActive => 'The payment vendor/channel is currently unavailable.',
            self::BadRequest => 'The request body is malformed or missing required fields.',
            self::Unauthorized => 'Invalid or expired access token.',
            self::NotFound => 'The requested resource or endpoint does not exist.',
            self::Forbidden => 'The token is valid but lacks permission for this action.',
            self::SignatureInvalid => 'The X-Signature header could not be verified.',
            self::UnauthorizedIp => 'The request originated from a non-whitelisted IP.',
            self::ValidationError => 'One or more fields failed validation.',
            self::GeneralError => 'An unclassified server-side error occurred.',
            self::MerchantAccountNotFound => 'The specified merchant account does not exist.',
            self::AccountCredentialRequired => 'This account requires its own Specific credential; the Default merchant credential is not accepted here.',
        };
    }

    /**
     * Whether the outcome is ambiguous and an inquiry-status call must be
     * made before deciding anything — never blindly retry these on money-out,
     * or you risk duplicating a real transfer.
     */
    public function shouldInquireStatus(): bool
    {
        return in_array($this, [self::TransactionFailure, self::Timeout], true);
    }

    /**
     * Whether the request may be retried automatically. Deliberately narrow:
     * only transient server-side failures qualify, and the SDK still never
     * auto-retries money-out operations.
     */
    public function isRetryable(): bool
    {
        return $this === self::GeneralFailure;
    }

    /**
     * Whether the access token should be refreshed and the request retried once.
     */
    public function requiresTokenRefresh(): bool
    {
        return $this === self::Unauthorized;
    }

    /**
     * The dedicated exception class thrown for this code, when one exists.
     * Codes without a dedicated class raise the generic {@see RequestException}.
     *
     * @return class-string<RequestException>
     */
    public function exceptionClass(): string
    {
        return match ($this) {
            self::InsufficientBalance => InsufficientBalanceException::class,
            self::DuplicateReferenceNumber => DuplicateReferenceException::class,
            self::Unauthorized => AuthenticationException::class,
            self::SignatureInvalid => InvalidSignatureException::class,
            self::UnauthorizedIp => IpNotWhitelistedException::class,
            self::ValidationError => ValidationException::class,
            default => RequestException::class,
        };
    }
}
