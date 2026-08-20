# Webhook

> 🇬🇧 English version: [docs/en/webhooks.md](en/webhooks.md)

## Cara kerja

1. Paket mendaftarkan `POST /webhooks/singapay` (path bisa diubah) **tanpa** middleware group `web` — tanpa session, tanpa CSRF. Ini penting: SingaPay tidak mengirim token CSRF, jadi route ber-CSRF akan menolak semua delivery dengan 419.
2. Middleware `VerifyWebhookSignature` memverifikasi `X-Signature` (HMAC-SHA512, perbandingan constant-time) dan menolak `X-Timestamp` di luar toleransi (default ±300 detik) untuk mencegah replay. Delivery yang gagal diverifikasi dijawab **401** tanpa membocorkan alasannya.
3. Controller mengenali **tipe** webhook dari payload (field `event`, dengan fallback bentuk payload untuk payment link) — bukan dari URL, karena beberapa tipe berbagi satu URL callback.
4. Duplikat (SingaPay melakukan retry) dikenali lewat hash body di tabel `singapay_webhook_events` dan dijawab 200 tanpa dispatch ulang.
5. Dua event Laravel dipancarkan: `WebhookReceived` (selalu) dan event spesifik tipe. Listener berjalan sinkron; jika listener melempar exception, SingaPay menerima 5xx dan mengirim ulang — record idempotency baru ditulis **setelah** listener sukses (at-least-once).

## Konfigurasi di dashboard SingaPay

Arahkan semua URL callback ke route yang sama:

```
https://app-anda.com/webhooks/singapay
```

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
| `ProductsExpired` | `product_expiration` (batch) | opsional |
| `MoneyInTransactionsExpired` | `transaction_expiration` (batch) | opsional |

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

## Housekeeping

Baris di `singapay_webhook_events` hanya dibutuhkan selama jendela retry SingaPay (menit-menitan). Aman dibersihkan berkala:

```php
Schedule::call(fn () => DB::table('singapay_webhook_events')
    ->where('created_at', '<', now()->subWeek())
    ->delete()
)->daily();
```
