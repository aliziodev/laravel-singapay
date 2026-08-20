# PRD — SingaPay SDK (Laravel + TypeScript/Next.js)

**Versi:** 1.0
**Tanggal:** 20 Agustus 2026
**Penulis:** Muhamad Hanafi — CV Gonsu Tri Jaya Utama
**Status:** Draft untuk implementasi

---

## 1. Latar Belakang & Tujuan

SingaPay (PT Abadi Singapay Indonesia, PJP1 berizin Bank Indonesia) menyediakan REST API payment gateway yang lengkap, namun **tidak menyediakan SDK resmi** untuk bahasa/framework apa pun. Setiap integrator harus mengimplementasikan sendiri dua skema tanda tangan HMAC yang berbeda, normalisasi JSON yang presisi, manajemen token, dan verifikasi webhook.

Dokumen ini menspesifikasikan dua paket open-source:

| Paket | Nama | Target |
|---|---|---|
| PHP | `gonsu/laravel-singapay` | Laravel 11/12/13, PHP 8.2+ |
| TypeScript | `@gonsu/singapay` | Node 20+, framework-agnostic core + adapter Next.js |

### Tujuan

1. **Primer** — kebutuhan internal Gonsu: checkout CekProfit, dan ke depan Hayshe OMS / Satulapak.
2. **Sekunder** — rilis publik di Packagist & npm. Tidak ada kompetitor; ini peluang menjadi SDK de-facto untuk SingaPay.

### Non-Tujuan (v1)

- Bukan pengganti dashboard SingaPay (tidak ada UI).
- Tidak menyimpan/merutekan data kartu mentah (PCI-DSS di luar cakupan — lihat §9.3).
- Tidak menyediakan UI checkout/komponen React siap pakai di v1.

---

## 2. Sumber Kebenaran (Source of Truth)

Semua implementasi wajib mengacu pada:

| Sumber | URL | Catatan |
|---|---|---|
| Indeks dokumentasi | `https://docs.singapay.id/llms.txt` | **Indeks resmi, tapi tidak lengkap** — lihat peringatan di bawah |
| OpenAPI (payment) | `https://docs.singapay.id/api-reference/openapi.json` | Parsial: hanya Accounts, Balance, Card, E-Wallet MI, Payment Link, QRIS MI, VA |
| OpenAPI (merchant) | `https://payment-b2b.singapay.id/api/docs/merchant-api.json` | Cek apakah lebih lengkap dari yang di atas |
| OpenAPI (biller) | `https://docs.singapay.id/openapi-biller.json` | Layanan biller terpisah |
| Swagger (identity) | `https://core.singapay.id/identity-verification/docs/swagger.json` | Layanan verifikasi identitas terpisah |

> ⚠️ **PENTING — dokumentasi tidak konsisten.** Ditemukan dua kesenjangan saat riset:
> 1. `llms.txt` **tidak mencantumkan** endpoint Card (Money In), padahal ada di `openapi.json` (`/v2.0/card/...`).
> 2. `llms.txt` **tidak mencantumkan** `disbursement-money-out/check-beneficiary`, padahal halaman Overview disbursement merujuknya secara eksplisit.
>
> **Instruksi implementasi:** jangan percaya satu sumber saja. Silangkan `llms.txt` + ketiga OpenAPI spec + halaman `introduction.md` tiap resource. Setiap "Overview" resource memuat CardGroup berisi daftar endpoint miliknya — itu yang paling andal per-resource.

---

## 3. Environment

| Environment | Base URL |
|---|---|
| Sandbox | `https://sandbox-payment-b2b.singapay.id` |
| Production | `https://payment-b2b.singapay.id` |

Semua path API diawali prefix `/api`. Contoh lengkap: `https://payment-b2b.singapay.id/api/v1.1/access-token/b2b`.

Layanan terpisah dengan host berbeda:
- Biller: `https://biller-b2b.singapay.id`
- Identity Verification: `https://core.singapay.id/identity-verification`

---

## 4. Autentikasi — Tiga Skema Berbeda

Ini bagian paling rawan salah. **Ada tiga skema tanda tangan yang berbeda dalam satu produk.** Implementasi harus memisahkannya dengan tegas.

### 4.1 Skema A — Access Token v1.1 (utama)

`POST /api/v1.1/access-token/b2b`

```
payload     = "{client_id}_{client_secret}_{YYYYMMDD}"
X-Signature = HMAC-SHA512(payload, client_secret)   → hex lowercase
```

Header wajib: `X-PARTNER-ID`, `X-CLIENT-ID`, `X-Signature`
Body: `{"grant_type": "client_credentials"}`

- `YYYYMMDD` adalah tanggal **Asia/Jakarta (UTC+7)**, bukan UTC. Tanda tangan hanya valid untuk hari kalender tersebut.
- ⚠️ **Contoh Node.js di dokumentasi resmi SingaPay salah** — menggunakan `new Date().toISOString()` yang menghasilkan tanggal UTC. Antara pukul 00:00–07:00 WIB ini menghasilkan tanggal kemarin dan tanda tangan ditolak. SDK **wajib** memaksa timezone `Asia/Jakarta`.

### 4.2 Skema B — Access Token v1.0 (legacy)

`POST /api/v1.0/access-token/b2b`

Header: `Authorization: Basic base64(client_id:client_secret)`, `X-PARTNER-ID`
Body: `grant_type=client_credentials`

Dukung sebagai opsi konfigurasi (`auth_version: '1.0' | '1.1'`), default `1.1`.

### 4.3 Skema C — Request Signature (money-out)

Untuk endpoint yang memindahkan uang. **Beda total dari Skema A.**

```
1. Sort key body secara rekursif & alfabetis
2. hashed_body   = SHA-256(normalized_json)              → hex
3. string_to_sign = "{METHOD}:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
4. X-Signature   = HMAC-SHA512(string_to_sign, client_secret) → hex lowercase
```

- `METHOD` — uppercase
- `ENDPOINT` — path **termasuk query string**, tanpa domain, termasuk prefix `/api`
- `ACCESS_TOKEN` — bearer token tanpa prefix `Bearer `
- `TIMESTAMP` — Unix **detik** (bukan milidetik), dikirim sebagai string di header `X-Timestamp`

Header wajib: `X-PARTNER-ID`, `Authorization: Bearer <token>`, `X-Timestamp`, `X-Signature`

Endpoint yang memerlukan skema ini (konfirmasi per endpoint di dokumentasi):
- `POST /api/v2.0/disbursement/transfer`
- Disbursement Inquiry Status
- `POST /api/v2.0/ewallet/trigger-topup`
- QRIS Issuer (money out) — trigger payment credit & terkait
- Account Transfer (transfer antar akun)

### 4.4 Skema D — Identity Verification (layanan terpisah)

`Exchange HMAC-signed credentials for a Bearer access token`

```
signature = HMAC-SHA256("{client_id}:{timestamp}", client_secret)
```

⚠️ **SHA-256, bukan SHA-512. Separator titik dua, bukan underscore. Tanpa komponen tanggal.** Ini kredensial dan skema yang sepenuhnya berbeda, untuk host `core.singapay.id`. Isolasi di modul terpisah agar tidak tertukar.

### 4.5 Verifikasi Webhook (inbound)

```
string_to_sign = "POST:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
expected       = HMAC-SHA512(string_to_sign, client_secret)
```

- `ACCESS_TOKEN` diambil dari header `Authorization` yang **SingaPay kirim ke kita** (strip `Bearer `) — bukan token kita sendiri.
- `ENDPOINT` harus persis sama dengan path URL webhook yang dikonfigurasi di dashboard, termasuk query string.
- Body harus di-hash dari **raw body**, bukan hasil parse ulang.
- Perbandingan wajib constant-time (`hash_equals` / `timingSafeEqual`).
- Validasi `X-Timestamp` dalam toleransi ±5 menit (konfigurable) untuk cegah replay.

---

## 5. Normalisasi JSON — Komponen Paling Kritis

**Ini penyebab bug tanda tangan nomor satu.** Harus jadi modul tersendiri dengan test paling ketat di kedua paket.

Aturan:
1. Sort key object secara **rekursif** dan alfabetis (PHP: `ksort($arr, SORT_STRING)`).
2. Array (list) **tidak** di-sort — urutan elemen dipertahankan, tapi isi tiap elemen tetap di-sort rekursif.
3. Encode: PHP `json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`; JS `JSON.stringify` (tanpa spasi).
4. SHA-256 hex atas hasil encode.

### Perbedaan PHP ↔ JS yang wajib ditangani

| Kasus | PHP | JS | Risiko |
|---|---|---|---|
| Float bulat | `json_encode(100000.0)` → `100000.0` | `JSON.stringify(100000.0)` → `100000` | **Signature mismatch** |
| Empty array vs object | `[]` → `[]`, `(object)[]` → `{}` | `[]` → `[]`, `{}` → `{}` | Ambigu di PHP |
| Slash | tanpa flag → `\/` | → `/` | Wajib `JSON_UNESCAPED_SLASHES` |
| Unicode | tanpa flag → `\uXXXX` | literal | Wajib `JSON_UNESCAPED_UNICODE` |
| Sort locale | `SORT_STRING` = byte order | `.sort()` = UTF-16 code unit | Beda untuk non-ASCII |

**Keputusan desain:** SDK **wajib** mengirim nominal sebagai integer, tidak pernah float. Sediakan helper `Amount` yang menolak float dan memaksa integer rupiah. Untuk key non-ASCII, gunakan pembanding byte-order eksplisit di JS (`Buffer.compare`), bukan `.sort()` bawaan.

### Test wajib (kedua paket, fixture identik)

Buat file fixture bersama `signature-vectors.json` berisi minimal 15 kasus, dipakai oleh test suite PHP **dan** TS:

- body kosong `{}`
- key tidak urut, nested 3 level
- array of object
- unicode (`"nama": "Muhamad Hanafi — Café"`)
- slash dalam URL (`"redirect_url": "https://gonsu.id/thanks"`)
- nilai null, boolean, integer besar (`999999999`)
- key dengan karakter non-ASCII
- string yang tampak angka (`"100000"` vs `100000`)

Setiap vector menyimpan `normalized_json`, `hashed_body`, dan `expected_signature` untuk secret uji tetap. Kedua paket harus menghasilkan hasil yang identik byte-per-byte.

---

## 6. Inventaris Endpoint Lengkap

Tandai `[S]` = butuh Request Signature (Skema C).

### 6.1 Security / Auth
| Method | Path | Keterangan |
|---|---|---|
| POST | `/api/v1.1/access-token/b2b` | HMAC-SHA512, utama |
| POST | `/api/v1.0/access-token/b2b` | Basic Auth, legacy |

### 6.2 Accounts (Sub-Account)
| Method | Path |
|---|---|
| GET | `/api/v1.0/accounts` |
| POST | `/api/v1.0/accounts` |
| GET | `/api/v1.0/accounts/{id}` |
| DELETE | `/api/v1.0/accounts/{id}` |
| PATCH | `/api/v1.0/accounts/update-status/{id}` |
| PATCH/PUT | Update account (name / status / `invite_members`) — konfirmasi path di docs |

Catatan: `account_type` = `owned` / `personal_managed` (aktif langsung) atau `business_managed` (perlu approval KYB). Sub-akun managed punya `kyb_onboarding_url`.

### 6.3 Balance Inquiry
| Method | Path |
|---|---|
| GET | `/api/v1.0/balance-inquiry` (merchant, agregat) |
| GET | `/api/v1.0/balance-inquiry/{account_id}` (per akun) |

Response: `held_balance`, `available_balance`, `pending_balance`, `balance` — masing-masing `{value: string, currency: "IDR"}`.

### 6.4 Account Transfer
| Method | Endpoint |
|---|---|
| GET | List account transfers |
| GET | Show account transfer detail |
| POST | **[S]** Transfer between accounts (`beneficiary_account_number`) |

### 6.5 Statements
| Method | Endpoint |
|---|---|
| GET | List statements (filter `processed_timestamp`, default bulan berjalan, maks 1 tahun, 25/halaman) |
| GET | Show statement (by `statements.transaction_id`) |

### 6.6 Payment Link
| Method | Path |
|---|---|
| GET | `/api/v1.0/payment-link-manage/{account_id}` |
| POST | `/api/v1.0/payment-link-manage/{account_id}` |
| GET | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` |
| PUT | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` |
| DELETE | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` |
| GET | `/api/v1.0/payment-link-manage/payment-methods` |

Aturan: `total_amount` harus sama persis dengan jumlah subtotal `items`. `reff_no` maks 40 char, tanpa spasi/slash. `expired_at` = Unix **milidetik** 13 digit (berbeda dari `X-Timestamp` yang detik). `max_usage` ≥ `current_usage` saat update.

### 6.7 Payment Link History
| Method | Path |
|---|---|
| GET | `/api/v1.0/payment-link-histories/{account_id}` |
| GET | `/api/v1.0/payment-link-histories/{account_id}/{history_id}` |

### 6.8 Virtual Account
| Method | Path |
|---|---|
| GET | `/api/v1.0/virtual-accounts/{account_id}` |
| POST | `/api/v1.0/virtual-accounts/{account_id}` |
| GET | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` |
| PUT | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` |
| PATCH | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` |
| DELETE | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` |

`amount_type`: open / closed. `kind`: temporary (butuh `expired_at` + `max_usage`) / permanent. `{virtual_account_id}` adalah **ULID**, bukan nomor VA.

### 6.9 VA Transaction
| Method | Path |
|---|---|
| GET | `/api/v1.0/va-transactions/{account_id}` |
| GET | `/api/v1.0/va-transactions/{account_id}/{transaction_id}` |
| GET | `/api/v1.0/va-transactions/{account_id}/detail-by-va-number/{virtual_account_no}` |

### 6.10 QRIS (Money In)
| Method | Path |
|---|---|
| POST | `/api/v1.0/qris-dynamic/{account_id}/generate-qr` |
| GET | `/api/v1.0/qris-dynamic/{account_id}` |
| GET | `/api/v1.0/qris-dynamic/{account_id}/show/{id}` |

### 6.11 E-Wallet (Money In)
| Method | Path |
|---|---|
| POST | `/api/v1.0/ewallet-native/{account_id}/create-checkout` (v1 legacy) |
| POST | `/api/v2.0/ewallet-native/create-order` (v2, `account_id` di body) |
| GET | `/api/v1.0/ewallet-native-transactions/{account_id}` |
| GET | `/api/v1.0/ewallet-native-transactions/{account_id}/{transaction_id}` |
| GET | `/api/v1.0/ewallet-native/{account_id}/inquiry-status/{id}` |

Vendor: DANA, OVO, ShopeePay, GoPay. OVO butuh `customer_phone` (push-to-pay).

### 6.12 Card (Money In) — ⚠️ tidak ada di `llms.txt`
| Method | Path |
|---|---|
| POST | `/api/v2.0/card/{account_id}/payment` |
| PATCH | `/api/v2.0/card/{account_id}/cancel/{id}` (void atau refund tergantung state) |
| GET | `/api/v2.0/card/{account_id}/inquiry-status/{id}` |

Provider: Nicepay. Mendukung installment 3/6/12 bulan. **Lihat §9.3 soal PCI-DSS.**

### 6.13 Subscription / Recurring
| Endpoint |
|---|
| Create Plan (mengembalikan payment link) |
| Get Plan (by UUID) |
| Update Plan (patch in-place, atau upgrade/downgrade bila `amount`/`items` berubah) |
| Cancel Plan |

### 6.14 Direct Debit
| Endpoint |
|---|
| Binding Card (redirect ke `data.redirect_url`, sekali pakai, ~15 menit) |
| Binding Status (poll `PENDING_AUTH → ACTIVE`) |
| Unbind Card (bisa balas HTTP 202 + `otp_required` + `otp_handoff`) |
| Charge (bisa balas `requires_otp=true`) |
| Verify OTP (`transaction_id` untuk payment ATAU `binding_id`+`unbind_context` untuk unbind — kirim keduanya atau tidak sama sekali = error) |
| Get Transaction (auto-reconcile bila non-terminal melewati window) |

### 6.15 Disbursement (Money Out)
| Endpoint |
|---|
| List |
| Show (by business `transaction_id`) |
| Check Fee |
| Check Beneficiary Bank Account — ⚠️ tidak ada di `llms.txt` |
| **[S]** Disburse Transfer — `POST /api/v2.0/disbursement/transfer` (`account_id` di body) |
| **[S]** Inquiry Status (by `reference_number`) |

`bank_code` menerima kode numerik 3 digit (`014`) atau SWIFT (`CENAIDJA`).

### 6.16 QRIS (Money Out / Issuer)
| Endpoint |
|---|
| Inquiry Merchant (decode `qr_data` MPM) |
| **[S]** Trigger Payment Credit |
| **[S]** Inquiry Status (`reference_number`, `type`, `scope`) |

### 6.17 E-Wallet (Money Out)
| Endpoint |
|---|
| Inquiry Account (validasi + limit + fee) |
| **[S]** Trigger Top-Up — `POST /api/v2.0/ewallet/trigger-topup` |
| **[S]** Inquiry Status |

### 6.18 Cardless Withdrawal
| Endpoint |
|---|
| List (12 bulan terakhir, 25/halaman) |
| Show Detail (by `reference_number`, bukan `transaction_id`) |
| Create (kelipatan 50.000, `account_id` di body, rate-limited) |
| Cancel (hanya status `open`, `account_id` + reference di body) |

### 6.19 Biller — host `biller-b2b.singapay.id`
| Grup | Endpoint |
|---|---|
| General | Inquiry Balance, Get Transaction Detail, List Transactions, Reset Customer ID (dev only) |
| Prepaid v2 | Get Parameters Game Topup, Prepaid Inquiry v2, Payment Prepaid v2 |
| Postpaid v2 | Inquiry Postpaid v2, Payment Postpaid v2 |
| Prepaid v1 | Inquiry Prepaid (legacy), Payment Prepaid (legacy) |
| Postpaid v1 | Inquiry Postpaid (legacy), Payment Postpaid (legacy) |

Kategori produk: PLN Token, Pulsa, Paket Data, Game Topup, Voucher Game, Utilities, Telco/Internet, Government & Social Insurance.

### 6.20 Identity Verification — host `core.singapay.id`
| Endpoint |
|---|
| Exchange HMAC-signed credentials for Bearer token (Skema D) |
| Verify the holder name on a bank account |
| Verify the holder name on an e-wallet account |

### 6.21 Webhooks (13 tipe, inbound)
| Webhook | Callback URL config |
|---|---|
| Virtual Account | `transaction_notif_url` |
| QRIS Acquirer | `transaction_notif_url` |
| Payment Link | `transaction_notif_url` |
| E-Wallet Native (money in) | `transaction_notif_url` |
| Subscription Cycle | (khusus) |
| Disbursement | `disbursement_notif_url` |
| E-Wallet Top Up (money out) | `disbursement_notif_url` |
| QRIS Issuer (money out) | `disbursement_notif_url` |
| Settlement Notification | (khusus) |
| Direct Debit binding | `direct_debit_notif_url` |
| Payment Link Inquiry (opsional) | (khusus) |
| Product Expiration (opsional, batch) | (khusus) |
| Transaction Money-In Expiration (opsional, batch) | (khusus) |

Referensi tambahan wajib dibaca: `webhooks/retry-mechanism`, `webhooks/shared-endpoints`, `references/transaction-status`.

**Konsekuensi desain penting:** beberapa tipe webhook berbagi satu URL callback. Router webhook harus mendiskriminasi berdasarkan field `event` dan/atau bentuk payload, bukan berdasarkan URL. Baca `shared-endpoints` untuk aturan diskriminasi resmi.

---

## 7. Response Code (SP-codes)

`response_code` menyatakan hasil **request**, bukan status transaksi. `SP000` ≠ pembayaran berhasil.

| Kode | Arti | Aksi SDK |
|---|---|---|
| `SP000` | Successfully | Lanjut, cek `transaction_status` |
| `SP001` | Transaction Failure | Panggil inquiry-status |
| `SP002` | General Failure | Retry dengan backoff |
| `SP003` | Insufficient Balance | Throw `InsufficientBalanceException`, **jangan retry** |
| `SP004` | Duplicate Reference Number | Throw `DuplicateReferenceException`, **jangan retry** |
| `SP005` | Timeout | Panggil inquiry-status |
| `SP006` | Exceed Beneficiary Limit | Jangan retry |
| `SP007` | Exceed Account Limit | Jangan retry |
| `SP008` | Invalid Reference Number | Jangan retry |
| `SP009` | Transaction Not Found | Jangan retry |
| `SP010` | Beneficiary Account Not Found | Jangan retry |
| `SP011` | Beneficiary Vendor Not Active | Retry nanti / channel lain |
| `SP012` | Bad Request | Jangan retry |
| `SP013` | Unauthorized | Refresh token, retry **satu kali** |
| `SP014` | Not Found | Jangan retry |
| `SP015` | Forbidden | Jangan retry |
| `SP016` | Signature Invalid | Jangan retry — bug SDK, log lengkap |
| `SP017` | Unauthorized IP | Jangan retry — throw pesan eksplisit soal IP whitelist |
| `SP018` | Validation Error | Parse `data.errors`, jangan retry |
| `SP019` | General Error | Jangan retry |
| `SP020` | Merchant Account Not Found | Jangan retry |

Dua bentuk envelope response berbeda:
- **v1 (laravel-responder):** `{status, success, data, error:{message,code}}`
- **v2:** `{response_code, response_message, data}`

SDK harus menormalkan keduanya jadi satu tipe `SingaPayResponse` yang konsisten.

**Aturan mutlak:** jangan pernah retry otomatis operasi money-out. Untuk `SP001/SP002/SP005`, panggil inquiry-status dulu. Retry buta = duplikasi transfer uang nyata.

---

## 8. Arsitektur Paket

### 8.1 Laravel — `gonsu/laravel-singapay`

```
src/
├── Contracts/
│   ├── SingaPayClientInterface.php
│   ├── SignerInterface.php
│   ├── TokenRepositoryInterface.php
│   └── JsonNormalizerInterface.php
├── Http/
│   ├── Client.php                    # wrapper Illuminate\Http\Client
│   ├── Middleware/VerifyWebhookSignature.php
│   └── Controllers/WebhookController.php
├── Auth/
│   ├── AccessTokenManager.php        # cache-aware, auto-refresh
│   ├── AccessTokenSigner.php         # Skema A
│   ├── RequestSigner.php             # Skema C
│   └── IdentitySigner.php            # Skema D — terisolasi
├── Support/
│   ├── JsonNormalizer.php            # ⚠️ komponen kritis
│   ├── Amount.php                    # integer-only value object
│   └── JakartaClock.php              # timezone Asia/Jakarta terpusat
├── Testing/
│   ├── SingaPayFake.php              # SingaPay::fake() untuk test konsumen
│   └── Concerns/InteractsWithSingaPay.php
├── Endpoints/                        # satu class per grup endpoint
│   ├── Accounts.php
│   ├── Balance.php
│   ├── AccountTransfer.php
│   ├── Statements.php
│   ├── PaymentLinks.php
│   ├── PaymentLinkHistories.php
│   ├── VirtualAccounts.php
│   ├── VaTransactions.php
│   ├── Qris.php
│   ├── EwalletMoneyIn.php
│   ├── Card.php
│   ├── Subscriptions.php
│   ├── DirectDebit.php
│   ├── Disbursement.php
│   ├── QrisMoneyOut.php
│   ├── EwalletMoneyOut.php
│   ├── CardlessWithdrawal.php
│   ├── Biller.php
│   └── IdentityVerification.php
├── Data/                             # DTO readonly, satu per resource
├── Events/                           # 13 event webhook
├── Exceptions/
└── SingaPayServiceProvider.php
```

**Catatan penamaan:** jangan gunakan `src/Resources/`. Di Laravel, "Resource" sudah berarti `Illuminate\Http\Resources\Json\JsonResource` (transformer output HTTP) — makna yang berbeda total dari wrapper endpoint API. `src/Endpoints/` menghindari kebingungan ini.

**Keputusan arsitektur:**

- **Injeksi kontrak, bukan Facade, di dalam paket.** Facade `SingaPay` hanya untuk konsumen.
- **Sediakan `SingaPay::fake()`.** Konsumen paket harus bisa menguji kode mereka tanpa mock HTTP manual. `SingaPayFake` merekam panggilan dan mengembalikan fixture, dengan assertion seperti `SingaPay::assertPaymentLinkCreated(fn ($link) => $link->reff_no === 'INV-001')`. Ini pembeda terbesar antara SDK yang enak dipakai dan yang menyebalkan — dan hampir selalu terlewat.
- **⚠️ Route webhook JANGAN pakai middleware group `web`.** Group `web` menyertakan `VerifyCsrfToken`; SingaPay POST tanpa CSRF token sehingga setiap webhook akan ditolak **419** dan masuk antrean retry selamanya. Daftarkan route secara programatis di service provider dengan middleware eksplisit (hanya `VerifyWebhookSignature` + throttle bila perlu), tanpa session dan tanpa CSRF. Jangan pakai `routes/web.php`; gunakan `routes/webhooks.php` atau `Route::post()` langsung.
- **Token cache via `Illuminate\Contracts\Cache\Repository`.** Kunci: `singapay:token:{env}:{client_id_hash}`. TTL = `expires_in` dikurangi buffer 60 detik. Gunakan `Cache::lock()` agar tidak terjadi thundering herd saat refresh.
- **Webhook via Event, bukan closure.** Setiap tipe webhook memancarkan event Laravel (`SingaPayVirtualAccountPaid`, `SingaPayDisbursementCompleted`, dst). Konsumen mendaftarkan listener sendiri. Ini jauh lebih Laravel-ish daripada callback config.
- **Route webhook opsional & konfigurable.** `config('singapay.webhooks.enabled')`, prefix bisa diatur. Middleware `VerifyWebhookSignature` menolak 401 sebelum controller.
- **Idempotency built-in.** Migration `singapay_webhook_events` (kolom: `event_id`, `event_type`, `payload`, `processed_at`, unique index). Middleware skip + balas 200 bila `event_id` sudah ada. Publishable, bisa dinonaktifkan.
- **Money-out di belakang guard eksplisit.** Method money-out melempar exception bila `config('singapay.money_out.enabled')` false (default **false**). Ini mencegah kecelakaan fatal di environment yang salah.

Config `config/singapay.php` minimal:
```php
'environment'   => env('SINGAPAY_ENV', 'sandbox'),
'client_id'     => env('SINGAPAY_CLIENT_ID'),
'client_secret' => env('SINGAPAY_CLIENT_SECRET'),
'partner_id'    => env('SINGAPAY_PARTNER_ID'),
'account_id'    => env('SINGAPAY_ACCOUNT_ID'),   // default account ULID
'auth_version'  => env('SINGAPAY_AUTH_VERSION', '1.1'),
'timeout'       => 30,
'retry'         => ['times' => 2, 'sleep' => 200],  // hanya GET & idempotent
'money_out'     => ['enabled' => env('SINGAPAY_MONEY_OUT', false)],
'webhooks'      => [
    'enabled'          => true,
    'path'             => 'webhooks/singapay',
    'verify_signature' => true,
    'tolerance'        => 300,
    'idempotency'      => true,
],
'logging'       => ['enabled' => true, 'channel' => null, 'redact' => true],
```

Artisan commands:
- `singapay:install` — publish config + migration
- `singapay:token` — ambil & tampilkan token (debug)
- `singapay:ping` — cek konektivitas + balance inquiry + validasi IP whitelist
- `singapay:verify-signature` — hitung tanda tangan dari file JSON (debug)

### 8.2 TypeScript — `@gonsu/singapay`

Monorepo, core framework-agnostic + adapter tipis:

```
packages/
├── core/          # @gonsu/singapay — framework-agnostic, zero-dep runtime
│   ├── auth/      # ketiga skema signer
│   ├── normalize/ # JsonNormalizer — port persis dari PHP
│   ├── endpoints/ # sama seperti daftar di §6
│   ├── webhooks/  # verifyWebhook() primitif + event discriminator
│   ├── testing/   # createSingaPayMock() untuk test konsumen
│   └── types/     # tipe hasil generate dari OpenAPI + tulis tangan
├── next/          # @gonsu/singapay-next  — App Router route handler
└── nuxt/          # @gonsu/singapay-nuxt  — Nitro event handler
```

#### Kompatibilitas runtime — server-side saja

Core memakai Web Crypto API (`crypto.subtle`) dan tanpa dependency runtime, sehingga berjalan di:

| Runtime / Framework | Status | Cara pakai webhook |
|---|---|---|
| Node.js 20+ | ✅ | `verifyWebhook()` langsung |
| Next.js (App Router) | ✅ | adapter `@gonsu/singapay-next` |
| Nuxt / Nitro | ✅ | adapter `@gonsu/singapay-nuxt` (`readRawBody`) |
| Express / Fastify / Hono | ✅ | `verifyWebhook()` + raw body middleware |
| SvelteKit / Remix / Astro | ✅ | `verifyWebhook()` + `await request.text()` |
| Bun / Deno | ✅ | `verifyWebhook()` langsung |
| **React SPA (browser)** | ❌ | **Tidak didukung — lihat di bawah** |
| **Vue SPA (browser)** | ❌ | **Tidak didukung — lihat di bawah** |

> ⚠️ **SDK ini server-only secara desain, bukan karena keterbatasan.**
>
> Setiap request memerlukan `client_secret` untuk menandatangani. Begitu secret itu masuk bundle browser, siapa pun dapat membacanya lewat DevTools dan melakukan disbursement dari saldo merchant. Ini bukan hal yang bisa "diperbaiki" — memindahkan penandatanganan ke klien akan menghancurkan seluruh model keamanan.
>
> **Arsitektur yang benar untuk SPA React/Vue:**
> ```
> React/Vue (browser) → backend Anda (Laravel/Nitro/Express) → SDK → SingaPay
> ```
> Yang dikirim ke browser hanya artefak publik: `payment_url`, `qr_string`, `checkout_url`, `virtual_account_no`. Tidak pernah kredensial, tidak pernah tanda tangan.
>
> **Penegakan teknis wajib:** package menyertakan `import 'server-only'` (Next.js), export condition `"browser": { "./index.js": "./browser-guard.js" }` di `package.json` yang langsung `throw`, dan runtime guard `if (typeof window !== 'undefined') throw new SingaPayBrowserError(...)`. Lebih baik gagal keras saat build daripada membocorkan secret secara diam-diam.

#### Kenapa adapter, bukan hanya core?

Satu-satunya bagian yang benar-benar berbeda antar framework adalah **akses raw body** — dan itu justru bagian yang kritis, karena hashing harus dilakukan atas byte mentah, bukan hasil parse ulang. Framework yang otomatis mem-parse JSON (Express `body-parser`, Nuxt default) akan merusak tanda tangan secara diam-diam.

Karena itu: **core mengekspos `verifyWebhook(rawBody, headers, endpoint, secret)` sebagai primitif**, dan adapter hanya dibuat untuk framework yang penanganan raw body-nya berbelit (Next.js, Nuxt). Sisanya cukup resep di dokumentasi — jangan buat adapter untuk setiap framework, itu beban pemeliharaan tanpa nilai tambah.

**Keputusan arsitektur:**

- **Core tanpa dependency runtime.** Pakai Web Crypto API (`crypto.subtle`) agar jalan di Node dan edge runtime. Fallback ke `node:crypto` bila perlu.
- **Server-only enforcement.** Package `next` menyertakan `import 'server-only'`. `client_secret` tidak boleh pernah sampai ke bundle browser. Tambahkan runtime guard yang throw bila `typeof window !== 'undefined'`.
- **Token store pluggable.** Default in-memory. Sediakan adapter Redis dan `unstable_cache` Next.js. Serverless butuh ini — instance memori tidak persisten.
- **Webhook handler App Router:**
  ```ts
  export const POST = createWebhookHandler({
    onVirtualAccountPaid: async (event) => { /* ... */ },
    onDisbursementCompleted: async (event) => { /* ... */ },
  });
  ```
  Handler membaca raw body via `await req.text()` **sebelum** parse — kritis untuk hashing.
- **Tipe dari OpenAPI.** Generate dengan `openapi-typescript` dari ketiga spec, lalu lapisi tipe tulis tangan untuk endpoint yang tidak ada di spec (Card, check-beneficiary, dll). Script `pnpm generate:types` masuk CI agar drift terdeteksi.

---

## 9. Kendala & Risiko yang Wajib Ditangani

### 9.1 ⚠️ IP Whitelist vs Serverless — kendala arsitektur terbesar

SingaPay mewajibkan IP server terdaftar. **Vercel, Netlify, dan Cloudflare Workers menggunakan IP egress dinamis** — tidak bisa di-whitelist.

Konsekuensi konkret untuk stack Gonsu:
- Next.js di Vercel **tidak bisa** memanggil SingaPay API secara langsung.
- Opsi: (a) deploy Next.js di VPS/Coolify dengan IP statis, (b) rute panggilan API lewat proxy ber-IP statis, (c) pakai Vercel Secure Compute (berbayar, enterprise).

**Instruksi:** README package `next` harus memuat peringatan ini di bagian paling atas, bukan di catatan kaki. Sertakan contoh proxy minimal. `singapay:ping` / `singapay ping` harus mendeteksi `SP017` dan menjelaskan penyebabnya secara eksplisit.

### 9.2 Jadwal settlement & rolling reserve tidak terdokumentasi

Dokumentasi tidak menyebutkan T+berapa settlement diproses, maupun batas rolling reserve. Ini tidak memengaruhi kode SDK, tapi **harus dicatat di README** agar pengguna tahu untuk menanyakannya langsung ke SingaPay sebelum produksi.

### 9.3 PCI-DSS pada endpoint Card

`POST /api/v2.0/card/{account_id}/payment` menerima `card_number`, `card_cvv`, `card_expiry` mentah. Server mana pun yang menyentuh data ini masuk ruang lingkup PCI-DSS.

**Keputusan:** implementasikan endpoint Card, tetapi:
- Beri `@deprecated`-style warning di docblock/JSDoc.
- README mencantumkan peringatan PCI-DSS yang tegas.
- Jangan pernah log request body endpoint ini, bahkan saat `logging.enabled = true`. Redaksi paksa di layer transport, bukan opsional.
- Sarankan Payment Link sebagai alternatif non-PCI.

### 9.4 Inkonsistensi tipe ID antar endpoint

Ini akan menyebabkan bug diam-diam kalau tidak ditangani:

| Konteks | Tipe ID |
|---|---|
| `account_id` | ULID string |
| `virtual_account_id` | ULID string |
| `payment_link_id` | integer (`payment_links.id`) |
| `payment_link_histories.id` | integer |
| `ewallet_transactions.id` | integer |
| `qris_transactions.id` | integer |
| VA transaction | **business** `transaction_id` string |
| Disbursement show | business `transaction_id`, bukan PK |
| Cardless withdrawal show | `reference_number`, bukan `transaction_id` |
| Subscription plan | UUID |
| Direct Debit binding | UUID (dipaksa di route layer, malformed → 404) |

Gunakan branded types di TS dan value object di PHP untuk mencegah tertukar.

### 9.5 Satuan waktu tidak seragam

- `X-Timestamp` — Unix **detik**
- `expired_at` payment link — Unix **milidetik** (13 digit, string)
- `post_timestamp_from/to` VA transaction — Unix **milidetik**
- `expired_at` e-wallet — ISO 8601 datetime
- Access token signature — string `YYYYMMDD` Asia/Jakarta

Sentralisasi di `JakartaClock` / `TimeFormat`. Jangan biarkan konversi tersebar.

---

## 10. Rencana Rilis Bertahap

Membangun ~70 endpoint sekaligus tidak realistis dan tidak perlu. Prioritaskan berdasarkan kebutuhan nyata.

### Fase 1 — Fondasi + Money In (target: CekProfit live)
Auth (Skema A & B), JsonNormalizer + signature vectors, HTTP client, error mapping, token cache, Payment Link (CRUD + payment methods), Payment Link History, Virtual Account, VA Transaction, QRIS Money In, E-Wallet Money In (v1 & v2), Balance Inquiry, webhook verification + 4 tipe webhook money-in, Settlement webhook.

**Kriteria selesai:** checkout CekProfit berjalan end-to-end di sandbox, webhook terverifikasi, idempotency teruji.

### Fase 2 — Money Out
Skema C (Request Signature), Disbursement lengkap (termasuk check-beneficiary), E-Wallet Money Out, Account Transfer, Statements, webhook disbursement + e-wallet top-up. Guard `money_out.enabled`.

### Fase 3 — Lanjutan
Accounts/Sub-account, Subscription, Direct Debit, QRIS Money Out, Cardless Withdrawal, Card (dengan peringatan PCI), sisa webhook.

### Fase 4 — Layanan terpisah
Biller (prepaid/postpaid v1 & v2), Identity Verification (Skema D).

Rilis publik v1.0.0 setelah Fase 2 selesai dan teruji produksi minimal 30 hari di CekProfit. Fase 3–4 masuk sebagai MINOR release.

---

## 11. Testing

### Wajib
- **Signature vectors bersama** (§5) — dijalankan di kedua paket dari fixture identik. Ini test paling penting di repo.
- **Contract test** per endpoint dengan HTTP faking (`Http::fake()` / `msw`), fixture response dari sandbox nyata.
- **Webhook verification test** — signature valid, invalid, timestamp kadaluarsa, body dimodifikasi, unicode, query string.
- **Token refresh** — expired, race condition (concurrent lock), `SP013` → refresh sekali.
- **Idempotency** — webhook duplikat tidak diproses dua kali.
- **Money-out guard** — throw bila config false.

### Matriks CI
- PHP: 8.2, 8.3, 8.4 × Laravel 11, 12, 13 (kecualikan PHP 8.2 × Laravel 13). Pest, min coverage 80%, Pint, **Larastan level 8** (`phpstan.neon` — wajib untuk package yang menangani uang; tipe ketat mencegah seluruh kelas bug di kode tanda tangan).
- TS: Node 20, 22, 24. Vitest, `tsc --noEmit`, publint, Biome/ESLint.
- Job terpisah: bandingkan output normalizer PHP vs TS terhadap fixture bersama. **Gagal = build merah.**

### Sandbox smoke test
Workflow manual (bukan di PR) yang memanggil sandbox nyata: token, balance inquiry, create payment link, create VA. Butuh secret repo + IP runner ter-whitelist — kemungkinan besar perlu self-hosted runner atau dijalankan lokal.

---

## 12. Dokumentasi & Rilis

Struktur `docs/` per skill `laravel-package-dev`: `installation.md`, `configuration.md`, `usage.md`, `webhooks.md`, `signatures.md`, `troubleshooting.md`, `extensibility.md`.

`troubleshooting.md` wajib memuat tabel gejala→penyebab untuk kegagalan tanda tangan (adaptasi dari tabel "Common mistakes" di dokumentasi SingaPay), plus bagian khusus IP whitelist/serverless.

Rilis: semantic-release + Conventional Commits, matriks CI hijau sebagai prasyarat, auto-publish ke Packagist (webhook) dan npm (provenance/OIDC).

Lisensi MIT. Repo publik dengan disclaimer: *unofficial, tidak berafiliasi dengan PT Abadi Singapay Indonesia.*

---

## 13. Kriteria Sukses

1. Checkout CekProfit berjalan di produksi lewat SDK, bukan kode ad-hoc.
2. Nol bug tanda tangan setelah Fase 1 — divalidasi oleh signature vectors.
3. Waktu integrasi dari `composer require` sampai payment link pertama < 15 menit.
4. Paket dipakai ulang tanpa modifikasi di minimal satu proyek lain (Hayshe OMS / Satulapak).
5. Coverage ≥ 80% di kedua paket.

---

## Lampiran A — Instruksi untuk Claude Code

Sebelum menulis kode:

1. **Fetch ulang seluruh dokumentasi.** Mulai dari `https://docs.singapay.id/llms.txt`, lalu ambil setiap halaman `.md` yang terdaftar, ditambah tiga OpenAPI spec di §2. Jangan mengandalkan ringkasan di PRD ini untuk detail field — PRD ini memetakan *permukaan* API, bukan skema lengkap tiap request body.

2. **Rekonsiliasi sumber.** Buat file kerja `docs/endpoint-inventory.md` yang menyilangkan `llms.txt` × OpenAPI × halaman Overview tiap resource. Tandai setiap perbedaan. Dua sudah diketahui (Card, check-beneficiary) — cari sisanya.

3. **Bangun `JsonNormalizer` dan signature vectors lebih dulu**, sebelum satu pun endpoint. Semuanya bergantung pada ini. Kalau ini salah, semua money-out gagal.

4. **Verifikasi asumsi timezone** di implementasi access token — paksa `Asia/Jakarta`, jangan ikuti contoh Node.js di dokumentasi resmi yang keliru memakai UTC.

5. **Jangan tulis kode retry otomatis untuk endpoint money-out.** Titik.

6. Ikuti struktur, konvensi CI, dan checklist rilis di skill `laravel-package-dev` untuk sisi PHP.
