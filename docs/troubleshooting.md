# Troubleshooting

> 🇬🇧 English version: [docs/en/troubleshooting.md](en/troubleshooting.md)

## SP016 — Signature Invalid

Kegagalan tanda tangan selalu bug/misconfig di sisi pemanggil. Tabel gejala → penyebab (diadaptasi dari "Common mistakes" dokumentasi SingaPay):

| Gejala | Penyebab umum | Solusi |
|---|---|---|
| SP016 hanya antara 00:00–07:00 WIB | Tanggal token dihitung dari UTC, bukan Asia/Jakarta | SDK sudah benar; pastikan tidak ada kode lain yang membuat token sendiri |
| SP016 konsisten di satu endpoint | Query string tidak ikut ditandatangani, atau path tanpa prefix `/api` | Cek `--endpoint` di `singapay:verify-signature` — harus persis path + query |
| SP016 hanya pada body tertentu | Ada float di body, atau key tidak ter-sort | Pakai `Amount`; float ditolak SDK dengan pesan lokasi field |
| SP016 di semua request | `client_secret` salah, atau memakai API key sebagai kunci HMAC | Kunci HMAC = client secret, bukan `X-PARTNER-ID` |
| Tanda tangan beda dengan contoh docs | Separator `_` vs `:` tertukar antar skema | Skema token pakai `_`, skema request pakai `:` |
| Webhook selalu 401 | Body sudah diparse ulang framework sebelum di-hash | SDK meng-hash dari raw body; pastikan tidak ada middleware yang memodifikasi body |
| SP016 setelah jam server melenceng | `X-Timestamp` di luar toleransi gateway | Sinkronkan NTP server |

Alat bantu: `php artisan singapay:verify-signature` menampilkan seluruh nilai antara.

## SP017 — Unauthorized IP / serverless

SingaPay hanya menerima request dari IP yang terdaftar di dashboard merchant.

- Cari IP egress publik server Anda (`curl https://api.ipify.org`) dan daftarkan.
- **Vercel, Netlify, Cloudflare Workers, dan platform serverless lain memakai IP egress dinamis — tidak bisa di-whitelist.** Pilihan Anda: (a) deploy backend di VPS/host ber-IP statis, (b) rutekan panggilan SingaPay lewat proxy ber-IP statis, (c) fitur static-egress berbayar dari platform Anda.
- `php artisan singapay:ping` mendeteksi kondisi ini dan mencetak diagnosis di atas.
- Penolakan IP paling sering muncul **bukan** sebagai SP017. Server yang belum di-whitelist ditolak sudah di tahap tukar token, dan endpoint itu menjawab HTTP 403 polos tanpa kode SP sama sekali:

  ```json
  {"status":403,"success":false,"error":{"code":403,"message":"Your IP address (1.2.3.4) is not registered"}}
  ```

  SDK mengenali kedua bentuk itu dan tetap melempar `IpNotWhitelistedException` — pesannya memuat IP yang ditolak, jadi itulah alamat yang perlu Anda daftarkan.

## Webhook

| Gejala | Penyebab | Solusi |
|---|---|---|
| Semua delivery 419 | Route webhook dibuat manual di `routes/web.php` (kena CSRF) | Pakai route bawaan paket; jangan tempel middleware `web` |
| Semua delivery 401 | Secret berbeda antara app dan dashboard, atau URL callback (path/query) tidak persis sama dengan yang didaftarkan | Samakan secret & URL; ingat query string ikut ditandatangani |
| **Hanya** delivery money-out yang 401, money-in normal | Notifikasi money-out ditandatangani kredensial **Default**, sementara app memakai secret kredensial Specific | Deklarasikan kredensial Default sebagai koneksi (`connections`) — secret tiap koneksi otomatis diterima saat verifikasi |
| Delivery dobel diproses dua kali | `webhooks.idempotency` dimatikan, atau migration belum dijalankan | `php artisan migrate` dan biarkan idempotency menyala |
| Event tidak terpancar | Listener terdaftar untuk kelas yang salah | Dengarkan kelas event spesifik atau `WebhookReceived`; cek `event_type` di tabel `singapay_webhook_events` |
| SingaPay terus me-retry | Listener melempar exception → app menjawab 5xx | Lihat log; pindahkan kerja berat ke queued job |

## Token & autentikasi

| Gejala | Penyebab | Solusi |
|---|---|---|
| 401 berulang meski kredensial benar | `SINGAPAY_ENV` tidak cocok dengan kredensial (sandbox vs production) | Samakan environment dan kredensialnya |
| `AuthenticationException` setelah tepat satu retry | Token valid tapi ditolak (SP013) dua kali — biasanya credential dicabut | Cek status kredensial di dashboard |
| Token diminta berkali-kali per detik | Cache store tidak persisten antar proses (mis. `array` di CLI) | Pakai store cache bersama (redis/file/database) |

## Lain-lain

- **`MoneyOutDisabledException`** — memang disengaja; setel `SINGAPAY_MONEY_OUT=true` hanya di environment yang boleh mentransfer dana.
- **`ConfigurationException: Missing ... [account_id]`** — pemanggilan tanpa akun eksplisit membutuhkan `SINGAPAY_ACCOUNT_ID`.
- **Timeout pada operasi money-out** — hasil TIDAK diketahui. Jangan retry; panggil `inquireStatus()` dengan reference yang sama.
- **Jadwal settlement / rolling reserve** — tidak terdokumentasi oleh SingaPay; tanyakan langsung sebelum produksi.
- **Cardless withdrawal `create()` menjawab HTTP 500** — `vendor_code` wajib tapi tidak divalidasi terhadap daftar, jadi kode yang tidak dikenal meledak 500 alih-alih 422, dan itu tidak bisa dibedakan dari produk yang belum aktif. Minta daftar vendor code ke support SingaPay; spec mereka sendiri menyuruh begitu. Sisi bacanya (`list`, `find`, `cancel`) sehat.
