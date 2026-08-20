# Penggunaan

> 🇬🇧 English version: [docs/en/usage.md](en/usage.md)

Semua contoh memakai facade `SingaPay`. Alternatifnya, inject `Aliziodev\Singapay\SingaPay` (manager) atau `Aliziodev\Singapay\Contracts\SingaPayClientInterface` lewat constructor.

## Objek Response

Setiap pemanggilan mengembalikan `Aliziodev\Singapay\Http\Response` yang menormalkan ketiga bentuk envelope SingaPay (v1 `status/success`, v2 `response_code`, dan flat):

```php
$response = SingaPay::balance()->merchant();

$response->successful();                    // bool — request diterima gateway
$response->code;                            // ?ResponseCode (enum SP000-SP020)
$response->data('available_balance.value'); // akses dot-notation ke bagian data
$response->collect('items');                // Collection
$response->raw;                             // body mentah ter-decode
```

> `SP000` berarti **request** berhasil, bukan pembayaran berhasil. Untuk transaksi, selalu cek `transaction_status` / webhook.

## Nominal: selalu integer

Tanda tangan SingaPay menghash body request — float bulat (`100000.0`) diserialisasi berbeda antar runtime dan merusak tanda tangan secara diam-diam. SDK **menolak float** sebelum menandatangani. Gunakan value object `Amount`:

```php
use Aliziodev\Singapay\Support\Amount;

Amount::rupiah(150_000);       // dari integer
Amount::from('150000');        // dari string numerik bulat
Amount::from('150000.50');     // ❌ InvalidAmountException
```

`Amount` otomatis ter-serialize menjadi integer di dalam body JSON.

## API charge terpadu

`SingaPay::pay($method, $data)` (alias `SingaPay::charges()->create(...)`) membuat tagihan money-in pada metode mana pun dengan **satu bentuk input** — builder-nya yang menyerap kerumitan per metode: `expired_at` milidetik vs ISO 8601, `reff_no` vs `merchant_reff_no`, sintesis `items` untuk payment link, sampai prefix `EWALLET_` untuk vendor.

```php
use Aliziodev\Singapay\Facades\SingaPay;

$charge = SingaPay::pay('va', [
    'amount' => 150_000,                 // wajib — integer/Amount/string bulat (float ditolak)
    'reference' => 'INV-2026-0001',      // reff merchant (wajib untuk payment_link)
    'expires_at' => now()->addDay(),     // DateTime, string tanggal, atau string 13 digit ms
    'customer' => ['name' => 'Budi', 'email' => 'budi@x.id', 'phone' => '0812...'],
    'bank_code' => 'BRI',                // wajib untuk va
]);

$charge->successful();     // request diterima gateway
$charge->vaNumber();       // artefak pembayaran per metode:
$charge->qrString();       //   qris → string EMV
$charge->checkoutUrl();    //   payment_link / ewallet → URL
$charge->data('...');      // akses penuh ke response
```

**Metode & alias** (case-insensitive): `payment_link`/`pl`/`link`, `virtual_account`/`va`, `qris`/`qr`, `ewallet`/`e-wallet`/`wallet` — atau enum `PaymentMethod`.

**Field per metode:**

| Field | payment_link | va | qris | ewallet |
|---|---|---|---|---|
| `amount` | ✅ wajib | ✅ wajib | ✅ wajib | ✅ wajib |
| `reference` | ✅ wajib (`reff_no`) | opsional | opsional | opsional |
| `expires_at` | → ms 13 digit | → ms 13 digit (+`kind: temporary`) | → ISO 8601 | → ISO 8601 |
| `bank_code` | — | ✅ wajib | — | — |
| `vendor` | — | — | — | ✅ wajib (mis. `DANA`) |
| `customer` | — | `name` → nama VA | — | `customer_*` |
| `title`, `items`, `max_usage` | ✅ (items disintesis bila kosong) | `max_usage` | — | — |
| `redirect_url` | ✅ | — | — | → `merchant_redirect_url` |
| `options` | escape hatch: field mentah yang di-merge terakhir ke payload | ⬅ sama | ⬅ sama | ⬅ sama |

Input yang tidak bisa dipetakan (metode tak dikenal, field wajib hilang, `amount` float) melempar `ChargeException` **sebelum** ada traffic keluar. VA tanpa `expires_at` dibuat `permanent`; dengan `expires_at` menjadi `temporary` + `max_usage` (default 1).

## Money in

### Payment Link

```php
$response = SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0001',      // maks 40 char, tanpa spasi/slash
    'title' => 'Invoice #0001',
    'max_usage' => 1,
    'total_amount' => Amount::rupiah(150_000), // harus = jumlah subtotal items
    'items' => [
        ['name' => 'Produk A', 'quantity' => 1, 'unit_price' => Amount::rupiah(150_000)],
    ],
    'expired_at' => (string) app(\Aliziodev\Singapay\Support\JakartaClock::class)
        ->toMilliseconds(now()->addDay()),  // 13 digit milidetik!
]);

$response->data('payment_url');
```

### Virtual Account

```php
$va = SingaPay::virtualAccounts()->create([
    'bank_code' => 'BRI',
    'kind' => 'temporary',              // temporary butuh expired_at + max_usage
    'amount_type' => 'closed',
    'amount' => Amount::rupiah(100_000),
    'expired_at' => '1774000000000',    // 13 digit milidetik
    'max_usage' => 1,
    'merchant_reff_no' => 'INV-2026-0001',
]);

$va->data('number');   // nomor VA yang dibayar customer
$va->data('id');       // ULID VA — dipakai untuk find/update/delete
```

### QRIS

```php
$qr = SingaPay::qris()->generate([
    'amount' => Amount::rupiah(50_000),
    'expired_at' => now()->addHour()->toIso8601String(), // QRIS pakai ISO 8601!
    'merchant_reff_no' => 'INV-2026-0002',
]);

$qr->data('qr_data'); // string EMV untuk dirender jadi QR
```

### E-Wallet

```php
$order = SingaPay::ewallet()->createOrder([
    'amount' => Amount::rupiah(75_000),
    'ewallet_vendor' => 'EWALLET_DANA',
    'customer_phone' => '081234567890', // wajib untuk OVO (push-to-pay)
    'merchant_redirect_url' => route('checkout.done'),
]);

$order->data('checkout_url');
```

## Money out

> Semua contoh di bawah butuh `SINGAPAY_MONEY_OUT=true`. Tanpa itu, SDK melempar `MoneyOutDisabledException` sebelum ada traffic keluar.

### Pola wajib: transfer → tangani ambiguitas → inquiry

```php
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\RequestException;

// 1. (Opsional tapi disarankan) validasi rekening tujuan
SingaPay::disbursement()->checkFee([
    'bank_swift_code' => 'BRINIDJA',
    'amount' => Amount::rupiah(500_000),
]);

// 2. Transfer dengan reference unik
try {
    $result = SingaPay::disbursement()->transfer([
        'reference_number' => 'PAYOUT-2026-0001',
        'bank_code' => '014',                    // 3 digit atau SWIFT
        'bank_account_number' => '1234567890',
        'amount' => Amount::rupiah(500_000),
        'notes' => 'Payout Agustus',
    ]);
} catch (InsufficientBalanceException $e) {
    // SP003 — jangan retry sampai saldo diisi
} catch (DuplicateReferenceException $e) {
    // SP004 — reference sudah pernah dipakai; cek statusnya, jangan ganti reference lalu retry buta
} catch (RequestException $e) {
    if ($e->shouldInquireStatus()) {
        // SP001/SP005 — hasil TIDAK diketahui. Jangan retry. Cek dulu:
        $status = SingaPay::disbursement()->inquireStatus('PAYOUT-2026-0001');
    }

    throw $e;
}

// 3. Kepastian final datang lewat webhook DisbursementProcessed
```

### E-wallet top-up & QRIS issuer

```php
SingaPay::ewalletMoneyOut()->inquireAccount([
    'ewallet_code' => 'DANA',
    'customer_number' => '085733347341',
    'amount' => ['value' => '10000.00', 'currency' => 'IDR'], // catatan: skema ini pakai string desimal
]);

SingaPay::ewalletMoneyOut()->triggerTopup([...]);
SingaPay::qrisMoneyOut()->inquireMerchant($qrData);
SingaPay::qrisMoneyOut()->triggerPaymentCredit([...]);
```

## Biller (PPOB)

```php
SingaPay::biller()->checkBalance();
$inquiry = SingaPay::biller()->prepaidInquiry('plntok', [
    'customer_id' => '01428800700',
    'product_code' => 'SPPLN20',
]);
SingaPay::biller()->prepaidPayment('plntok', [
    'customer_id' => '01428800700',
    'product_code' => 'SPPLN20',
    'password' => config('services.singapay_biller.password'),
    'reference_number' => $inquiry->data('reference_number'),
]);
```

## Verifikasi identitas (KYC)

```php
$check = SingaPay::identity()->verifyBankAccount([
    'request_id' => (string) Str::uuid(),
    'account_number' => '1234567890',
    'bank_code' => '014',
    'name' => 'Budi Santoso',
]);

$check->data('similarity'); // 0-100
$check->data('suggestion'); // pass | reject
```

## Penanganan exception

Semua exception SDK turunan `Aliziodev\Singapay\Exceptions\SingaPayException`:

| Exception | Kapan |
|---|---|
| `ConfigurationException` | Config/kredensial hilang atau tidak valid |
| `ConnectionException` | SingaPay tidak bisa dihubungi |
| `RequestException` | Gateway menjawab dengan kegagalan (punya `->response`, `->responseCode()`, `->shouldInquireStatus()`) |
| ↳ `InsufficientBalanceException` | SP003 |
| ↳ `DuplicateReferenceException` | SP004 |
| ↳ `AuthenticationException` | SP013 setelah refresh token sekali |
| ↳ `InvalidSignatureException` | SP016 — bug sisi pemanggil; lihat [troubleshooting](troubleshooting.md) |
| ↳ `IpNotWhitelistedException` | SP017, atau HTTP 403 "IP … not registered" dari endpoint token |
| ↳ `ValidationException` | SP018 (punya `->errors()`) |
| `MoneyOutDisabledException` | Operasi money-out saat guard mati |
| `WebhookVerificationException` | Webhook masuk gagal diverifikasi |

## Testing dengan SingaPay::fake()

```php
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Http\ApiRequest;

$fake = SingaPay::fake([
    // pola path (wildcard *) => data | Response | Closure
    '*payment-link-manage*' => ['payment_url' => 'https://pay.test/abc'],
]);

// ... jalankan kode aplikasi Anda ...

$fake->assertSent('*payment-link-manage*');
$fake->assertSent(fn (ApiRequest $r) => $r->body['reff_no'] === 'INV-001');
$fake->assertPaymentLinkCreated();
$fake->assertDisbursementRequested();
$fake->assertNothingSent();
$fake->assertSentCount(2);
```

Guard money-out tetap berlaku di bawah `fake()`: request money-out melempar `MoneyOutDisabledException` kecuali `SINGAPAY_MONEY_OUT=true`. Ini disengaja — test tidak boleh lulus di jalur yang produksi tolak. Untuk menguji jalur money-out, nyalakan flag-nya di environment test Anda (mis. `<env name="SINGAPAY_MONEY_OUT" value="true"/>` di `phpunit.xml`).

Untuk menguji listener webhook, lihat [webhooks.md](webhooks.md#menguji-listener-anda).
