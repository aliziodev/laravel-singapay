# Installation

> 🇮🇩 Versi Bahasa Indonesia: [docs/installation.md](../installation.md)

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12, or 13
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
SINGAPAY_ACCOUNT_ID=01J...        # default account ULID
```

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
