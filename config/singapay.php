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
    | never reuse the payment credentials here.
    */
    'identity' => [
        'client_id' => env('SINGAPAY_IDENTITY_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_IDENTITY_CLIENT_SECRET'),
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
        'middleware' => [],
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
