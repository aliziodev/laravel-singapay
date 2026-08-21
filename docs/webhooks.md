# Webhook

> 🇬🇧 English version: [docs/en/webhooks.md](en/webhooks.md)

## Cara kerja

1. Paket mendaftarkan `POST /webhooks/singapay` (path bisa diubah) **tanpa** middleware group `web` — tanpa session, tanpa CSRF. Ini penting: SingaPay tidak mengirim token CSRF, jadi route ber-CSRF akan menolak semua delivery dengan 419.
2. Middleware `VerifyWebhookSignature` memverifikasi `X-Signature` (HMAC-SHA512, perbandingan constant-time) dan menolak `X-Timestamp` di luar toleransi (default ±300 detik) untuk mencegah replay. Delivery yang gagal diverifikasi dijawab **401** tanpa membocorkan alasannya.
3. Controller mengenali **tipe** webhook dari payload (field `event`, dengan fallback bentuk payload untuk payment link) — bukan dari URL, karena beberapa tipe berbagi satu URL callback.
4. Idempotency memakai protokol **claim-then-dispatch**: delivery mengklaim barisnya di tabel `singapay_webhook_events` (kunci = hash body, ditegakkan unique index) **sebelum** dispatch. Duplikat konkuren — termasuk replay sengaja atas delivery bertanda tangan valid dalam jendela toleransi — kalah race insert dan dijawab 200 tanpa dispatch, sehingga listener terpanggil tepat sekali.
5. Dua event Laravel dipancarkan: `WebhookReceived` (selalu) dan event spesifik tipe. Listener berjalan sinkron; jika listener melempar exception, klaim dilepas dan SingaPay menerima 5xx sehingga retry berikutnya diproses ulang (at-least-once). Klaim yang tertinggal karena worker crash dianggap basi setelah 5 menit dan direbut ulang oleh retry.

## Konfigurasi di dashboard SingaPay

Dashboard menyediakan **delapan kolom Notif URL terpisah** — Transaction (Money In), Disbursement (Money Out), Payment Link Inquiry, Product Expiration, Transaction Expiration, Subscription Cycle, Settlement, dan Direct Debit. Arahkan semuanya ke route yang sama:

```
https://app-anda.com/webhooks/singapay
```

Satu route sudah cukup: controller mengenali tipe dari payload, bukan dari URL. Catatan dari dashboard: binding dan unbinding direct debit selalu memakai URL Direct Debit tingkat merchant, bukan URL per akun.

### Webhook mengikuti kredensial, bukan merchant

Ini penyebab paling mungkin kalau webhook Anda tidak pernah datang, dan tidak terlihat di mana pun sampai Anda tahu harus mencarinya.

Halaman **Credential Details** di dashboard punya daftar *Assigned Accounts*, dan panel webhook-nya menyatakan *"Applies to all accounts sharing this credential."* Artinya notifikasi sebuah transaksi dikirim ke Notif URL milik **kredensial yang memiliki akun tersebut** — bukan ke kredensial yang Anda pakai memanggil API, dan bukan ke satu konfigurasi merchant-wide.

Konsekuensinya ada dua, dan keduanya harus benar bersamaan:

1. **URL** harus diisi di kredensial yang memiliki akun itu. Mengisinya di kredensial lain tidak berpengaruh apa pun — SingaPay tetap mengirim, hanya ke tempat lain, dan Anda tidak akan melihat jejaknya.
2. **`SINGAPAY_CLIENT_SECRET` aplikasi Anda harus milik kredensial yang sama.** Tanda tangan webhook dihitung dengan client secret kredensial itu; secret kredensial lain akan gagal verifikasi dan dijawab 401.

Diverifikasi 2026-08-21: sebuah delivery `va-transaction` sungguhan dihitung ulang tanda tangannya dengan empat kandidat — client secret dan HMAC key dari dua kredensial berbeda — dan hanya **client secret milik kredensial yang memiliki akun** yang cocok.

SingaPay juga **mengirim ulang** delivery yang dijawab 401, sekitar satu menit sekali selama kurang lebih delapan menit, lalu menyerah. Jadi salah konfigurasi tidak langsung kehilangan notifikasi — tapi jendela perbaikannya hanya beberapa menit.

### Satu URL bisa menerima delivery dari lebih dari satu kredensial

Aturan "webhook mengikuti kredensial" di atas benar untuk money-in, tapi tidak lengkap untuk money-out. Diverifikasi 2026-08-21: sebuah `disbursement` yang **dipicu dengan kredensial Specific** justru dinotifikasi oleh kredensial **Default** — delivery-nya membawa `X-PARTNER-ID` milik Default, dan hanya client secret Default yang mencocokkan tanda tangannya. URL yang sama terpasang di kedua kredensial.

Akibatnya, aplikasi yang memakai kredensial Specific (dan SP403 memang memaksa demikian untuk akun yang punya kredensial sendiri) akan menolak setiap notifikasi money-out dengan 401 — diam-diam, karena yang terlihat hanya baris `singapay.webhook.rejected` di log.

Solusinya: deklarasikan kredensial lain itu sebagai **koneksi**. Secret setiap koneksi otomatis ikut jadi kandidat verifikasi.

```php
'connections' => [
    'default-credential' => [
        'client_id' => env('SINGAPAY_DEFAULT_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_DEFAULT_CLIENT_SECRET'),
    ],
],
```

Kalau kredensial itu **hanya mengirimi Anda webhook** dan tidak pernah Anda pakai memanggil API — kredensial lama, atau milik pihak lain — tidak perlu dijadikan koneksi utuh; cukup daftarkan secret-nya:

```dotenv
SINGAPAY_WEBHOOK_SECRETS=secret-kredensial-lama,secret-kredensial-pihak-lain
```

Semua kunci dari kedua sumber dibandingkan constant-time, dan tanda tangan **keluar** tetap memakai `client_secret` koneksi yang dipakai — jadi menambah kunci di sini tidak melonggarkan apa pun selain daftar penanda tangan yang Anda akui.

Koneksi yang setengah terkonfigurasi (tanpa secret) dilewati, bukan membuat verifikasi gagal.

### Kunci penanda tangan: Client Secret

SingaPay menandatangani webhook dengan **Client Secret**, bukan HMAC Validation Key. Ini diverifikasi langsung terhadap delivery sungguhan (2026-08-21): tanda tangan dihitung ulang dengan kedua kunci, dan hanya Client Secret yang cocok.

SDK tetap menerima keduanya, jadi Anda tidak perlu mengubah apa pun. Tapi kalau `SINGAPAY_HMAC_KEY` adalah satu-satunya kunci yang Anda set dan Client Secret tidak dikonfigurasi, verifikasi akan gagal.

### Tombol "Test" di dashboard mengirim request TANPA tanda tangan

Tombol **Test** di sebelah tiap kolom Notif URL mengirim payload dummy seperti ini:

```json
{"status": 200, "success": true, "data": "VA Payment Notification testing"}
```

**tanpa** header `X-Signature`, `X-Timestamp`, maupun `Authorization`. SDK menolaknya dengan 401 dan pesan `Invalid webhook signature.` — dan itu **perilaku yang benar**, bukan tanda instalasi Anda rusak.

Jadi tombol Test hanya membuktikan URL Anda terjangkau dari internet. Untuk menguji jalur lengkapnya, picu transaksi sungguhan lewat **Simulator** di dashboard; delivery asli datang lengkap dengan ketiga header itu dan akan lolos verifikasi.

Kalau Anda benar-benar ingin tombol Test lolos, satu-satunya cara adalah mematikan verifikasi (`SINGAPAY_WEBHOOK_VERIFY=false`) — jangan pernah lakukan itu di produksi.

Delivery datang dari user-agent `GuzzleHttp/7`. IP asalnya tidak didokumentasikan SingaPay dan tidak dijamin stabil, jadi jangan jadikan allowlist IP sebagai satu-satunya kontrol — tanda tangan yang menjadi otoritasnya.

### Memicu tiap event di sandbox

Delapan dari tiga belas tipe event sudah dikonfirmasi dengan payload asli SingaPay (2026-08-21). Ini cara memicunya:

| Event | Cara memicu |
|---|---|
| `va-transaction` | Simulator → Virtual Account, masukkan nomor VA-nya |
| `qris-acquirer-transaction` | Simulator → QRIS & E-Wallet, tempel string `qr_data` |
| `payment-link-transaction` | buka `payment_url`, bayar dengan kartu tes `4111111111111111` (kedaluwarsa `12/30` di UI, CVV `123`) |
| `payment-link-inquiry` | terkirim otomatis sesaat sebelum pembayaran payment link, saat pelanggan memilih metode |
| retail outlet (Alfamart/Indomaret) | payment link dengan `whitelisted_payment_method: ['ALFAMART']`, pilih metodenya, lalu bayar kodenya di simulator **Retail Outlet** dashboard |
| `ewallet-native-transaction` | buka `checkout_url` dan selesaikan: **GoPay** paling mudah — URL-nya simulator publik Midtrans, tanpa akun vendor. DANA pakai akun sandbox `0817345545` / PIN `123321`. **Hanya sukses yang memancarkan webhook**; checkout gagal tidak mengirim apa pun |
| `ewallet-topup` | `ewalletMoneyOut()->triggerTopup()` |
| `transaction-expiration` | otomatis, batch terjadwal (~1 menit setelah jatuh tempo) |
| `disbursement` | `disbursement()->transfer()` ke nomor rekening berawalan `1000`–`1003` (sukses) atau `1004`/`1006`/`1007`/`4000` (gagal) |

Sisanya belum bisa dipicu di sandbox: `settlement` (batch terjadwal), `subscription-cycle` (menunggu siklus tagihan), `qris-issuer` dan `direct-debit` (produknya belum aktif). **`product-expiration` tampaknya tidak pernah menyala sama sekali**: payment link dan VA yang jauh lewat `expired_at` tidak pernah berubah status — kedaluwarsa dihitung saat dibaca, bukan ditulis balik — jadi tidak ada perubahan state yang bisa memicu batch.

**Akun DANA sandbox.** Checkout e-wallet butuh nomor yang terdaftar di sandbox DANA; nomor asli Anda akan ditolak. Nomor uji yang bekerja adalah **0817345545** dengan PIN **123321** — didokumentasikan oleh [Faspay](https://docs.faspay.co.id/before-live/account-testing), karena PSP Indonesia berbagi environment sandbox DANA yang sama. SingaPay tidak mendokumentasikannya sendiri. Jangan menebak PIN: akun uji punya batas percobaan.

**Bentuk payload berbeda antara money-in dan money-out.** Event money-in datang dengan envelope v1 (`{"status":200,"success":true,"event":...}`), sementara `ewallet-topup` datang dengan envelope v2 (`{"response_code":"SP000","response_message":...,"event":...}`). SDK menormalkan keduanya, tapi kalau Anda membaca `$event->payload` mentah, jangan berasumsi satu bentuk.

### Webhook pembayaran tidak memberi tahu metode yang dipakai

Diverifikasi dengan pembayaran Alfamart sungguhan (2026-08-21): delivery `payment-link-transaction` melaporkan `data.payment.method` sebagai literal **`payment_link`**, apa pun cara pelanggan membayar. Tidak ada jejak retail, kartu, atau VA di mana pun dalam payload itu.

Metodenya hanya diumumkan di delivery `payment-link-inquiry` yang datang lebih dulu, dan keduanya dijodohkan lewat `reff_no` yang identik:

```php
// Saat pelanggan memilih metode:
public function handle(PaymentLinkInquiryReceived $event): void
{
    $event->retailCode();           // "ALFAMART" | "INDOMARET" | null
    $event->paymentMethodName();    // "Alfamart (Linkqu)" — label tampilan
    $event->paymentMethodValue();   // kode bayar retail
    $event->historyReffNo();        // kunci join
}

// Saat dibayar — cocokkan dengan yang tersimpan di atas:
public function handle(PaymentLinkPaid $event): void
{
    $event->reffNo();               // sama persis dengan historyReffNo()
}
```

Jadi kalau Anda perlu mencatat "dibayar di Alfamart", Anda **wajib** mendengarkan event inquiry — event pembayaran saja tidak akan pernah bisa memberitahunya.

Satu jebakan lagi: `payment_method_additional` dikirim sebagai **string JSON**, bukan objek, sehingga `data('payment_link_history.payment_method_additional.retail_code')` diam-diam mengembalikan null. Pakai `paymentMethodAdditional()` yang sudah men-decode-nya.

## Daftar event

| Event | Tipe webhook | Sumber |
|---|---|---|
| `VirtualAccountPaid` | `va-transaction` | `transaction_notif_url` |
| `QrisAcquirerPaid` | `qris-acquirer-transaction` | `transaction_notif_url` |
| `PaymentLinkPaid` | `payment-link-transaction` (atau tanpa `event`) | `transaction_notif_url` |
| `EwalletPaid` | `ewallet-native-transaction` | `transaction_notif_url` |
| `SubscriptionCycleProcessed` | `subscription.cycle.*`, `subscription.plan.status_changed` | khusus |
| `DisbursementProcessed` | `disbursement` | `disbursement_notif_url` |
| `EwalletTopupProcessed` | `ewallet-topup` | `disbursement_notif_url` |
| `QrisIssuerProcessed` | `qris-issuer` | `disbursement_notif_url` |
| `SettlementProcessed` | `settlement.*` | khusus |
| `DirectDebitBindingUpdated` | `direct-debit*` | `direct_debit_notif_url` |
| `PaymentLinkInquiryReceived` | `payment_link.inquiry*` | khusus |
| `ProductsExpired` | `product-expiration` (batch) | opsional |
| `MoneyInTransactionsExpired` | `transaction-expiration` (batch) | opsional |

Semua event turunan `WebhookReceived`, jadi selalu punya `->payload`, `->type`, `->event()`, dan `->data('dot.notation')`.

## Contoh listener

```php
// AppServiceProvider::boot() atau EventServiceProvider
use Aliziodev\Singapay\Events\DisbursementProcessed;
use Aliziodev\Singapay\Events\VirtualAccountPaid;
use Aliziodev\Singapay\Events\WebhookReceived;

Event::listen(function (VirtualAccountPaid $event) {
    Order::where('reff_no', $event->reffNo())->first()?->markAsPaid();
});

Event::listen(function (DisbursementProcessed $event) {
    $payout = Payout::where('reference', $event->referenceNumber())->first();

    // Webhook money-out juga dikirim saat GAGAL — selalu cek statusnya:
    $event->isSuccessful() ? $payout?->markAsCompleted() : $payout?->markAsFailed();
});

// Tangkap semuanya, termasuk tipe baru yang belum dikenal SDK:
Event::listen(function (WebhookReceived $event) {
    Log::info('singapay webhook', ['event' => $event->event()]);
});
```

Butuh proses berat? Dispatch job dari listener (`dispatch(new HandlePayment(...))`) supaya webhook tetap dijawab cepat.

## Catatan penting

- Payload webhook money-in memakai timestamp `d M Y H:i:s` **Asia/Jakarta**; money-out memakai Unix milidetik string. Jangan parse dengan asumsi UTC.
- `success: true` di payload subscription berarti *webhook terkirim*, bukan *tagihan berhasil* — pakai `$event->isPaymentFailed()`.
- Field `amount.value` bisa integer (VA/QRIS) atau string desimal (payment link, money-out) tergantung tipe webhook.
- Bearer token pada header `Authorization` webhook adalah string acak dari SingaPay — bukan access token Anda — dan tetap menjadi bagian dari string yang ditandatangani.

## Menguji listener Anda

Trait `InteractsWithSingaPay` bisa mem-POST payload ke route webhook dengan tanda tangan yang valid, persis seperti kiriman SingaPay:

```php
use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;

uses(InteractsWithSingaPay::class);

it('menandai order lunas saat VA dibayar', function () {
    $order = Order::factory()->create(['reff_no' => 'INV-1']);

    $this->postSingaPayWebhook([
        'event' => 'va-transaction',
        'data' => ['transaction' => ['reff_no' => 'INV-1', 'status' => 'paid']],
    ])->assertOk();

    expect($order->fresh()->isPaid())->toBeTrue();
});
```

## Model `WebhookEvent`

Setiap delivery yang diproses tercatat sebagai model Eloquent `Aliziodev\Singapay\Models\WebhookEvent` (cast `payload` → array, `processed_at` → datetime):

```php
use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Models\WebhookEvent;

// Inspeksi riwayat
WebhookEvent::ofType(WebhookType::Disbursement)->latest()->limit(20)->get();

// Replay sebuah delivery melalui listener Anda — memancarkan event generik
// DAN event bertipe, persis seperti delivery aslinya
WebhookEvent::find($id)->replay();
```

`toEvent()` membangun ulang objek event bertipe (mis. `DisbursementProcessed`) dari payload tersimpan — berguna saat listener dulu gagal dan Anda ingin memprosesnya ulang.

## Housekeeping

Baris di tabel hanya dibutuhkan selama jendela retry SingaPay (menit-menitan). Model sudah `MassPrunable` — cukup jadwalkan pruner standar Laravel:

```php
use Aliziodev\Singapay\Models\WebhookEvent;

Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])->daily();
```

Retensi default 7 hari, bisa diubah lewat `SINGAPAY_WEBHOOK_PRUNE_DAYS` (config `singapay.webhooks.prune_after_days`).
