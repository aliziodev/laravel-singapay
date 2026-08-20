# Troubleshooting

> 🇮🇩 Versi Bahasa Indonesia: [docs/troubleshooting.md](../troubleshooting.md)

## SP016 — Signature Invalid

A signature failure is always a caller-side bug/misconfiguration. Symptom → cause table (adapted from SingaPay's "Common mistakes"):

| Symptom | Common cause | Fix |
|---|---|---|
| SP016 only between 00:00–07:00 WIB | Token date computed from UTC instead of Asia/Jakarta | The SDK is correct; make sure no other code builds its own tokens |
| SP016 consistently on one endpoint | Query string not included in the signed endpoint, or the `/api` prefix missing | Check `--endpoint` in `singapay:verify-signature` — it must be the exact path + query |
| SP016 only for certain bodies | A float in the body, or unsorted keys | Use `Amount`; the SDK rejects floats with the exact field path |
| SP016 on every request | Wrong `client_secret`, or the API key used as the HMAC key | The HMAC key is the client secret, not `X-PARTNER-ID` |
| Signature differs from the docs example | `_` vs `:` separator mixed up between schemes | The token scheme uses `_`, the request scheme uses `:` |
| Webhooks always 401 | The framework re-parsed the body before hashing | The SDK hashes from the raw body; ensure no middleware mutates it |
| SP016 after server clock drift | `X-Timestamp` outside the gateway's tolerance | Sync NTP |

Tooling: `php artisan singapay:verify-signature` prints every intermediate value.

## SP017 — Unauthorized IP / serverless

SingaPay only accepts requests from IPs registered in the merchant dashboard.

- Find your server's public egress IP (`curl https://api.ipify.org`) and register it.
- **Vercel, Netlify, Cloudflare Workers, and other serverless platforms use dynamic egress IPs — they cannot be whitelisted.** Your options: (a) deploy the backend on a static-IP VPS/host, (b) route SingaPay calls through a static-IP proxy, (c) your platform's paid static-egress feature.
- `php artisan singapay:ping` detects this condition and prints the diagnosis above.
- An IP rejection usually does **not** arrive as SP017. A non-whitelisted server is turned away at the token exchange, and that endpoint answers a bare HTTP 403 with no SP code at all:

  ```json
  {"status":403,"success":false,"error":{"code":403,"message":"Your IP address (1.2.3.4) is not registered"}}
  ```

  The SDK recognises both shapes and still raises `IpNotWhitelistedException` — its message quotes the rejected IP, which is exactly the address you need to register.

## Webhooks

| Symptom | Cause | Fix |
|---|---|---|
| Every delivery 419 | A hand-made webhook route in `routes/web.php` (CSRF applies) | Use the package route; never attach the `web` group |
| Every delivery 401 | Secret mismatch between app and dashboard, or the callback URL (path/query) differs from what was registered | Align secret & URL; remember the query string is part of the signature |
| Duplicates processed twice | `webhooks.idempotency` disabled, or the migration was never run | `php artisan migrate` and keep idempotency on |
| Events never fire | Listener registered for the wrong class | Listen to the specific event class or `WebhookReceived`; inspect `event_type` in `singapay_webhook_events` |
| SingaPay keeps retrying | A listener throws → your app answers 5xx | Check the logs; move heavy work into queued jobs |

## Tokens & authentication

| Symptom | Cause | Fix |
|---|---|---|
| Repeated 401 despite correct credentials | `SINGAPAY_ENV` doesn't match the credentials (sandbox vs production) | Align environment and credentials |
| `AuthenticationException` after exactly one retry | A valid-looking token rejected (SP013) twice — usually revoked credentials | Check the credential status in the dashboard |
| Token requested many times per second | The cache store isn't shared across processes (e.g. `array` in CLI) | Use a shared cache store (redis/file/database) |

## Everything else

- **`MoneyOutDisabledException`** — intentional; set `SINGAPAY_MONEY_OUT=true` only in environments allowed to move funds.
- **`ConfigurationException: Missing ... [account_id]`** — calls without an explicit account need `SINGAPAY_ACCOUNT_ID`.
- **A timeout on a money-out operation** — the outcome is UNKNOWN. Do not retry; call `inquireStatus()` with the same reference.
- **Settlement schedule / rolling reserve** — undocumented by SingaPay; ask them directly before production.
