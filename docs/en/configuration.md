# Configuration

> 🇮🇩 Versi Bahasa Indonesia: [docs/configuration.md](../configuration.md)

All configuration lives in `config/singapay.php` (publish via `php artisan singapay:install`).

## Credentials & environment

| Key | Env | Default | Notes |
|---|---|---|---|
| `environment` | `SINGAPAY_ENV` | `sandbox` | `sandbox` or `production` |
| `client_id` | `SINGAPAY_CLIENT_ID` | — | Merchant client ID |
| `client_secret` | `SINGAPAY_CLIENT_SECRET` | — | Client secret (the HMAC key for every signature) |
| `partner_id` | `SINGAPAY_PARTNER_ID` | — | Merchant API key, sent as the `X-PARTNER-ID` header |
| `hmac_key` | `SINGAPAY_HMAC_KEY` | — | The dashboard's "HMAC Validation Key". When set, webhook verification accepts signatures from this key OR the client secret (the docs name the client secret, the dashboard issues a dedicated key — the SDK accepts both). Outbound signatures always use the client secret |
| `account_id` | `SINGAPAY_ACCOUNT_ID` | — | Default account ULID used when a call names no account |
| `auth_version` | `SINGAPAY_AUTH_VERSION` | `1.1` | `1.1` (HMAC) or `1.0` (Basic, legacy) |

Credentials are validated **when used**, not at boot — the app still boots (e.g. in CI) without them.

## Base URLs

The three SingaPay hosts (payment, biller, identity) each have sandbox and production URLs under `base_urls`. Only override them if SingaPay assigns you different hosts.

## Identity/KYC credentials

The identity-verification service uses its **own credential pair** (`SINGAPAY_IDENTITY_CLIENT_ID`, `SINGAPAY_IDENTITY_CLIENT_SECRET`) and a different signature scheme. Never put the payment credentials here.

## HTTP

| Key | Default | Notes |
|---|---|---|
| `timeout` | `30` | Per-request timeout (seconds) |
| `retry.times` | `2` | Retry attempts — **GET requests only** |
| `retry.sleep` | `200` | Delay between retries (ms) |

Write operations are never retried automatically by the SDK, regardless of configuration.

## Money-out guard

```php
'money_out' => ['enabled' => env('SINGAPAY_MONEY_OUT', false)],
```

The six signed endpoints (disbursement transfer, e-wallet top-up, QRIS payment credit, account transfer, cardless withdrawal create, direct-debit charge) throw `MoneyOutDisabledException` while the guard is off. It defaults to **off** so a misconfigured environment can never move money.

## Webhooks

| Key | Env | Default | Notes |
|---|---|---|---|
| `webhooks.enabled` | `SINGAPAY_WEBHOOKS_ENABLED` | `true` | Register the webhook route |
| `webhooks.path` | `SINGAPAY_WEBHOOK_PATH` | `webhooks/singapay` | Route path |
| `webhooks.verify_signature` | `SINGAPAY_WEBHOOK_VERIFY` | `true` | Verify `X-Signature` (never disable in production) |
| `webhooks.tolerance` | `SINGAPAY_WEBHOOK_TOLERANCE` | `300` | `X-Timestamp` tolerance in seconds (anti-replay) |
| `webhooks.idempotency` | `SINGAPAY_WEBHOOK_IDEMPOTENCY` | `true` | Record processed deliveries in `singapay_webhook_events` |
| `webhooks.middleware` | — | `[]` | Extra middleware, e.g. `['throttle:60,1']` |

The route is registered **without** the `web` group — that group includes CSRF verification, SingaPay sends no CSRF token, and every delivery would bounce with 419 into an endless retry loop.

## Logging

| Key | Env | Default |
|---|---|---|
| `logging.enabled` | `SINGAPAY_LOGGING` | `true` |
| `logging.channel` | `SINGAPAY_LOG_CHANNEL` | default channel |

The SDK logs request **metadata** only (method, path, status, SP code, duration). Request/response bodies are never logged — deliberately and non-configurably, so card data and credentials can never leak into log files through the SDK.
