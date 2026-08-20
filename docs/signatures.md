# Tanda Tangan (Signatures)

> 🇬🇧 English version: [docs/en/signatures.md](en/signatures.md)

SingaPay memakai **empat skema kriptografi berbeda** dalam satu produk. SDK memisahkannya dengan tegas — Anda tidak perlu mengimplementasikan apa pun, tapi dokumen ini menjelaskan apa yang terjadi di balik layar (berguna saat debugging SP016).

## Skema A — Access Token v1.1

```
payload     = "{client_id}_{client_secret}_{YYYYMMDD}"
X-Signature = HMAC-SHA512(payload, client_secret)   → hex lowercase
```

- `YYYYMMDD` adalah tanggal kalender **Asia/Jakarta**, bukan UTC. Contoh Node.js di dokumentasi resmi SingaPay salah (memakai tanggal UTC) — antara 00:00–07:00 WIB tanda tangannya tertolak. SDK memaksa timezone lewat `JakartaClock`.
- Diimplementasikan di `Auth\AccessTokenSigner`.

## Skema B — Access Token v1.0 (legacy)

`Authorization: Basic base64(client_id:client_secret)` — tanpa tanda tangan. Dipakai bila `auth_version = 1.0`, dan selalu dipakai untuk token host Biller.

## Skema C — Request Signature (money-out & webhook)

```
1. Normalisasi body  → sort key rekursif (byte order), encode tanpa escape unicode/slash
2. hashed_body       = SHA-256(normalized_json)                     → hex
3. string_to_sign    = "{METHOD}:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
4. X-Signature       = HMAC-SHA512(string_to_sign, client_secret)   → hex lowercase
```

- `METHOD` uppercase; `ENDPOINT` = path lengkap **termasuk query string dan prefix `/api`**, tanpa domain; `TIMESTAMP` = Unix **detik** (header `X-Timestamp`).
- Diimplementasikan di `Auth\RequestSigner` + `Support\JsonNormalizer`.
- SDK mengirim **byte hasil normalisasi itu sendiri** sebagai body request, sehingga body di kabel dan hash yang ditandatangani mustahil berbeda.
- Untuk webhook masuk, formula yang sama dipakai terbalik: token diambil dari header `Authorization` yang dikirim SingaPay, `ENDPOINT` adalah path callback Anda sendiri, dan perbandingan memakai `hash_equals` (constant-time).

## Skema D — Identity/KYC

```
timestamp = RFC 3339 UTC presisi detik (mis. 2026-05-26T07:30:00Z)
signature = HMAC-SHA256("{client_id}:{timestamp}", client_secret)   → hex
```

⚠️ SHA-**256** (bukan 512), separator **titik dua** (bukan underscore), timestamp **UTC** (bukan tanggal Jakarta), dan **kredensial terpisah**. Diisolasi di `Auth\IdentitySigner` + `Auth\IdentityTokenManager` agar tidak pernah tertukar dengan skema payment.

## Aturan normalisasi JSON (komponen paling kritis)

1. Key object di-sort **rekursif** dengan perbandingan byte (`ksort(..., SORT_STRING)`).
2. Array (list) **tidak** di-sort — urutan elemen dipertahankan, isi tiap elemen tetap dinormalisasi.
3. Encode dengan `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, tanpa spasi.
4. **Float ditolak.** `json_encode(100000.0)` menghasilkan `100000.0` di PHP tapi `100000` di JS — beda satu byte = tanda tangan hancur. Kirim nominal sebagai integer (`Amount`).
5. Array asosiatif kosong PHP (`[]`) ter-encode `[]`; bila gateway mengharapkan objek kosong `{}`, kirim `new stdClass` / `(object) []`.

Perilaku ini dikunci oleh 18 *signature vector* di `tests/Fixtures/signature-vectors.json` — setiap vector menyimpan byte kanonis, hash SHA-256, dan tanda tangan HMAC yang diharapkan untuk secret uji tetap. SDK lain (mis. port TypeScript) harus menghasilkan byte yang identik terhadap fixture yang sama.

## Debugging tanda tangan

```bash
php artisan singapay:verify-signature body.json \
  --endpoint="/api/v2.0/disbursement/transfer" \
  --token="<access-token>" \
  --timestamp=1755657600
```

Output menampilkan setiap nilai antara (JSON ternormalisasi, hash body, string-to-sign, tanda tangan akhir) sehingga bisa dibandingkan langkah demi langkah. Lihat juga tabel gejala di [troubleshooting.md](troubleshooting.md).
