# Instalasi

> 🇬🇧 English version: [docs/en/installation.md](en/installation.md)

## Kebutuhan

- PHP 8.3 atau lebih baru
- Laravel 13
- Kredensial merchant SingaPay (client ID, client secret, partner ID/API key, dan ULID akun) dari dashboard SingaPay
- Server dengan **IP publik statis** yang sudah di-whitelist di dashboard SingaPay

## Langkah instalasi

```bash
composer require aliziodev/laravel-singapay
```

Publish config dan migration, lalu jalankan migrasi (tabel `singapay_webhook_events` dipakai untuk idempotency webhook):

```bash
php artisan singapay:install
php artisan migrate
```

Isi variabel environment:

```env
SINGAPAY_ENV=sandbox
SINGAPAY_CLIENT_ID=your-client-id
SINGAPAY_CLIENT_SECRET=your-client-secret
SINGAPAY_PARTNER_ID=your-api-key
SINGAPAY_ACCOUNT_ID=01J...        # ULID akun default
```

## Verifikasi

```bash
php artisan singapay:ping
```

Perintah ini mengambil access token lalu memanggil balance inquiry. Tiga hasil yang mungkin:

- **Sukses** — kredensial dan IP whitelist beres; saldo tampil.
- **SP017 (Unauthorized IP)** — IP server belum di-whitelist. Daftarkan IP egress publik server di dashboard SingaPay. Ingat: platform serverless (Vercel/Netlify/Workers) tidak punya IP statis dan tidak bisa dipakai untuk memanggil SingaPay secara langsung.
- **401 / Invalid credentials** — periksa kembali client ID, secret, dan partner ID; pastikan `SINGAPAY_ENV` cocok dengan kredensialnya (sandbox vs production).

## Webhook

Route `POST /webhooks/singapay` terdaftar otomatis. Daftarkan URL berikut di dashboard SingaPay (sesuaikan domain):

```
https://app-anda.com/webhooks/singapay
```

URL yang sama dipakai untuk `transaction_notif_url`, `disbursement_notif_url`, dan URL callback lain — paket ini membedakan tipe webhook dari isi payload, bukan dari URL. Lihat [webhooks.md](webhooks.md).

## Ke produksi

1. Ganti `SINGAPAY_ENV=production` beserta kredensial production.
2. Whitelist IP server production.
3. Setel `SINGAPAY_MONEY_OUT=true` **hanya** jika environment tersebut memang boleh memindahkan dana.
4. Jalankan `php artisan singapay:ping` sekali lagi di server production.
5. Tanyakan jadwal settlement dan kebijakan rolling reserve langsung ke SingaPay — keduanya tidak terdokumentasi.
