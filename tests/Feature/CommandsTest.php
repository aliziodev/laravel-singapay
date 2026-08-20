<?php

declare(strict_types=1);

use Aliziodev\Singapay\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('publishes config and migrations via singapay:install', function (): void {
    $configTarget = config_path('singapay.php');
    File::delete($configTarget);

    $this->artisan('singapay:install')->assertSuccessful();

    expect(File::exists($configTarget))->toBeTrue();

    File::delete($configTarget);

    foreach (File::glob(database_path('migrations/*create_singapay_webhook_events_table.php')) as $file) {
        File::delete($file);
    }
});

it('fetches and prints a truncated token', function (): void {
    Http::fake(tokenEndpointFixtures());

    $this->artisan('singapay:token')
        ->expectsOutputToContain('use --full to reveal')
        ->assertSuccessful();
});

it('prints the complete token with --full', function (): void {
    Http::fake(tokenEndpointFixtures());

    $this->artisan('singapay:token', ['--full' => true])
        ->expectsOutputToContain('test-access-token')
        ->assertSuccessful();
});

it('fails gracefully when the token request is rejected', function (): void {
    Http::fake([
        '*access-token*' => Http::response(['status' => 401, 'success' => false, 'error' => ['code' => 401, 'message' => 'Invalid credentials']], 401),
    ]);

    $this->artisan('singapay:token')->assertFailed();
});

it('reports healthy connectivity via singapay:ping', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*balance-inquiry*' => Http::response([
            'status' => 200,
            'success' => true,
            'data' => ['available_balance' => ['value' => '1000000.00', 'currency' => 'IDR']],
        ]),
    ]);

    $this->artisan('singapay:ping')
        ->expectsOutputToContain('looks good')
        ->assertSuccessful();
});

it('diagnoses the IP whitelist failure mode via singapay:ping', function (): void {
    Http::fake([
        ...tokenEndpointFixtures(),
        '*balance-inquiry*' => Http::response(['response_code' => 'SP017', 'response_message' => 'Unauthorized IP', 'data' => null], 403),
    ]);

    $this->artisan('singapay:ping')
        ->expectsOutputToContain('egress IP')
        ->assertFailed();
});

it('computes a signature from a JSON file via singapay:verify-signature', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'singapay');
    file_put_contents((string) $file, '{"b":2,"a":1}');

    $hashedBody = hash('sha256', '{"a":1,"b":2}');
    $expected = hash_hmac('sha512', "POST:/api/test:tok:{$hashedBody}:1755657600", TestCase::CLIENT_SECRET);

    $this->artisan('singapay:verify-signature', [
        'file' => $file,
        '--endpoint' => '/api/test',
        '--token' => 'tok',
        '--timestamp' => '1755657600',
    ])
        ->expectsOutputToContain($expected)
        ->assertSuccessful();

    unlink((string) $file);
});

it('rejects missing or invalid signature input files', function (): void {
    $this->artisan('singapay:verify-signature', ['file' => 'does-not-exist.json'])->assertFailed();

    $file = tempnam(sys_get_temp_dir(), 'singapay');
    file_put_contents((string) $file, 'not-json');

    $this->artisan('singapay:verify-signature', ['file' => $file])->assertFailed();

    unlink((string) $file);
});

it('rejects payloads with floats in singapay:verify-signature', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'singapay');
    file_put_contents((string) $file, '{"amount":100000.5}');

    $this->artisan('singapay:verify-signature', ['file' => $file])->assertFailed();

    unlink((string) $file);
});
