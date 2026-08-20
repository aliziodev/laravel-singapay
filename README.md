# Laravel SingaPay

[![Tests](https://github.com/aliziodev/laravel-singapay/actions/workflows/tests.yml/badge.svg)](https://github.com/aliziodev/laravel-singapay/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/aliziodev/laravel-singapay.svg)](https://packagist.org/packages/aliziodev/laravel-singapay)
[![License](https://img.shields.io/packagist/l/aliziodev/laravel-singapay.svg)](LICENSE)

SDK Laravel **tidak resmi** untuk payment gateway [SingaPay](https://singapay.id) (PT Abadi Singapay Indonesia, PJP1 berizin Bank Indonesia). Paket ini tidak berafiliasi dengan PT Abadi Singapay Indonesia.

🇬🇧 *English documentation is available in [README.en.md](README.en.md) and [docs/en/](docs/en/).*

SingaPay tidak menyediakan SDK resmi — setiap integrator harus mengimplementasikan sendiri dua skema tanda tangan HMAC yang berbeda, normalisasi JSON yang presisi byte-per-byte, manajemen token, dan verifikasi webhook. Paket ini mengerjakan semuanya, dengan test suite yang mengunci setiap byte tanda tangan.

## Fitur

- **Money In** — Payment Link, Virtual Account, QRIS, E-Wallet (DANA/OVO/GoPay/ShopeePay), Card, Subscription, Direct Debit.
- **Money Out** — Disbursement, E-Wallet Top-Up, QRIS Issuer, Account Transfer, Cardless Withdrawal — di belakang *guard* eksplisit yang default-nya **mati**.
- **Webhook** — verifikasi tanda tangan constant-time, proteksi replay, idempotency built-in, dan 13 event Laravel yang siap di-listen.
- **Autentikasi** — token v1.1 (HMAC) / v1.0 (Basic) dengan cache + lock anti *thundering herd*, plus skema terpisah untuk layanan Biller dan Identity/KYC.
- **Testing** — `SingaPay::fake()` dengan assertion, plus helper untuk menguji webhook di aplikasi Anda tanpa mock manual.
- **Keamanan by design** — nominal wajib integer (float ditolak sebelum ditandatangani), body request tidak pernah masuk log, retry otomatis hanya untuk GET.

## ⚠️ Baca dulu sebelum produksi

1. **IP whitelist wajib.** SingaPay menolak request dari IP yang tidak terdaftar (SP017). Platform serverless (Vercel, Netlify, Cloudflare Workers) memakai IP dinamis dan **tidak bisa** di-whitelist — panggil SingaPay dari server ber-IP statis. Jalankan `php artisan singapay:ping` untuk memverifikasi.
2. **Money-out mati secara default.** Semua operasi yang memindahkan uang melempar `MoneyOutDisabledException` sampai Anda menyetel `SINGAPAY_MONEY_OUT=true`. Nyalakan hanya di environment yang memang boleh mentransfer dana.
3. **Jangan pernah retry money-out secara buta.** Setelah `SP001`/`SP005`/timeout, panggil `inquireStatus()` dengan reference yang sama sebelum melakukan apa pun — retry buta bisa menduplikasi transfer uang sungguhan.
4. **Endpoint Card = ruang lingkup PCI-DSS.** Server yang menyentuh nomor kartu mentah masuk cakupan PCI-DSS. Gunakan Payment Link kecuali Anda benar-benar paham konsekuensinya.
5. **Jadwal settlement & rolling reserve tidak terdokumentasi** oleh SingaPay — tanyakan langsung sebelum go-live.

## Instalasi

```bash
composer require aliziodev/laravel-singapay
php artisan singapay:install
php artisan migrate
```

Isi `.env`:

```env
SINGAPAY_ENV=sandbox
SINGAPAY_CLIENT_ID=...
SINGAPAY_CLIENT_SECRET=...
SINGAPAY_PARTNER_ID=...
SINGAPAY_ACCOUNT_ID=...   # ULID akun default
```

Verifikasi koneksi:

```bash
php artisan singapay:ping
```

## Mulai cepat

### Membuat Payment Link

```php
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Support\Amount;

$response = SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0001',
    'title' => 'Invoice #0001',
    'max_usage' => 1,
    'total_amount' => Amount::rupiah(150_000),
    'items' => [
        ['name' => 'Produk A', 'quantity' => 1, 'unit_price' => Amount::rupiah(150_000)],
    ],
]);

$paymentUrl = $response->data('payment_url');
```

### Menerima webhook

Route `POST /webhooks/singapay` sudah terdaftar otomatis (tanpa CSRF, dengan verifikasi tanda tangan). Cukup daftarkan listener:

```php
use Aliziodev\Singapay\Events\VirtualAccountPaid;

Event::listen(function (VirtualAccountPaid $event) {
    Order::where('reff_no', $event->reffNo())->first()?->markAsPaid();
});
```

### Disbursement (money out)

```php
use Aliziodev\Singapay\Exceptions\RequestException;

try {
    $response = SingaPay::disbursement()->transfer([
        'reference_number' => 'PAYOUT-2026-0001',
        'bank_code' => '014',
        'bank_account_number' => '1234567890',
        'amount' => Amount::rupiah(500_000),
    ]);
} catch (RequestException $e) {
    if ($e->shouldInquireStatus()) {
        // Hasil belum pasti — JANGAN retry. Cek status dulu:
        $status = SingaPay::disbursement()->inquireStatus('PAYOUT-2026-0001');
    }

    throw $e;
}
```

### Testing di aplikasi Anda

```php
use Aliziodev\Singapay\Facades\SingaPay;

$fake = SingaPay::fake([
    '*payment-link-manage*' => ['payment_url' => 'https://pay.test/abc'],
]);

$this->post('/checkout', [...])->assertOk();

$fake->assertPaymentLinkCreated(fn (array $body) => $body['reff_no'] === 'INV-001');
```

## Grup endpoint

| Akses | Cakupan |
|---|---|
| `SingaPay::paymentLinks()` | CRUD payment link + daftar metode pembayaran |
| `SingaPay::paymentLinkHistories()` | Riwayat pembayaran per link |
| `SingaPay::virtualAccounts()` / `vaTransactions()` | Provisioning VA + transaksinya |
| `SingaPay::qris()` / `qrisMoneyOut()` | QRIS dinamis (in) + issuer (out) |
| `SingaPay::ewallet()` / `ewalletMoneyOut()` | E-wallet checkout (in) + top-up (out) |
| `SingaPay::card()` | Pembayaran kartu (⚠️ PCI-DSS) |
| `SingaPay::subscriptions()` | Paket berlangganan / recurring |
| `SingaPay::directDebit()` | Binding kartu + charge berulang |
| `SingaPay::disbursement()` | Transfer bank keluar |
| `SingaPay::accountTransfer()` | Transfer antar sub-akun |
| `SingaPay::cardlessWithdrawal()` | Tarik tunai tanpa kartu |
| `SingaPay::accounts()` / `balance()` / `statements()` | Sub-akun, saldo, mutasi |
| `SingaPay::biller()` | PPOB: PLN, pulsa, game, dsb. (host terpisah) |
| `SingaPay::identity()` | Verifikasi nama pemilik rekening/e-wallet (host terpisah) |

## Dokumentasi

| Bahasa Indonesia | English |
|---|---|
| [Instalasi](docs/installation.md) | [Installation](docs/en/installation.md) |
| [Konfigurasi](docs/configuration.md) | [Configuration](docs/en/configuration.md) |
| [Penggunaan](docs/usage.md) | [Usage](docs/en/usage.md) |
| [Webhook](docs/webhooks.md) | [Webhooks](docs/en/webhooks.md) |
| [Tanda Tangan](docs/signatures.md) | [Signatures](docs/en/signatures.md) |
| [Troubleshooting](docs/troubleshooting.md) | [Troubleshooting](docs/en/troubleshooting.md) |
| [Ekstensibilitas](docs/extensibility.md) | [Extensibility](docs/en/extensibility.md) |

Referensi teknis: [Inventaris Endpoint](docs/endpoint-inventory.md) (English).

## Perintah Artisan

| Perintah | Fungsi |
|---|---|
| `singapay:install` | Publish config + migration |
| `singapay:ping` | Cek konektivitas, kredensial, dan IP whitelist |
| `singapay:token` | Ambil & tampilkan access token (debug) |
| `singapay:verify-signature` | Hitung tanda tangan dari file JSON (debug SP016) |

## Kebutuhan

- PHP 8.3+
- Laravel 13

> Paket ini sengaja hanya menargetkan versi Laravel terbaru. Untuk aplikasi pembayaran, framework yang masih menerima patch keamanan adalah prasyarat — Laravel 11 sudah melewati masa dukungan keamanannya dan Composer terbaru bahkan memblokir instalasinya secara default.

## Lisensi

[MIT](LICENSE). Paket tidak resmi — tidak berafiliasi dengan PT Abadi Singapay Indonesia.
