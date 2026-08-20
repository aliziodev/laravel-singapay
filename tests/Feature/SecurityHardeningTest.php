<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Auth\IdentityTokenManager;
use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Exceptions\MoneyOutDisabledException;
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Client;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;

it('url-encodes path parameters so hostile ids cannot escape their segment', function (): void {
    Http::fake([...tokenEndpointFixtures(), '*' => Http::response(['status' => 200, 'success' => true, 'data' => []])]);

    // An id sourced from user input must not be able to traverse the path
    // or smuggle a query string into the (signed) endpoint.
    SingaPay::vaTransactions()->find('../../evil?injected=1');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/va-transactions/'.TestCase::ACCOUNT_ID.'/..%2F..%2Fevil%3Finjected%3D1')
            && ! str_contains($request->url(), 'evil?injected');
    });
});

it('url-encodes the account id itself', function (): void {
    Http::fake([...tokenEndpointFixtures(), '*' => Http::response(['status' => 200, 'success' => true, 'data' => []])]);

    SingaPay::balance()->account('acc/../other');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/balance-inquiry/acc%2F..%2Fother'));
});

it('keeps hostile ids inside one segment on uuid and reference routes', function (): void {
    Http::fake([...tokenEndpointFixtures(), '*' => Http::response(['response_code' => 'SP000', 'response_message' => 'OK', 'data' => []])]);

    SingaPay::subscriptions()->findPlan('a?b=c');
    SingaPay::directDebit()->bindingStatus('x/y');
    SingaPay::cardlessWithdrawal()->find('ref#1');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/recurring/plans/a%3Fb%3Dc'));
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/direct-debit/binding/x%2Fy'));
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/cardless-withdrawals/transaction/'.TestCase::ACCOUNT_ID.'/ref%231'));
});

it('never hands request or response bodies to the logger', function (): void {
    Http::fake([...tokenEndpointFixtures(), '*' => Http::response(['status' => 200, 'success' => true, 'data' => ['secret_field' => 'sensitive']])]);

    $collector = new class extends AbstractLogger
    {
        /** @var array<int, array{message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }
    };

    $client = new Client(
        http: app(HttpFactory::class),
        config: app(SingaPayConfig::class),
        tokens: app(AccessTokenManager::class),
        identityTokens: app(IdentityTokenManager::class),
        signer: app(RequestSigner::class),
        normalizer: app(JsonNormalizerInterface::class),
        clock: app(JakartaClock::class),
        logger: $collector,
    );

    $client->send(new ApiRequest('POST', '/api/v2.0/card/ACC/payment', body: [
        'card_number' => '4111111111111111',
        'card_cvv' => '123',
    ]));

    expect($collector->records)->not->toBeEmpty();

    $serialized = json_encode($collector->records);

    expect($serialized)->not->toContain('4111111111111111')
        ->and($serialized)->not->toContain('secret_field')
        ->and($collector->records[0]['context'])->toHaveKeys(['method', 'path', 'status', 'duration_ms']);
});

it('sends the money-out guard check before acquiring any token', function (): void {
    // No Http::fake token stub on purpose: if the guard ran after token
    // acquisition, this would attempt a real HTTP call and fail loudly.
    Http::fake();

    expect(fn () => SingaPay::ewalletMoneyOut()->triggerTopup(['reference_number' => 'R']))
        ->toThrow(MoneyOutDisabledException::class);

    Http::assertNothingSent();
});
