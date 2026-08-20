# Extensibility

> 🇮🇩 Versi Bahasa Indonesia: [docs/extensibility.md](../extensibility.md)

The SDK is built around contracts so its parts can be replaced without forking.

## Swapping token storage

Default: tokens live in the Laravel cache with an atomic lock. For another store, implement `TokenRepositoryInterface` and rebind:

```php
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;

$this->app->singleton(TokenRepositoryInterface::class, VaultTokenRepository::class);
```

The contract is small: `get`, `put`, `forget`, and `withLock(key, callback)`.

## Swapping the JSON normalizer

Almost certainly unnecessary — but if SingaPay ever changes its canonicalization rules, rebind `JsonNormalizerInterface`. Every signer and the client consume it through the contract. **Re-run the signature vectors** after swapping.

## Replacing the transport entirely

`SingaPayClientInterface` is the single transport boundary (`send(ApiRequest): Response`). `SingaPay::fake()` works through exactly this seam; decorators (e.g. for metrics) attach here too:

```php
$this->app->extend(SingaPayClientInterface::class,
    fn ($client) => new MeteredClient($client, app(Metrics::class)));
```

## Calling endpoints the SDK doesn't wrap yet

No need to wait for a package release:

```php
use Aliziodev\Singapay\Http\ApiRequest;

$response = SingaPay::client()->send(new ApiRequest(
    'POST',
    '/api/v9.9/brand-new-endpoint',
    body: ['foo' => 'bar'],
    signed: true, // if that endpoint requires the request signature
));
```

All transport facilities (tokens, signatures, the money-out guard, error mapping) still apply.

## A dedicated log channel

```env
SINGAPAY_LOG_CHANNEL=singapay
```

```php
// config/logging.php
'singapay' => ['driver' => 'daily', 'path' => storage_path('logs/singapay.log'), 'days' => 30],
```

The SDK still writes metadata only — bodies are never logged, whatever the channel.

## Extra webhook middleware

```php
// config/singapay.php
'webhooks' => [
    // ...
    'middleware' => ['throttle:120,1'],
],
```

Extra middleware runs **after** signature verification.

## Disabling the built-in webhook route

Set `SINGAPAY_WEBHOOKS_ENABLED=false` and register your own route. Use `Webhooks\WebhookVerifier` for verification — don't reimplement the crypto:

```php
Route::post('my/custom/hook', function (Request $request, WebhookVerifier $verifier) {
    $verifier->verify(
        rawBody: $request->getContent(),
        signature: $request->header('X-Signature'),
        timestamp: $request->header('X-Timestamp'),
        authorization: $request->header('Authorization'),
        endpoint: $request->getRequestUri(),
        clientSecret: config('singapay.client_secret'),
    );

    // ...
});
```

## Events as the primary extension point

The webhook flow is deliberately built on Laravel events: attach as many listeners as you like, queue them, or subscribe to `WebhookReceived` to catch types the SDK doesn't know yet — all without touching package code.
