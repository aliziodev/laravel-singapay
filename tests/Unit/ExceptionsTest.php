<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Exceptions\SingaPayException;
use Aliziodev\Singapay\Exceptions\ValidationException;
use Aliziodev\Singapay\Http\Response;

covers(RequestException::class, ValidationException::class, IpNotWhitelistedException::class);

function failedResponse(string $code, string $message, array $data = []): Response
{
    return new Response(
        status: 400,
        code: ResponseCode::from($code),
        message: $message,
        data: $data,
        raw: ['response_code' => $code, 'response_message' => $message, 'data' => $data],
    );
}

it('builds the dedicated exception class from the response code', function (): void {
    expect(RequestException::fromResponse(failedResponse('SP003', 'Insufficient Balance')))
        ->toBeInstanceOf(InsufficientBalanceException::class)
        ->and(RequestException::fromResponse(failedResponse('SP004', 'Duplicate Reference Number')))
        ->toBeInstanceOf(DuplicateReferenceException::class)
        ->and(RequestException::fromResponse(failedResponse('SP014', 'Not Found')))
        ->toBeInstanceOf(RequestException::class);
});

it('exposes the full response and its code', function (): void {
    $exception = RequestException::fromResponse(failedResponse('SP009', 'Transaction Not Found'));

    expect($exception->responseCode())->toBe(ResponseCode::TransactionNotFound)
        ->and($exception->response->status)->toBe(400)
        ->and($exception->getMessage())->toContain('SP009')
        ->and($exception)->toBeInstanceOf(SingaPayException::class);
});

it('flags ambiguous outcomes so callers inquire before reacting', function (): void {
    expect(RequestException::fromResponse(failedResponse('SP005', 'Timeout'))->shouldInquireStatus())->toBeTrue()
        ->and(RequestException::fromResponse(failedResponse('SP012', 'Bad Request'))->shouldInquireStatus())->toBeFalse();
});

it('exposes field errors on validation failures', function (): void {
    $exception = RequestException::fromResponse(failedResponse('SP018', 'Validation Error', [
        'errors' => ['amount' => ['The amount field is required.']],
    ]));

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toBe(['amount' => ['The amount field is required.']]);
});

it('explains the serverless IP problem on SP017', function (): void {
    $exception = RequestException::fromResponse(failedResponse('SP017', 'Unauthorized IP'));

    expect($exception)->toBeInstanceOf(IpNotWhitelistedException::class)
        ->and($exception->getMessage())->toContain('egress IP')
        ->and($exception->getMessage())->toContain('Serverless');
});

it('falls back to the HTTP status when no SP code is present', function (): void {
    $response = new Response(status: 503, code: null, message: 'Service unavailable', data: [], raw: []);

    expect(RequestException::fromResponse($response)->getMessage())->toContain('HTTP 503');
});

it('explains the serverless IP problem on a bare 403 with no SP code', function (): void {
    $exception = RequestException::fromResponse(new Response(
        status: 403,
        code: null,
        message: 'Your IP address (182.10.100.149) is not registered',
        data: [],
        raw: ['status' => 403, 'success' => false, 'error' => ['code' => 403, 'message' => 'Your IP address (182.10.100.149) is not registered']],
    ));

    expect($exception)->toBeInstanceOf(IpNotWhitelistedException::class)
        ->and($exception->getMessage())->toContain('182.10.100.149')
        ->and($exception->getMessage())->toContain('egress IP');
});
