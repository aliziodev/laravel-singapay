# Ekstensibilitas

> 🇬🇧 English version: [docs/en/extensibility.md](en/extensibility.md)

SDK dirancang di sekitar kontrak sehingga bagian-bagiannya bisa diganti tanpa fork.

## Mengganti penyimpanan token

Default: token disimpan di cache Laravel dengan atomic lock. Untuk penyimpanan lain, implementasikan `TokenRepositoryInterface` lalu rebind:

```php
use Aliziodev\Singapay\Contracts\TokenRepositoryInterface;

$this->app->singleton(TokenRepositoryInterface::class, VaultTokenRepository::class);
```

Kontraknya kecil: `get`, `put`, `forget`, dan `withLock(key, callback)`.

## Mengganti normalizer JSON

Hampir pasti tidak perlu — tapi bila SingaPay mengubah aturan kanonisasinya, cukup rebind `JsonNormalizerInterface`. Semua signer dan client memakainya lewat kontrak. **Jalankan ulang signature vectors** setelah mengganti.

## Mengganti transport sepenuhnya

`SingaPayClientInterface` adalah satu-satunya batas transport (`send(ApiRequest): Response`). `SingaPay::fake()` bekerja persis lewat titik ini; decorator (mis. untuk metrics) juga bisa dipasang di sini:

```php
$this->app->extend(SingaPayClientInterface::class,
    fn ($client) => new MeteredClient($client, app(Metrics::class)));
```

Decorator berlaku untuk **semua** koneksi, bukan hanya koneksi default: client tiap koneksi dibangun lewat container, jadi instrumentasi yang Anda pasang di sini tidak akan melewatkan panggilan dari `SingaPay::connection('payouts')`.

## Memanggil endpoint yang belum dibungkus SDK

Tidak perlu menunggu rilis paket:

```php
use Aliziodev\Singapay\Http\ApiRequest;

$response = SingaPay::client()->send(new ApiRequest(
    'POST',
    '/api/v9.9/brand-new-endpoint',
    body: ['foo' => 'bar'],
    signed: true, // bila endpoint tersebut butuh request signature
));
```

Semua fasilitas transport (token, tanda tangan, guard money-out, mapping error) tetap berlaku.

## Channel log khusus

```env
SINGAPAY_LOG_CHANNEL=singapay
```

```php
// config/logging.php
'singapay' => ['driver' => 'daily', 'path' => storage_path('logs/singapay.log'), 'days' => 30],
```

SDK tetap hanya menulis metadata — body tidak pernah dicatat, apa pun channel-nya.

## Middleware webhook tambahan

```php
// config/singapay.php
'webhooks' => [
    // ...
    'middleware' => ['throttle:120,1'],
],
```

Middleware tambahan berjalan **setelah** verifikasi tanda tangan.

## Menonaktifkan route webhook bawaan

Setel `SINGAPAY_WEBHOOKS_ENABLED=false` lalu daftarkan route Anda sendiri. Gunakan `Webhooks\WebhookVerifier` untuk verifikasi manual — jangan tulis ulang kriptografinya:

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

## Event sebagai titik ekstensi utama

Alur webhook sengaja dibangun di atas event Laravel: pasang listener sebanyak apa pun, queue-kan, atau subscribe `WebhookReceived` untuk menangkap tipe yang belum dikenal SDK — semuanya tanpa menyentuh kode paket.
