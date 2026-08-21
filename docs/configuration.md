# Konfigurasi

> 🇬🇧 English version: [docs/en/configuration.md](en/configuration.md)

Semua konfigurasi ada di `config/singapay.php` (publish via `php artisan singapay:install`).

## Kredensial & environment

| Key | Env | Default | Keterangan |
|---|---|---|---|
| `environment` | `SINGAPAY_ENV` | `sandbox` | `sandbox` atau `production` |
| `client_id` | `SINGAPAY_CLIENT_ID` | — | Client ID merchant |
| `client_secret` | `SINGAPAY_CLIENT_SECRET` | — | Client secret (kunci HMAC semua tanda tangan) |
| `partner_id` | `SINGAPAY_PARTNER_ID` | — | API key merchant, dikirim sebagai header `X-PARTNER-ID` |
| `hmac_key` | `SINGAPAY_HMAC_KEY` | — | "HMAC Validation Key" dari dashboard. Bila di-set, verifikasi webhook menerima tanda tangan dari kunci ini ATAU client secret (dokumentasi resmi menyebut client secret, dashboard menerbitkan kunci khusus — SDK menerima keduanya). Tanda tangan keluar tetap memakai client secret |
| `account_id` | `SINGAPAY_ACCOUNT_ID` | — | ULID akun default; dipakai ketika pemanggilan tidak menyebut akun |
| `auth_version` | `SINGAPAY_AUTH_VERSION` | `1.1` | `1.1` (HMAC) atau `1.0` (Basic, legacy) |

Kredensial divalidasi **saat dipakai**, bukan saat boot — aplikasi tetap bisa boot (mis. di CI) tanpa kredensial.

## Beberapa kredensial (connections)

Dashboard SingaPay menerbitkan kredensial **Default** (merchant-wide) dan kredensial **Specific** yang diikat ke sub-akun tertentu lewat daftar *Assigned Accounts*. Begitu sebuah akun ditugaskan ke kredensial Specific, kredensial Default ditolak untuk akun itu dengan SP403. Aplikasi yang melayani beberapa akun karena itu memang butuh beberapa set kredensial.

Kunci-kunci di atas **adalah** koneksi bernama `default`, jadi sebagian besar aplikasi tidak perlu menyentuh bagian ini. Tambahkan entri hanya untuk set kredensial **tambahan**:

```php
'default' => env('SINGAPAY_CONNECTION', 'main'),

'connections' => [
    'payouts' => [
        'client_id' => env('SINGAPAY_PAYOUTS_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_PAYOUTS_CLIENT_SECRET'),
        'partner_id' => env('SINGAPAY_PAYOUTS_PARTNER_ID'),
        'account_id' => env('SINGAPAY_PAYOUTS_ACCOUNT_ID'),
    ],
],
```

```php
SingaPay::paymentLinks()->create([...]);                      // koneksi default
SingaPay::connection('payouts')->disbursement()->transfer([...]);
```

Aturannya:

- Hanya **kunci kredensial** yang boleh diisi di sebuah koneksi: `client_id`, `client_secret`, `partner_id`, `account_id`, `hmac_key`, `auth_version`, `identity`, `biller`. Sisanya — environment, base URL, guard money-out, webhook, logging, timeout — adalah kebijakan aplikasi dan tetap dipakai bersama. Menaruh salah satunya di dalam koneksi **ditolak dengan error**, bukan diabaikan diam-diam; `money_out` yang tersembunyi di sana akan jadi kejutan berbahaya.
- Kunci yang tidak disebut di sebuah koneksi **diwarisi** dari tingkat atas.
- Token tiap koneksi disimpan terpisah (kunci cache-nya memuat hash `client_id`), jadi dua koneksi tidak pernah berbagi token.
- **Semua** secret koneksi ikut diterima saat memverifikasi webhook masuk — lihat [webhooks.md](webhooks.md).

## Base URL

Tiga host SingaPay (payment, biller, identity) masing-masing punya URL sandbox dan production di `base_urls`. Ubah hanya jika SingaPay memberi Anda host berbeda.

## Kredensial Identity/KYC

Layanan verifikasi identitas memakai **pasangan kredensial tersendiri** (`SINGAPAY_IDENTITY_CLIENT_ID`, `SINGAPAY_IDENTITY_CLIENT_SECRET`) dan skema tanda tangan berbeda. Jangan pernah mengisi kredensial payment di sini.

## HTTP

| Key | Default | Keterangan |
|---|---|---|
| `timeout` | `30` | Timeout per request (detik) |
| `retry.times` | `2` | Jumlah retry — **hanya untuk GET** |
| `retry.sleep` | `200` | Jeda antar retry (ms) |

Operasi tulis tidak pernah di-retry otomatis oleh SDK, apa pun konfigurasinya.

## Money-out guard

```php
'money_out' => ['enabled' => env('SINGAPAY_MONEY_OUT', false)],
```

Enam endpoint bertanda tangan (disbursement transfer, e-wallet top-up, QRIS payment credit, account transfer, cardless withdrawal create, direct-debit charge) melempar `MoneyOutDisabledException` selama guard mati. Default **mati** agar environment yang salah konfigurasi tidak pernah bisa memindahkan uang.

## Webhook

| Key | Env | Default | Keterangan |
|---|---|---|---|
| `webhooks.enabled` | `SINGAPAY_WEBHOOKS_ENABLED` | `true` | Daftarkan route webhook |
| `webhooks.path` | `SINGAPAY_WEBHOOK_PATH` | `webhooks/singapay` | Path route |
| `webhooks.verify_signature` | `SINGAPAY_WEBHOOK_VERIFY` | `true` | Verifikasi `X-Signature` (jangan dimatikan di produksi) |
| `webhooks.tolerance` | `SINGAPAY_WEBHOOK_TOLERANCE` | `300` | Toleransi `X-Timestamp` dalam detik (anti-replay) |
| `webhooks.idempotency` | `SINGAPAY_WEBHOOK_IDEMPOTENCY` | `true` | Simpan delivery yang sudah diproses di tabel `singapay_webhook_events` |
| `webhooks.secrets` | `SINGAPAY_WEBHOOK_SECRETS` | — | Kunci **tambahan** di luar semua koneksi, dipisah koma. Hanya perlu untuk kredensial yang Anda **terima kirimannya tapi tidak pernah pakai memanggil API** (kredensial lama, atau milik pihak lain); kalau Anda memanggil API dengannya, deklarasikan sebagai koneksi saja. Tidak memengaruhi tanda tangan keluar |
| `webhooks.middleware` | — | `[]` | Middleware tambahan, mis. `['throttle:60,1']` |

Route didaftarkan **tanpa** group `web` — group `web` menyertakan verifikasi CSRF, sementara SingaPay tidak mengirim token CSRF, sehingga setiap delivery akan ditolak 419 dan berputar di antrean retry selamanya.

## Logging

| Key | Env | Default |
|---|---|---|
| `logging.enabled` | `SINGAPAY_LOGGING` | `true` |
| `logging.channel` | `SINGAPAY_LOG_CHANNEL` | channel default |

SDK hanya mencatat **metadata** request (method, path, status, kode SP, durasi). Body request/response tidak pernah dicatat — ini disengaja dan tidak bisa dikonfigurasi, supaya data kartu dan kredensial mustahil bocor ke file log lewat SDK.
