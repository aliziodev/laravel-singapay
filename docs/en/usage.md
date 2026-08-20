# Usage

> 🇮🇩 Versi Bahasa Indonesia: [docs/usage.md](../usage.md)

All examples use the `SingaPay` facade. Alternatively, inject `Aliziodev\Singapay\SingaPay` (the manager) or `Aliziodev\Singapay\Contracts\SingaPayClientInterface` through the constructor.

## The Response object

Every call returns `Aliziodev\Singapay\Http\Response`, which normalizes SingaPay's three envelope shapes (v1 `status/success`, v2 `response_code`, and flat):

```php
$response = SingaPay::balance()->merchant();

$response->successful();                    // bool — the gateway accepted the request
$response->code;                            // ?ResponseCode (SP000-SP020 enum)
$response->data('available_balance.value'); // dot-notation into the data section
$response->collect('items');                // Collection
$response->raw;                             // full decoded body
```

> `SP000` means the **request** succeeded, not that a payment succeeded. For transactions, always check `transaction_status` / webhooks.

## Amounts: always integers

SingaPay signatures hash the request body — whole floats (`100000.0`) serialize differently across runtimes and silently corrupt signatures. The SDK **rejects floats** before signing. Use the `Amount` value object:

```php
use Aliziodev\Singapay\Support\Amount;

Amount::rupiah(150_000);       // from an integer
Amount::from('150000');        // from a whole numeric string
Amount::from('150000.50');     // ❌ InvalidAmountException
```

`Amount` serializes to a bare integer inside JSON bodies.

## Money in

### Payment links

```php
$response = SingaPay::paymentLinks()->create([
    'reff_no' => 'INV-2026-0001',      // max 40 chars, no spaces/slashes
    'title' => 'Invoice #0001',
    'max_usage' => 1,
    'total_amount' => Amount::rupiah(150_000), // must equal the sum of item subtotals
    'items' => [
        ['name' => 'Product A', 'quantity' => 1, 'unit_price' => Amount::rupiah(150_000)],
    ],
    'expired_at' => (string) app(\Aliziodev\Singapay\Support\JakartaClock::class)
        ->toMilliseconds(now()->addDay()),  // 13-digit milliseconds!
]);

$response->data('payment_url');
```

### Virtual accounts

```php
$va = SingaPay::virtualAccounts()->create([
    'bank_code' => 'BRI',
    'kind' => 'temporary',              // temporary requires expired_at + max_usage
    'amount_type' => 'closed',
    'amount' => Amount::rupiah(100_000),
    'expired_at' => '1774000000000',    // 13-digit milliseconds
    'max_usage' => 1,
    'merchant_reff_no' => 'INV-2026-0001',
]);

$va->data('number');   // the VA number customers pay to
$va->data('id');       // the VA ULID — used for find/update/delete
```

### QRIS

```php
$qr = SingaPay::qris()->generate([
    'amount' => Amount::rupiah(50_000),
    'expired_at' => now()->addHour()->toIso8601String(), // QRIS uses ISO 8601!
    'merchant_reff_no' => 'INV-2026-0002',
]);

$qr->data('qr_data'); // EMV string to render as a QR code
```

### E-wallets

```php
$order = SingaPay::ewallet()->createOrder([
    'amount' => Amount::rupiah(75_000),
    'ewallet_vendor' => 'EWALLET_DANA',
    'customer_phone' => '081234567890', // required for OVO (push-to-pay)
    'merchant_redirect_url' => route('checkout.done'),
]);

$order->data('checkout_url');
```

## Money out

> Everything below requires `SINGAPAY_MONEY_OUT=true`. Otherwise the SDK throws `MoneyOutDisabledException` before any traffic leaves.

### The mandatory pattern: transfer → handle ambiguity → inquire

```php
use Aliziodev\Singapay\Exceptions\DuplicateReferenceException;
use Aliziodev\Singapay\Exceptions\InsufficientBalanceException;
use Aliziodev\Singapay\Exceptions\RequestException;

// 1. (Optional but recommended) validate the destination
SingaPay::disbursement()->checkFee([
    'bank_swift_code' => 'BRINIDJA',
    'amount' => Amount::rupiah(500_000),
]);

// 2. Transfer with a unique reference
try {
    $result = SingaPay::disbursement()->transfer([
        'reference_number' => 'PAYOUT-2026-0001',
        'bank_code' => '014',                    // 3-digit or SWIFT
        'bank_account_number' => '1234567890',
        'amount' => Amount::rupiah(500_000),
        'notes' => 'August payout',
    ]);
} catch (InsufficientBalanceException $e) {
    // SP003 — don't retry until the balance is topped up
} catch (DuplicateReferenceException $e) {
    // SP004 — the reference was already used; check its status instead of
    // switching references and blindly retrying
} catch (RequestException $e) {
    if ($e->shouldInquireStatus()) {
        // SP001/SP005 — the outcome is UNKNOWN. Do not retry. Check first:
        $status = SingaPay::disbursement()->inquireStatus('PAYOUT-2026-0001');
    }

    throw $e;
}

// 3. Final certainty arrives via the DisbursementProcessed webhook
```

### E-wallet top-ups & QRIS issuer

```php
SingaPay::ewalletMoneyOut()->inquireAccount([
    'ewallet_code' => 'DANA',
    'customer_number' => '085733347341',
    'amount' => ['value' => '10000.00', 'currency' => 'IDR'], // note: this scheme uses decimal strings
]);

SingaPay::ewalletMoneyOut()->triggerTopup([...]);
SingaPay::qrisMoneyOut()->inquireMerchant($qrData);
SingaPay::qrisMoneyOut()->triggerPaymentCredit([...]);
```

## Biller (PPOB)

```php
SingaPay::biller()->checkBalance();
$inquiry = SingaPay::biller()->prepaidInquiry('plntok', [
    'customer_id' => '01428800700',
    'product_code' => 'SPPLN20',
]);
SingaPay::biller()->prepaidPayment('plntok', [
    'customer_id' => '01428800700',
    'product_code' => 'SPPLN20',
    'password' => config('services.singapay_biller.password'),
    'reference_number' => $inquiry->data('reference_number'),
]);
```

## Identity verification (KYC)

```php
$check = SingaPay::identity()->verifyBankAccount([
    'request_id' => (string) Str::uuid(),
    'account_number' => '1234567890',
    'bank_code' => '014',
    'name' => 'Budi Santoso',
]);

$check->data('similarity'); // 0-100
$check->data('suggestion'); // pass | reject
```

## Exception handling

Every SDK exception derives from `Aliziodev\Singapay\Exceptions\SingaPayException`:

| Exception | When |
|---|---|
| `ConfigurationException` | Missing/invalid config or credentials |
| `ConnectionException` | SingaPay is unreachable |
| `RequestException` | The gateway reported a failure (has `->response`, `->responseCode()`, `->shouldInquireStatus()`) |
| ↳ `InsufficientBalanceException` | SP003 |
| ↳ `DuplicateReferenceException` | SP004 |
| ↳ `AuthenticationException` | SP013 after one token refresh |
| ↳ `InvalidSignatureException` | SP016 — a caller-side bug; see [troubleshooting](troubleshooting.md) |
| ↳ `IpNotWhitelistedException` | SP017 |
| ↳ `ValidationException` | SP018 (has `->errors()`) |
| `MoneyOutDisabledException` | Money-out attempted while the guard is off |
| `WebhookVerificationException` | An inbound webhook failed verification |

## Testing with SingaPay::fake()

```php
use Aliziodev\Singapay\Facades\SingaPay;
use Aliziodev\Singapay\Http\ApiRequest;

$fake = SingaPay::fake([
    // path pattern (with * wildcards) => data | Response | Closure
    '*payment-link-manage*' => ['payment_url' => 'https://pay.test/abc'],
]);

// ... run your application code ...

$fake->assertSent('*payment-link-manage*');
$fake->assertSent(fn (ApiRequest $r) => $r->body['reff_no'] === 'INV-001');
$fake->assertPaymentLinkCreated();
$fake->assertDisbursementRequested();
$fake->assertNothingSent();
$fake->assertSentCount(2);
```

For testing webhook listeners, see [webhooks.md](webhooks.md#testing-your-listeners).
