<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Http\Response;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as HttpResponse;

covers(Response::class);

function httpResponse(int $status, array $body): HttpResponse
{
    return new HttpResponse(new Psr7Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
}

it('normalizes the v2 envelope', function (): void {
    $response = Response::fromHttp(httpResponse(200, [
        'response_code' => 'SP000',
        'response_message' => 'Successfully',
        'data' => ['transaction_id' => 'TX-1', 'amount' => ['value' => '100.00']],
    ]));

    expect($response->successful())->toBeTrue()
        ->and($response->code)->toBe(ResponseCode::Success)
        ->and($response->message)->toBe('Successfully')
        ->and($response->data('transaction_id'))->toBe('TX-1')
        ->and($response->data('amount.value'))->toBe('100.00');
});

it('treats v2 error codes as failures', function (): void {
    $response = Response::fromHttp(httpResponse(400, [
        'response_code' => 'SP003',
        'response_message' => 'Insufficient Balance',
        'data' => null,
    ]));

    expect($response->failed())->toBeTrue()
        ->and($response->code)->toBe(ResponseCode::InsufficientBalance)
        ->and($response->data())->toBe([]);
});

it('normalizes the v1 success envelope', function (): void {
    $response = Response::fromHttp(httpResponse(200, [
        'status' => 200,
        'success' => true,
        'data' => ['access_token' => 'tok', 'expires_in' => '216000'],
    ]));

    expect($response->successful())->toBeTrue()
        ->and($response->code)->toBeNull()
        ->and($response->data('access_token'))->toBe('tok');
});

it('normalizes the v1 error envelope with the nested error message', function (): void {
    $response = Response::fromHttp(httpResponse(401, [
        'status' => 401,
        'success' => false,
        'error' => ['code' => 401, 'message' => 'Invalid credentials'],
    ]));

    expect($response->failed())->toBeTrue()
        ->and($response->message)->toBe('Invalid credentials');
});

it('normalizes flat payloads by HTTP status', function (): void {
    $response = Response::fromHttp(httpResponse(200, [
        'access_token' => 'jwt',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]));

    expect($response->successful())->toBeTrue()
        ->and($response->data('access_token'))->toBe('jwt');

    expect(Response::fromHttp(httpResponse(502, ['message' => 'oops']))->failed())->toBeTrue();
});

it('handles non-JSON bodies gracefully', function (): void {
    $response = Response::fromHttp(new HttpResponse(new Psr7Response(200, [], 'not-json')));

    expect($response->data())->toBe([])
        ->and($response->raw)->toBe([]);
});

it('collects data values', function (): void {
    $response = Response::fromHttp(httpResponse(200, [
        'status' => 200,
        'success' => true,
        'data' => [['id' => 1], ['id' => 2]],
    ]));

    expect($response->collect()->pluck('id')->all())->toBe([1, 2])
        ->and($response->toArray())->toHaveKey('success');
});

it('does not misread an unknown response_code as success', function (): void {
    $response = Response::fromHttp(httpResponse(200, [
        'response_code' => 'SP999',
        'response_message' => 'Unknown',
    ]));

    expect($response->code)->toBeNull()
        ->and($response->successful())->toBeFalse();
});

it('recognises the bare 403 the token endpoint returns for a non-whitelisted IP', function (): void {
    // Verbatim body captured from the SingaPay sandbox: no SP017 anywhere.
    $response = Response::fromHttp(httpResponse(403, [
        'status' => 403,
        'success' => false,
        'error' => ['code' => 403, 'message' => 'Your IP address (182.10.100.149) is not registered'],
    ]));

    expect($response->rejectedIp())->toBeTrue()
        ->and($response->code)->toBeNull();
});

it('recognises SP017 as an IP rejection', function (): void {
    $response = Response::fromHttp(httpResponse(403, [
        'response_code' => 'SP017',
        'response_message' => 'Unauthorized IP',
    ]));

    expect($response->rejectedIp())->toBeTrue();
});

it('does not mistake other failures for an IP rejection', function (string $body, int $status): void {
    expect(Response::fromHttp(httpResponse($status, json_decode($body, true)))->rejectedIp())->toBeFalse();
})->with([
    'permission 403' => ['{"status":403,"success":false,"error":{"code":403,"message":"You are not allowed to access this account"}}', 403],
    'expired credentials' => ['{"status":401,"success":false,"error":{"code":401,"message":"Your IP address is not registered"}}', 401],
    'successful call' => ['{"status":200,"success":true,"data":{}}', 200],
]);

it('finds field errors in either envelope generation', function (): void {
    $v1 = Response::fromHttp(httpResponse(422, [
        'status' => 422,
        'success' => false,
        'error' => ['code' => 422, 'message' => 'Validation error', 'errors' => ['bank_code' => ['invalid']]],
    ]));

    $v2 = Response::fromHttp(httpResponse(400, [
        'response_code' => 'SP018',
        'response_message' => 'Validation Error',
        'data' => ['errors' => ['amount' => ['required']]],
    ]));

    expect($v1->fieldErrors())->toBe(['bank_code' => ['invalid']])
        ->and($v2->fieldErrors())->toBe(['amount' => ['required']])
        ->and(Response::fromHttp(httpResponse(200, ['status' => 200, 'success' => true, 'data' => []]))->fieldErrors())->toBe([]);
});

it('reads the biller envelope, which reuses response_code for a non-SP code', function (): void {
    // Verbatim from openapi-biller.json: a v2 reading would call this failed,
    // because "00" is not an SP code.
    $success = Response::fromHttp(httpResponse(200, [
        'command' => 'check-balance',
        'response_code' => '00',
        'response_text' => 'Operation completed successfully',
        'data' => ['balance' => 250000],
    ]));

    expect($success->successful())->toBeTrue()
        ->and($success->code)->toBeNull()
        ->and($success->message)->toBe('Operation completed successfully')
        ->and($success->data('balance'))->toBe(250000);
});

it('treats every non-00 biller code as a failure', function (string $code): void {
    $response = Response::fromHttp(httpResponse(422, [
        'command' => 'detail-bill-transaction',
        'response_code' => $code,
        'response_text' => 'Rejected Format Error',
        'data' => ['data.transaction_id' => 'The data.transaction id field is required.'],
    ]));

    expect($response->successful())->toBeFalse()
        ->and($response->message)->toBe('Rejected Format Error');
})->with(['04', '99', '68']);

it('does not mistake a v2 envelope for a biller one', function (): void {
    $v2 = Response::fromHttp(httpResponse(200, [
        'response_code' => 'SP000',
        'response_message' => 'Successfully',
        'data' => [],
    ]));

    expect($v2->successful())->toBeTrue()
        ->and($v2->code)->toBe(ResponseCode::Success);
});

it('reads the identity (KYC) envelope', function (): void {
    // Verbatim from the KYC swagger's 200 example.
    $response = Response::fromHttp(httpResponse(200, [
        'code' => 'SUCCESS',
        'data' => ['similarity' => 100, 'status' => 'found', 'suggestion' => 'pass'],
        'message' => 'OK',
        'pricing' => 'PAID',
        'request_id' => 'TXN-20260727-001',
    ]));

    expect($response->successful())->toBeTrue()
        ->and($response->message)->toBe('OK')
        // Without the dedicated branch this reads null: `data` would be the
        // whole envelope rather than its data section.
        ->and($response->data('similarity'))->toBe(100)
        ->and($response->data('suggestion'))->toBe('pass')
        ->and($response->data('request_id'))->toBeNull()
        ->and($response->raw['pricing'])->toBe('PAID');
});

it('treats every non-SUCCESS identity code as a failure', function (string $code): void {
    $response = Response::fromHttp(httpResponse(402, [
        'code' => $code,
        'message' => 'insufficient balance',
        'pricing' => 'FREE',
        'request_id' => 'TXN-1',
    ]));

    expect($response->successful())->toBeFalse()
        ->and($response->message)->toBe('insufficient balance');
})->with(['CLIENT_ERROR', 'UNAUTHORIZED', 'DUPLICATE_REFERENCE', 'INSUFFICIENT_BALANCE', 'SERVER_ERROR', 'INTERNAL_ERROR']);
