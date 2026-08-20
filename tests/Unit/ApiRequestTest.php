<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Http\ApiRequest;

covers(ApiRequest::class);

it('returns the bare path when there is no query', function (): void {
    expect((new ApiRequest('GET', '/api/v1.0/accounts'))->endpoint())->toBe('/api/v1.0/accounts');
});

it('appends an RFC 3986 encoded query string', function (): void {
    $request = new ApiRequest('GET', '/api/v1.0/va-transactions/ACC', query: [
        'status' => 'paid',
        'merchant_reff_no' => 'INV 2026/001',
    ]);

    expect($request->endpoint())
        ->toBe('/api/v1.0/va-transactions/ACC?status=paid&merchant_reff_no=INV%202026%2F001');
});

it('defaults to an unsigned payment-host request', function (): void {
    $request = new ApiRequest('POST', '/api/v1.0/accounts', body: ['name' => 'x']);

    expect($request->signed)->toBeFalse()
        ->and($request->host)->toBe(Host::Payment)
        ->and($request->query)->toBe([]);
});
