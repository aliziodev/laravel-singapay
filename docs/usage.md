# Penggunaan

> 🇬🇧 English version: [docs/en/usage.md](en/usage.md)

Semua contoh memakai facade `SingaPay`. Alternatifnya, inject `Aliziodev\Singapay\SingaPay` (SDK untuk koneksi default) atau `Aliziodev\Singapay\Contracts\SingaPayClientInterface` lewat constructor.

## Beberapa kredensial

Kalau merchant Anda memegang lebih dari satu kredensial dashboard — satu Default dan satu Specific per sub-akun — deklarasikan yang tambahan di `singapay.connections`, lalu panggil per nama:

```php
SingaPay::paymentLinks()->create([...]);                       // koneksi default
SingaPay::connection('payouts')->disbursement()->transfer([...]);

SingaPay::getDefaultConnection();   // "main"
SingaPay::connectionNames();        // ["main", "payouts"]
```

Kalau Anda hanya punya satu kredensial — kasus yang paling umum — lewati saja bagian ini; kunci kredensial di tingkat atas config sudah menjadi koneksi default. Rinciannya di [configuration.md](configuration.md).

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

## Nominal pada respons: jangan pernah bandingkan dengan `===`

Nominal yang Anda **kirim** selalu integer (lihat bagian di atas). Nominal yang Anda **terima** tidak konsisten tipenya — gateway mencampur integer dan decimal-string di field yang sama, tergantung nilainya:

```json
{"debit":  {"value": 2500,      "currency": "IDR"},
 "credit": {"value": "0.00",    "currency": "IDR"},
 "balance_after": {"value": 1203500, "currency": "IDR"}}
```

Baris statement yang sama mengembalikan `2500` (integer) untuk nominal terisi dan `"0.00"` (string) untuk nol. Endpoint balance justru mengembalikan `"1203500.00"` (string) untuk nilai yang di statement muncul sebagai integer `1203500`.

Jadi jangan pernah menulis `$row['credit']['value'] === 0` atau `=== "0.00"` — keduanya akan meleset separuh waktu. Normalkan dulu:

```php
$credit = Amount::from($response->data('credit.value'))->value; // selalu int rupiah
```

`Amount::from()` menerima integer maupun decimal-string dan menolak float, jadi aman dipakai sebagai gerbang tunggal untuk semua nominal yang masuk.

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

Keempatnya adalah *builder charge*, bukan daftar metode pembayaran SingaPay. Daftar itu adalah ~20 kode dari `paymentLinks()->paymentMethods()` yang dipakai di `whitelisted_payment_method` — sengaja tidak dijadikan enum karena per-merchant dan berubah seiring SingaPay menambah channel. Baca dari gateway, jangan dibekukan di kode.

Tiga hal sengaja tidak ada di `pay()`: **kartu** (pakai `card()->payment()` — dijauhkan dari API mudah ini karena membawa server Anda ke cakupan PCI-DSS), **gerai retail** (tidak punya endpoint sendiri, lewat `whitelisted_payment_method`), dan **direct debit** (siklusnya bind-lalu-charge, dan produknya belum dirilis).

**Field per metode:**

| Field | payment_link | va | qris | ewallet |
|---|---|---|---|---|
| `amount` | ✅ wajib | ✅ wajib | ✅ wajib | ✅ wajib |
| `reference` | ✅ wajib (`reff_no`) | opsional | opsional | opsional |
| `expires_at` | → ISO 8601 | → ms 13 digit (+`kind: temporary`) | → ISO 8601 | → ISO 8601 |
| `bank_code` | — | ✅ wajib | — | — |
| `vendor` | — | — | — | ✅ wajib (mis. `DANA`) |
| `customer` | — | `name` → nama VA | — | `customer_*` |
| `title`, `items`, `max_usage` | ✅ (`title` → `description`; `items` opsional — v2 menghitung totalnya sendiri) | `max_usage` | — | — |
| `redirect_url` | → `success_redirect_url` | — | — | → `merchant_redirect_url` |
| `options` | escape hatch: field mentah yang di-merge terakhir ke payload | ⬅ sama | ⬅ sama | ⬅ sama |

Input yang tidak bisa dipetakan (metode tak dikenal, field wajib hilang, `amount` float) melempar `ChargeException` **sebelum** ada traffic keluar. VA tanpa `expires_at` dibuat `permanent`; dengan `expires_at` menjadi `temporary` + `max_usage` (default 1).

## Money in

### Payment Link

`create()`, `find()`, dan `update()` memakai **API v2** — spec gateway menandai padanan v1-nya sebagai "Legacy". `list()`, `delete()`, dan `paymentMethods()` tetap v1 karena v2 tidak punya padanannya.

> ⚠️ **Jangan menilai payment link dari `status`.** Field itu menyimpan status *tersimpan* dan tetap `"open"` selamanya, bahkan lama setelah lewat `expired_at`. Yang otoritatif adalah `status_computed` (`expired`) dan `is_expired` (`true`), yang dihitung saat dibaca. Diverifikasi 2026-08-21 pada link yang sudah lewat sejam: `status: "open"`, `status_computed: "expired"`.

```php
// Bentuk "total": kirim nominalnya, tidak perlu items sama sekali.
$response = SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0001',      // maks 40 char, tanpa spasi/slash
    'payment_link_type' => 'total',
    'total_amount' => Amount::rupiah(150_000),
    'max_usage' => 1,                  // default 1; 0 = tanpa batas
    'expired_at' => now()->addDay()->toIso8601String(), // string tanggal biasa
]);

// Bentuk "items": gateway yang menjumlahkan. Harga negatif = diskon.
SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0002',
    'payment_link_type' => 'items',
    'items' => [
        ['name' => 'Produk A', 'quantity' => 2, 'unit_price' => 25_000],
        ['name' => 'Diskon', 'quantity' => 1, 'unit_price' => -5_000],
    ],
]);

$response->data('payment_url');
```

#### Membatasi metode pembayaran (dan cara membayar di Alfamart/Indomaret)

`whitelisted_payment_method` membatasi metode yang muncul di halaman pembayaran. Ini juga **satu-satunya jalan** ke pembayaran gerai retail — SingaPay tidak menyediakan endpoint retail tersendiri (sudah diprobe: semua kandidat path menjawab 404).

```php
SingaPay::paymentLinks()->create([
    // ... field lain ...
    'whitelisted_payment_method' => ['ALFAMART', 'INDOMARET'],
]);
```

Perilakunya, diverifikasi langsung 2026-08-21:

| Yang Anda kirim | Hasilnya |
|---|---|
| tidak dikirim sama sekali | ke-20 kode yang memenuhi syarat dipilih otomatis |
| `['VA_BCA', 'QRIS']` | persis dua itu saja |
| **`[]` (array kosong)** | **ke-20 lagi — ini BUKAN berarti "tidak ada"** |
| ada satu kode yang salah | **HTTP 422, seluruh request ditolak** |
| `['va_bca']` (huruf kecil) | HTTP 422 — kodenya case-sensitive |

Dua catatan lagi. **Urutannya tidak dipertahankan** — `['VA_BCA','QRIS']` kembali sebagai `["QRIS","VA_BCA"]`, jadi jangan pernah membandingkan array yang dikembalikan dengan `===`. Dan gateway menormalkan kode retail: `ALFAMART` kembali sebagai `RETAIL_ALFAMART_LINKQU`, sementara kode VA, QRIS, e-wallet, dan kartu dikembalikan apa adanya.

Kode yang salah ditolak mentah-mentah, bukan diam-diam dibuang — dan itu justru yang Anda inginkan: salah ketik satu kode seharusnya menggagalkan request, bukan diam-diam membuka metode yang ingin Anda tutup.

`update()` juga menerima field ini: bisa mempersempit atau memperluas link yang sudah ada, dan mengirim `null` mengembalikannya ke pilihan otomatis penuh.

> ⚠️ Pembayaran retail sudah diverifikasi penuh (2026-08-21), dan ada kejutannya: **webhook pembayarannya tidak menyebut retail sama sekali** — `data.payment.method` selalu `payment_link`. Kalau Anda perlu tahu pelanggan membayar di Alfamart atau Indomaret, tangkap dari event `payment-link-inquiry` dan jodohkan lewat `reff_no`. Lihat [webhooks.md](webhooks.md).

Daftar kode resmi datang dari gateway, bukan dari dokumen ini — `SingaPay::paymentLinks()->paymentMethods()` mengembalikan `payment_methods` (dengan `code`, `name`, `group`, `desc`) dan `available_codes`. Per sandbox 2026-08-21 ada 20 kode dalam lima grup:

| Grup | Kode |
|---|---|
| `card` | `NICEPAY_CARD` |
| `ewallet` | `EWALLET_DANA`, `EWALLET_GOPAY`, `EWALLET_OVO`, `EWALLET_SHOPEEPAY` |
| `offline_store` | `ALFAMART`, `INDOMARET` |
| `qris` | `QRIS` |
| `va` | `VA_BCA`, `VA_BNC`, `VA_BNI`, `VA_BRI`, `VA_BSI`, `VA_CIMB`, `VA_DANAMON`, `VA_MANDIRI`, `VA_MAYBANK`, `VA_MUAMALAT`, `VA_OCBC`, `VA_PERMATA` |

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

> ⚠️ **`status` VA tidak pernah berubah jadi kedaluwarsa.** Diverifikasi 2026-08-21: sebuah VA `temporary` satu jam lewat `expired_at` masih melaporkan `status: "active"` — dan endpoint VA **tidak punya** `status_computed` maupun `is_expired` seperti payment link. Jadi bandingkan sendiri `expired_at` (Unix **milidetik**, bukan detik) dengan jam Anda; jangan percaya `status`.

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

**Bentuk respons berbeda antar vendor** — diverifikasi langsung di sandbox 2026-08-21:

| Vendor | `checkout_url` | `checkout_url_app` | Catatan |
|---|---|---|---|
| `EWALLET_DANA` | ada | — | `customer_phone` opsional |
| `EWALLET_OVO` | **null** | **null** | *push-to-pay*: `customer_phone` **wajib** (tanpa itu HTTP 422), dan pembayaran didorong ke aplikasi OVO pelanggan — tidak ada URL untuk di-redirect |
| `EWALLET_GOPAY` | ada | ada (deeplink) | |
| `EWALLET_SHOPEEPAY` | ada | ada (`shopeepayid://`) | |

Di sandbox, sejauh mana tiap vendor bisa diselesaikan: **GoPay** dan **DANA** bisa dibayar sampai lunas (GoPay lewat simulator publik Midtrans, tanpa akun vendor). **OVO** membuat transaksi `open` yang menunggu push ke aplikasi — butuh ponsel dengan OVO sandbox untuk menyelesaikannya. **ShopeePay** mengarah ke halaman checkout UAT sungguhan.

> ⚠️ **OVO memvalidasi nomor HP di sisi vendor.** Nomor yang tidak terdaftar ditolak saat pembuatan dengan **HTTP 400 tanpa kode SP**, membawa pesan siap-tampil: *"Nomor HP tidak terdaftar di aplikasi OVO. Pastikan nomor HP yang dimasukkan sudah terdaftar."* Tidak ada kode untuk dicabangkan — tampilkan pesannya langsung ke pelanggan.

Dan ingat: **checkout yang gagal tidak memancarkan webhook** — jangan menunggu notifikasi kegagalan, panggil `inquireStatus()`.

Jadi jangan pernah `redirect($order->data('checkout_url'))` tanpa memeriksa null — checkout OVO akan mengirim pelanggan ke mana-mana. Tampilkan instruksi "buka aplikasi OVO Anda" untuk vendor itu.

## Pembayaran berulang, kartu, dan direct debit

Ketiganya diverifikasi langsung terhadap sandbox pada 2026-08-21. Semuanya money-in — tidak satu pun butuh `SINGAPAY_MONEY_OUT=true`.

### Langganan (recurring plan)

```php
$plan = SingaPay::subscriptions()->createPlan([
    'name' => 'Paket Bulanan',
    'customer_name' => 'Budi',
    'customer_email' => 'budi@example.id',
    'customer_phone' => '081234567890',
    'amount' => Amount::rupiah(99_000),    // atau 'items', pilih salah satu
    'subscription_id' => 'SUB-ORDER-4021', // kunci korelasi Anda
    'schedule' => [
        'interval' => 1,
        'interval_unit' => 'month',
        'total_interval' => 12,
        'start_time' => now()->addDay()->toIso8601String(),
    ],
]);

$plan->data('payment_link_url'); // pelanggan menautkan kartunya di sini
$plan->data('status');           // "pending_card_linking" sampai kartu tertaut
```

**Pakai `subscription_id` sebagai kunci korelasi, bukan `merchant_reff_no`.** Gateway menerima `merchant_reff_no` tanpa keluhan lalu membuangnya — plan selalu kembali dengan `merchant_reff_no: null`. `subscription_id` dihormati apa adanya, dan dibuatkan otomatis (`SUB-…`) kalau Anda tidak mengirimnya. `payment_type` juga selalu `null` saat pembuatan; nilainya baru terisi setelah pelanggan menautkan instrumen pembayaran.

Sisanya: `findPlan($id)`, `updatePlan($id, [...])` (ubah `amount`/`items` untuk upgrade/downgrade — respons memuat objek `upgrade` berisi proration), dan `cancelPlan($id, $reason)` yang mencatat `cancellation_reason` di `metadata.extra`.

### Kartu

```php
$tx = SingaPay::card()->payment([
    'amount' => Amount::rupiah(150_000),
    'goods_name' => 'Invoice #0001',
    'card_number' => '4111111111111111',
    'card_expiry' => '3012',   // YYMM — Desember 2030
    'card_cvv' => '123',
    // ... field customer_* wajib, lihat PHPDoc Card::payment()
]);

$tx->data('transaction_id');
$tx->data('provider_transaction_id');
```

⚠️ **`card_expiry` adalah YYMM, bukan MMYY.** Desember 2030 = `3012`. Urutan terbalik ditolak dengan `SP001 Card Expiri Date Check Please.` — dan SP001 di tempat lain berarti "hasil tidak diketahui, lakukan inquiry", padahal di sini murni kesalahan format.

`inquireStatus($id)` dan `cancel($id)` menerima `transaction_id` maupun `provider_transaction_id` (keduanya sudah diuji). `cancel()` menolak transaksi yang sudah `success` dengan `SP012`.

⚠️ Server yang menyentuh nomor kartu mentah masuk cakupan PCI-DSS. Pakai Payment Link kecuali Anda paham konsekuensinya.

### Direct debit

```php
$binding = SingaPay::directDebit()->bindCard([
    'customer_ref' => 'CUST-4021',
    'phone_no' => '081234567890',   // digit saja, TANPA "+"
]);

$binding->data('redirect_url');  // webview penautan, kedaluwarsa ~10 menit
$binding->data('status');        // PENDING_AUTH
```

⚠️ **`phone_no` tidak boleh diawali `+`.** `081234567890` dan `6281234567890` diterima; `+6281234567890` ditolak dengan `SP002 General Failure` yang sama sekali tidak menjelaskan apa pun.

Pelanggan harus menyelesaikan penautan di `redirect_url` (webview AyoConnect) — tidak ada jalan otomatis. Poll `bindingStatus($id)` sampai `PENDING_AUTH` menjadi `ACTIVE`; `charge()` pada binding yang belum aktif ditolak `SP018`. `charge()` dan `unbindCard()` bisa menjawab HTTP 202 dengan `requires_otp`; selesaikan lewat `verifyOtp()`.

Direct debit **tidak** terkena guard money-out meski request-nya ditandatangani: dia menagih pelanggan, bukan mengirim uang keluar.

## Money out

> Semua contoh di bawah butuh `SINGAPAY_MONEY_OUT=true`. Tanpa itu, SDK melempar `MoneyOutDisabledException` sebelum ada traffic keluar.

### Nomor rekening tes: prefix menentukan hasilnya

Sandbox tidak memakai rekening sungguhan. Hasil sebuah disbursement ditentukan oleh **prefix** nomor rekening tujuan — sisanya angka bebas, asal panjang totalnya sesuai panjang nomor rekening bank tersebut (BRI 15 digit, jadi `1000` + 11 angka).

| Prefix | Hasil akhir |
|---|---|
| `1000`, `1001`, `1002`, `1003` | SUCCESS |
| `1004`, `1006`, `1007`, `4000` | FAILED |

Aturan ini hanya muncul di modal *New Transaction* pada dashboard, tidak di dokumentasi API mana pun. Diverifikasi 2026-08-21: `100000000000001` selesai `success` (code `00`), sementara `123456789012` — nomor asal yang tidak berprefix — **menggantung `Pending` selamanya**. Kalau disbursement sandbox Anda tidak pernah selesai, ini penyebabnya.

Hasilnya tidak langsung: transfer selalu mulai `Pending` dan baru resolve beberapa puluh detik kemudian. Jangan simpulkan apa pun dari respons pertama — panggil `inquireStatus()`, persis seperti yang wajib dilakukan di produksi.

`checkBeneficiary()` juga bekerja dengan nomor berprefix ini dan mengembalikan nama pemilik palsu yang deterministik per nomor (`100000000000001` → "Yayasan Marbun Tbk"), jadi alur konfirmasi-nama bisa diuji utuh.

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
| ↳ `ValidationException` | SP018, atau HTTP 422 dari endpoint money-in (punya `->errors()` per-field) |
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
