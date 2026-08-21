# Installation

> 🇮🇩 Versi Bahasa Indonesia: [docs/installation.md](../installation.md)

## Requirements

- PHP 8.3 or newer
- Laravel 13
- SingaPay merchant credentials (client ID, client secret, partner ID/API key, and an account ULID) from the SingaPay dashboard
- A server with a **static public IP** whitelisted in the SingaPay dashboard

## Steps

```bash
composer require aliziodev/laravel-singapay
```

Publish the config and migrations, then migrate (the `singapay_webhook_events` table powers webhook idempotency):

```bash
php artisan singapay:install
php artisan migrate
```

Set the environment variables:

```env
SINGAPAY_ENV=sandbox
SINGAPAY_CLIENT_ID=your-client-id
SINGAPAY_CLIENT_SECRET=your-client-secret
SINGAPAY_PARTNER_ID=your-api-key
SINGAPAY_HMAC_KEY=your-hmac-validation-key
SINGAPAY_ACCOUNT_ID=01J...        # default account ULID
```

Mapping from the **Credential Details** page in the SingaPay dashboard:

| Dashboard field | Env variable | Used for |
|---|---|---|
| Client ID | `SINGAPAY_CLIENT_ID` | Client identity in every auth scheme |
| Client Secret | `SINGAPAY_CLIENT_SECRET` | HMAC key for outbound signatures (token + money-out) |
| API Key | `SINGAPAY_PARTNER_ID` | The `X-PARTNER-ID` header on every request |
| HMAC Validation Key | `SINGAPAY_HMAC_KEY` | Verifying inbound webhook signatures (optional; without it verification uses the Client Secret) |

> ⚠️ The most common mistake: swapping the **API Key** and the **Client Secret**. The API Key is an identity (header), not a signing key.

Hold more than one dashboard credential (a Default one plus a Specific one per sub-account)? Declare the extras as *connections* — see [configuration.md](configuration.md).

## Verify

```bash
php artisan singapay:ping
```

This fetches a token and performs a balance inquiry. Three possible outcomes:

- **Success** — credentials and IP whitelisting are fine; your balance is shown.
- **SP017 (Unauthorized IP)** — the server's IP is not whitelisted. Register the server's public egress IP in the SingaPay dashboard. Remember: serverless platforms (Vercel/Netlify/Workers) have no static IP and cannot call SingaPay directly.
- **401 / Invalid credentials** — re-check client ID, secret, and partner ID; make sure `SINGAPAY_ENV` matches the credentials (sandbox vs production).

## Webhooks

A `POST /webhooks/singapay` route is registered automatically. Point every callback URL in the SingaPay dashboard to it (adjust the domain):

```
https://your-app.com/webhooks/singapay
```

The same URL serves `transaction_notif_url`, `disbursement_notif_url`, and the other callback settings — the package discriminates webhook types by payload, not by URL. See [webhooks.md](webhooks.md).

## Going to production

1. Switch to `SINGAPAY_ENV=production` with production credentials.
2. Whitelist the production server's IP.
3. Set `SINGAPAY_MONEY_OUT=true` **only** if that environment is allowed to move funds.
4. Run `php artisan singapay:ping` once more on the production server.
5. Ask SingaPay directly about settlement schedules and rolling reserve — neither is documented.
