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
