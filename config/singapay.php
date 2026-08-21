<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    | "sandbox" or "production". Selects which base URLs are used and which
    | credentials SingaPay expects. Keep "sandbox" until your integration is
    | fully tested — production moves real money.
    */
    'environment' => env('SINGAPAY_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    | From the SingaPay merchant dashboard. The partner ID is the merchant
    | API key sent as the X-PARTNER-ID header. The account ID is the default
    | sub-account ULID used when an SDK call does not name one explicitly.
    */
    'client_id' => env('SINGAPAY_CLIENT_ID'),
    'client_secret' => env('SINGAPAY_CLIENT_SECRET'),
    'partner_id' => env('SINGAPAY_PARTNER_ID'),
    'account_id' => env('SINGAPAY_ACCOUNT_ID'),

    /*
    |--------------------------------------------------------------------------
    | HMAC Validation Key
    |--------------------------------------------------------------------------
    | The "HMAC Validation Key" from the dashboard's Credential Details.
    | When set, inbound webhook signatures are accepted from this key OR the
    | client secret (each compared in constant time) — the official docs say
    | webhooks are signed with the client secret, but the dashboard issues
    | this dedicated key, so the SDK accepts both. Outbound signatures always
    | use the client secret, as documented.
    */
    'hmac_key' => env('SINGAPAY_HMAC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Connections (extra credential sets)
    |--------------------------------------------------------------------------
    | A merchant can hold several dashboard credentials: a merchant-wide
    | Default one, plus Specific ones bound to particular sub-accounts.
    | SP403 refuses the Default credential for an account that has its own,
    | so an app serving several accounts needs several credential sets.
    |
    | The keys above ARE the connection named by "default", so most
    | applications never touch this. Add an entry only for an ADDITIONAL
    | credential set, then reach it by name:
    |
    |     SingaPay::connection('payouts')->disbursement()->transfer([...]);
    |
    | Only credential keys may be set here (client_id, client_secret,
    | partner_id, account_id, hmac_key, auth_version, identity, biller); a
    | key nested here that is really application policy — money_out above
    | all — is rejected rather than silently ignored. Every connection's
    | secret is accepted when verifying inbound webhooks, because one
    | callback URL can receive deliveries signed by more than one credential.
    */
    'default' => env('SINGAPAY_CONNECTION', 'main'),

    'connections' => [
        // 'payouts' => [
        //     'client_id' => env('SINGAPAY_PAYOUTS_CLIENT_ID'),
        //     'client_secret' => env('SINGAPAY_PAYOUTS_CLIENT_SECRET'),
        //     'partner_id' => env('SINGAPAY_PAYOUTS_PARTNER_ID'),
        //     'account_id' => env('SINGAPAY_PAYOUTS_ACCOUNT_ID'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access token scheme
    |--------------------------------------------------------------------------
    | "1.1" (default): HMAC-SHA512 signed token request.
    | "1.0" (legacy): HTTP Basic authentication.
    */
    'auth_version' => env('SINGAPAY_AUTH_VERSION', '1.1'),

    /*
    |--------------------------------------------------------------------------
    | Base URLs
    |--------------------------------------------------------------------------
    | SingaPay is split across three hosts: the payment B2B API, the biller
    | (PPOB) API, and the identity-verification (KYC) API. Override only if
    | SingaPay assigns you different hosts.
    */
    'base_urls' => [
        'payment' => [
            'sandbox' => env('SINGAPAY_PAYMENT_SANDBOX_URL', 'https://sandbox-payment-b2b.singapay.id'),
            'production' => env('SINGAPAY_PAYMENT_PRODUCTION_URL', 'https://payment-b2b.singapay.id'),
        ],
        'biller' => [
            'sandbox' => env('SINGAPAY_BILLER_SANDBOX_URL', 'https://sandbox-biller-b2b.singapay.id'),
            'production' => env('SINGAPAY_BILLER_PRODUCTION_URL', 'https://biller-b2b.singapay.id'),
        ],
        'identity' => [
            'sandbox' => env('SINGAPAY_IDENTITY_SANDBOX_URL', 'https://sandbox-apigw.singapay.id'),
            'production' => env('SINGAPAY_IDENTITY_PRODUCTION_URL', 'https://api.singapay.id'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity verification (KYC) credentials
    |--------------------------------------------------------------------------
    | The KYC service uses its OWN credential pair and signature scheme —
    | never reuse the payment credentials here; they are rejected with
    | "401 invalid credential or signature" (verified against sandbox).
    | These are issued from the separate merchant KYC dashboard, and the
    | client id looks like `kc_live_a3f2c4` rather than a UUID.
    */
    'identity' => [
        'client_id' => env('SINGAPAY_IDENTITY_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_IDENTITY_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Biller (PPOB) credentials
    |--------------------------------------------------------------------------
    | The biller host is a separate product and may be issued its own
    | credential set — a payment-host partner id is rejected there with
    | "403 Invalid X-PARTNER-ID". Leave these null to reuse the payment
    | credentials above, which is correct for merchants whose biller access
    | rides on the same keys.
    */
    'biller' => [
        'client_id' => env('SINGAPAY_BILLER_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_BILLER_CLIENT_SECRET'),
        'partner_id' => env('SINGAPAY_BILLER_PARTNER_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    | "retry" applies to GET requests only — write operations, and above all
    | money-out operations, are never retried automatically by the SDK.
    | "times" is the number of retry attempts, "sleep" the delay in ms.
    */
    'timeout' => (int) env('SINGAPAY_TIMEOUT', 30),
    'retry' => [
        'times' => 2,
        'sleep' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Money-out guard
    |--------------------------------------------------------------------------
    | Disbursements, e-wallet top-ups, QRIS payment credits, account
    | transfers, cardless withdrawals, and direct-debit charges all throw
    | MoneyOutDisabledException unless this is true. Default is FALSE so a
    | misconfigured environment can never move real money by accident.
    */
    'money_out' => [
        'enabled' => (bool) env('SINGAPAY_MONEY_OUT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    | The package registers POST /{path} WITHOUT the "web" middleware group
    | (no session, no CSRF — SingaPay cannot send a CSRF token). Signature
    | verification compares HMAC-SHA512 signatures in constant time and
    | rejects timestamps outside "tolerance" seconds. "idempotency" stores
    | processed deliveries in the singapay_webhook_events table so retried
    | deliveries are acknowledged without being re-dispatched. Add extra
    | route middleware (e.g. "throttle:60,1") via "middleware".
    */
    'webhooks' => [
        'enabled' => (bool) env('SINGAPAY_WEBHOOKS_ENABLED', true),
        'path' => env('SINGAPAY_WEBHOOK_PATH', 'webhooks/singapay'),
        'verify_signature' => (bool) env('SINGAPAY_WEBHOOK_VERIFY', true),
        'tolerance' => (int) env('SINGAPAY_WEBHOOK_TOLERANCE', 300),
        'idempotency' => (bool) env('SINGAPAY_WEBHOOK_IDEMPOTENCY', true),
        // Additional keys accepted when verifying inbound signatures, on top
        // of "hmac_key" and "client_secret". One callback URL can legitimately
        // receive deliveries from more than one dashboard credential — each
        // signs with its own client secret — and money-out notifications are
        // signed by the merchant Default credential even when the transfer
        // was made with a Specific one. Comma-separated, or an array here.
        'secrets' => env('SINGAPAY_WEBHOOK_SECRETS'),
        'middleware' => [],
        // Retention for the WebhookEvent pruner (`php artisan model:prune`);
        // rows are only needed for SingaPay's retry window (minutes).
        'prune_after_days' => (int) env('SINGAPAY_WEBHOOK_PRUNE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | When enabled, the SDK logs request METADATA only (method, path, status,
    | SP code, duration). Request and response bodies are never logged — this
    | is deliberate and not configurable, so card data and credentials can
    | never leak into log files through the SDK. "channel" null = default.
    */
    'logging' => [
        'enabled' => (bool) env('SINGAPAY_LOGGING', true),
        'channel' => env('SINGAPAY_LOG_CHANNEL'),
    ],

];
