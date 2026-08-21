<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\Environment;
use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Enums\TransactionStatus;
use Aliziodev\Singapay\Exceptions\AuthenticationException;
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\InvalidSignatureException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Exceptions\ValidationException;

covers(ResponseCode::class, TransactionStatus::class, Environment::class);

it('describes every response code', function (): void {
    foreach (ResponseCode::cases() as $code) {
        expect($code->description())->not->toBe('');
    }
});

it('flags ambiguous outcomes that require an inquiry-status call', function (): void {
    expect(ResponseCode::TransactionFailure->shouldInquireStatus())->toBeTrue()
        ->and(ResponseCode::Timeout->shouldInquireStatus())->toBeTrue()
        ->and(ResponseCode::Success->shouldInquireStatus())->toBeFalse()
        ->and(ResponseCode::InsufficientBalance->shouldInquireStatus())->toBeFalse();
});

it('only marks transient server failures as retryable', function (): void {
    $retryable = array_filter(ResponseCode::cases(), fn (ResponseCode $c): bool => $c->isRetryable());

    expect(array_values($retryable))->toBe([ResponseCode::GeneralFailure]);
});

it('maps codes to their dedicated exception classes', function (): void {
    expect(ResponseCode::InsufficientBalance->exceptionClass())->toBe(InsufficientBalanceException::class)
        ->and(ResponseCode::DuplicateReferenceNumber->exceptionClass())->toBe(DuplicateReferenceException::class)
        ->and(ResponseCode::Unauthorized->exceptionClass())->toBe(AuthenticationException::class)
        ->and(ResponseCode::SignatureInvalid->exceptionClass())->toBe(InvalidSignatureException::class)
        ->and(ResponseCode::UnauthorizedIp->exceptionClass())->toBe(IpNotWhitelistedException::class)
        ->and(ResponseCode::ValidationError->exceptionClass())->toBe(ValidationException::class)
        ->and(ResponseCode::NotFound->exceptionClass())->toBe(RequestException::class);
});

it('marks only SP013 as requiring a token refresh', function (): void {
    $refresh = array_filter(ResponseCode::cases(), fn (ResponseCode $c): bool => $c->requiresTokenRefresh());

    expect(array_values($refresh))->toBe([ResponseCode::Unauthorized]);
});

it('knows which transaction statuses are terminal', function (): void {
    expect(TransactionStatus::Success->isTerminal())->toBeTrue()
        ->and(TransactionStatus::Failed->isTerminal())->toBeTrue()
        ->and(TransactionStatus::Refunded->isTerminal())->toBeTrue()
        ->and(TransactionStatus::Initiated->isTerminal())->toBeFalse()
        ->and(TransactionStatus::Paying->isTerminal())->toBeFalse()
        ->and(TransactionStatus::Pending->isTerminal())->toBeFalse();
});

it('treats only status 00 as successful', function (): void {
    expect(TransactionStatus::Success->isSuccessful())->toBeTrue()
        ->and(TransactionStatus::Refunded->isSuccessful())->toBeFalse();
});

it('identifies the production environment', function (): void {
    expect(Environment::Production->isProduction())->toBeTrue()
        ->and(Environment::Sandbox->isProduction())->toBeFalse();
});

it('recognises the undocumented SP403 account-credential code', function (): void {
    // Observed in sandbox on money-out endpoints when the merchant-wide
    // Default credential is used for an account that needs its own key.
    expect(ResponseCode::tryFrom('SP403'))->toBe(ResponseCode::AccountCredentialRequired)
        ->and(ResponseCode::AccountCredentialRequired->description())->toContain('credential that owns it')
        ->and(ResponseCode::AccountCredentialRequired->shouldInquireStatus())->toBeFalse()
        ->and(ResponseCode::AccountCredentialRequired->isRetryable())->toBeFalse();
});
