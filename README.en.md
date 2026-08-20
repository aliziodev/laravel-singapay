# Laravel SingaPay

[![Tests](https://github.com/aliziodev/laravel-singapay/actions/workflows/tests.yml/badge.svg)](https://github.com/aliziodev/laravel-singapay/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/aliziodev/laravel-singapay.svg)](https://packagist.org/packages/aliziodev/laravel-singapay)
[![License](https://img.shields.io/packagist/l/aliziodev/laravel-singapay.svg)](LICENSE)

**Unofficial** Laravel SDK for the [SingaPay](https://singapay.id) payment gateway (PT Abadi Singapay Indonesia, a Bank Indonesia licensed PJP1). Not affiliated with PT Abadi Singapay Indonesia.

🇮🇩 *Dokumentasi utama dalam Bahasa Indonesia ada di [README.md](README.md) dan [docs/](docs/).*

SingaPay ships no official SDK — every integrator has to reimplement two different HMAC signature schemes, byte-exact JSON canonicalization, token management, and webhook verification. This package does all of it, with a test suite that pins every signature byte.

## Features

- **Money in** — Payment Links, Virtual Accounts, QRIS, E-Wallets (DANA/OVO/GoPay/ShopeePay), Cards, Subscriptions, Direct Debit.
- **Money out** — Disbursements, E-Wallet Top-Ups, QRIS Issuer, Account Transfers, Cardless Withdrawals — behind an explicit guard that defaults to **off**.
- **Webhooks** — constant-time signature verification, replay protection, built-in idempotency, and 13 ready-to-listen Laravel events.
- **Auth** — v1.1 (HMAC) / v1.0 (Basic) tokens with cached, lock-protected refresh, plus the separate Biller and Identity/KYC schemes.
- **Testing** — `SingaPay::fake()` with assertions, plus helpers to test your webhook listeners without manual mocks.
- **Secure by design** — amounts must be integers (floats are rejected before signing), request bodies never reach log files, automatic retries apply to GET only.

## ⚠️ Read before production

1. **IP whitelisting is mandatory.** SingaPay rejects calls from unregistered IPs (SP017). Serverless platforms (Vercel, Netlify, Cloudflare Workers) use dynamic egress IPs and **cannot** be whitelisted — call SingaPay from a static-IP server. Run `php artisan singapay:ping` to verify.
2. **Money-out is disabled by default.** Every money-moving operation throws `MoneyOutDisabledException` until you set `SINGAPAY_MONEY_OUT=true`. Enable it only in environments allowed to move funds.
3. **Never blindly retry money-out.** After `SP001`/`SP005`/a timeout, call `inquireStatus()` with the same reference before doing anything — a blind retry can duplicate a real transfer.
4. **The Card endpoint is PCI-DSS scope.** Any server touching raw card data falls under PCI-DSS. Use Payment Links unless you fully understand the implications.
5. **Settlement schedule and rolling reserve are undocumented** by SingaPay — ask them directly before going live.

## Installation

```bash
composer require aliziodev/laravel-singapay
php artisan singapay:install
php artisan migrate
```

Fill in `.env`:

```env
SINGAPAY_ENV=sandbox
SINGAPAY_CLIENT_ID=...
SINGAPAY_CLIENT_SECRET=...
SINGAPAY_PARTNER_ID=...
SINGAPAY_ACCOUNT_ID=...   # default account ULID
```

Verify connectivity:

```bash
php artisan singapay:ping
```

## Quick start

### Create a payment link

```php
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Support\Amount;

$response = SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0001',
    'title' => 'Invoice #0001',
    'max_usage' => 1,
    'total_amount' => Amount::rupiah(150_000),
    'items' => [
        ['name' => 'Product A', 'quantity' => 1, 'unit_price' => Amount::rupiah(150_000)],
    ],
]);

$paymentUrl = $response->data('payment_url');
```

### Receive webhooks

A `POST /webhooks/singapay` route is registered automatically (no CSRF, signature-verified). Just register listeners:

```php
use Aliziodev\Singapay\Events\VirtualAccountPaid;

Event::listen(function (VirtualAccountPaid $event) {
    Order::where('reff_no', $event->reffNo())->first()?->markAsPaid();
});
```

### Disbursement (money out)

```php
use Aliziodev\Singapay\Exceptions\RequestException;

try {
    $response = SingaPay::disbursement()->transfer([
        'reference_number' => 'PAYOUT-2026-0001',
        'bank_code' => '014',
        'bank_account_number' => '1234567890',
        'amount' => Amount::rupiah(500_000),
    ]);
} catch (RequestException $e) {
    if ($e->shouldInquireStatus()) {
        // Outcome unknown — do NOT retry. Check the status first:
        $status = SingaPay::disbursement()->inquireStatus('PAYOUT-2026-0001');
    }

    throw $e;
}
```

### Testing your integration

```php
use Aliziodev\Singapay\Facades\SingaPay;

$fake = SingaPay::fake([
    '*payment-link-manage*' => ['payment_url' => 'https://pay.test/abc'],
]);

$this->post('/checkout', [...])->assertOk();

$fake->assertPaymentLinkCreated(fn (array $body) => $body['reff_no'] === 'INV-001');
```

## Endpoint groups

| Accessor | Coverage |
|---|---|
| `SingaPay::paymentLinks()` | Payment link CRUD + payment methods |
| `SingaPay::paymentLinkHistories()` | Per-link payment histories |
| `SingaPay::virtualAccounts()` / `vaTransactions()` | VA provisioning + transactions |
| `SingaPay::qris()` / `qrisMoneyOut()` | Dynamic QRIS (in) + issuer (out) |
| `SingaPay::ewallet()` / `ewalletMoneyOut()` | E-wallet checkout (in) + top-up (out) |
| `SingaPay::card()` | Card payments (⚠️ PCI-DSS) |
| `SingaPay::subscriptions()` | Recurring plans |
| `SingaPay::directDebit()` | Card binding + repeat charges |
| `SingaPay::disbursement()` | Outbound bank transfers |
| `SingaPay::accountTransfer()` | Transfers between sub-accounts |
| `SingaPay::cardlessWithdrawal()` | Cardless ATM withdrawals |
| `SingaPay::accounts()` / `balance()` / `statements()` | Sub-accounts, balances, statements |
| `SingaPay::biller()` | PPOB: PLN, mobile credit, games, etc. (separate host) |
| `SingaPay::identity()` | Bank/e-wallet holder-name verification (separate host) |

## Documentation

| English | Bahasa Indonesia |
|---|---|
| [Installation](docs/en/installation.md) | [Instalasi](docs/installation.md) |
| [Configuration](docs/en/configuration.md) | [Konfigurasi](docs/configuration.md) |
| [Usage](docs/en/usage.md) | [Penggunaan](docs/usage.md) |
| [Webhooks](docs/en/webhooks.md) | [Webhook](docs/webhooks.md) |
| [Signatures](docs/en/signatures.md) | [Tanda Tangan](docs/signatures.md) |
| [Troubleshooting](docs/en/troubleshooting.md) | [Troubleshooting](docs/troubleshooting.md) |
| [Extensibility](docs/en/extensibility.md) | [Ekstensibilitas](docs/extensibility.md) |

Technical reference: [Endpoint Inventory](docs/endpoint-inventory.md).

## Artisan commands

| Command | Purpose |
|---|---|
| `singapay:install` | Publish config + migrations |
| `singapay:ping` | Check connectivity, credentials, and IP whitelisting |
| `singapay:token` | Fetch & display an access token (debug) |
| `singapay:verify-signature` | Compute a signature from a JSON file (debug SP016) |

## Requirements

- PHP 8.3+
- Laravel 13

> This package deliberately targets only the latest Laravel version. For payment applications, a framework that still receives security fixes is a prerequisite — Laravel 11 is past its security-fix window, and newer Composer versions even block installing it by default.

## License

[MIT](LICENSE). Unofficial package — not affiliated with PT Abadi Singapay Indonesia.
