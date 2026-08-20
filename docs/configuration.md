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
| `account_id` | `SINGAPAY_ACCOUNT_ID` | — | ULID akun default; dipakai ketika pemanggilan tidak menyebut akun |
| `auth_version` | `SINGAPAY_AUTH_VERSION` | `1.1` | `1.1` (HMAC) atau `1.0` (Basic, legacy) |

Kredensial divalidasi **saat dipakai**, bukan saat boot — aplikasi tetap bisa boot (mis. di CI) tanpa kredensial.

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
| `webhooks.middleware` | — | `[]` | Middleware tambahan, mis. `['throttle:60,1']` |

Route didaftarkan **tanpa** group `web` — group `web` menyertakan verifikasi CSRF, sementara SingaPay tidak mengirim token CSRF, sehingga setiap delivery akan ditolak 419 dan berputar di antrean retry selamanya.

## Logging

| Key | Env | Default |
|---|---|---|
| `logging.enabled` | `SINGAPAY_LOGGING` | `true` |
| `logging.channel` | `SINGAPAY_LOG_CHANNEL` | channel default |

SDK hanya mencatat **metadata** request (method, path, status, kode SP, durasi). Body request/response tidak pernah dicatat — ini disengaja dan tidak bisa dikonfigurasi, supaya data kartu dan kredensial mustahil bocor ke file log lewat SDK.
