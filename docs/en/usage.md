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

## Amounts in responses: never compare with `===`

Amounts you **send** are always integers (see above). Amounts you **receive** are not consistently typed — the gateway mixes integers and decimal strings in the same field depending on the value:

```json
{"debit":  {"value": 2500,      "currency": "IDR"},
 "credit": {"value": "0.00",    "currency": "IDR"},
 "balance_after": {"value": 1203500, "currency": "IDR"}}
```

One statement row returns `2500` (integer) for a populated amount and `"0.00"` (string) for zero. The balance endpoint then returns `"1203500.00"` (string) for the very value the statement reported as the integer `1203500`.

So never write `$row['credit']['value'] === 0` or `=== "0.00"` — either check is wrong half the time. Normalise first:

```php
$credit = Amount::from($response->data('credit.value'))->value; // always int rupiah
```

`Amount::from()` accepts integers and decimal strings and rejects floats, which makes it a safe single gate for every incoming amount.

## The unified charge API

`SingaPay::pay($method, $data)` (alias `SingaPay::charges()->create(...)`) creates a money-in charge on any method from **one input shape** — the builder absorbs the per-method quirks: millisecond vs ISO 8601 expirations, `reff_no` vs `merchant_reff_no`, payment-link `items` synthesis, and the `EWALLET_` vendor prefix.

```php
use Aliziodev\Singapay\Facades\SingaPay;

$charge = SingaPay::pay('va', [
    'amount' => 150_000,                 // required — int/Amount/whole string (floats rejected)
    'reference' => 'INV-2026-0001',      // merchant reference (required for payment_link)
    'expires_at' => now()->addDay(),     // DateTime, date string, or 13-digit ms string
    'customer' => ['name' => 'Budi', 'email' => 'budi@x.id', 'phone' => '0812...'],
    'bank_code' => 'BRI',                // required for va
]);

$charge->successful();     // the gateway accepted the request
$charge->vaNumber();       // the payment artifact per method:
$charge->qrString();       //   qris → EMV string
$charge->checkoutUrl();    //   payment_link / ewallet → URL
$charge->data('...');      // full response access
```

**Methods & aliases** (case-insensitive): `payment_link`/`pl`/`link`, `virtual_account`/`va`, `qris`/`qr`, `ewallet`/`e-wallet`/`wallet` — or the `PaymentMethod` enum.

**Per-method fields:**

| Field | payment_link | va | qris | ewallet |
|---|---|---|---|---|
| `amount` | ✅ required | ✅ required | ✅ required | ✅ required |
| `reference` | ✅ required (`reff_no`) | optional | optional | optional |
| `expires_at` | → 13-digit ms | → 13-digit ms (+`kind: temporary`) | → ISO 8601 | → ISO 8601 |
| `bank_code` | — | ✅ required | — | — |
| `vendor` | — | — | — | ✅ required (e.g. `DANA`) |
| `customer` | — | `name` → VA name | — | `customer_*` |
| `title`, `items`, `max_usage` | ✅ (items synthesized when absent) | `max_usage` | — | — |
| `redirect_url` | ✅ | — | — | → `merchant_redirect_url` |
| `options` | escape hatch: raw fields merged onto the payload last | ⬅ same | ⬅ same | ⬅ same |

Unmappable input (unknown method, missing required field, float `amount`) throws `ChargeException` **before** any traffic leaves. A VA without `expires_at` is created `permanent`; with one it becomes `temporary` + `max_usage` (default 1).

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

#### Restricting payment methods (and how to accept Alfamart/Indomaret)

`whitelisted_payment_method` limits which methods appear on the payment page. It is also the **only** route to retail-outlet payments — SingaPay exposes no dedicated retail endpoint (probed: every candidate path answers 404).

```php
SingaPay::paymentLinks()->create([
    // ... other fields ...
    'whitelisted_payment_method' => ['ALFAMART', 'INDOMARET'],
]);
```

The gateway normalises the codes you send, so never compare the result verbatim: `ALFAMART` comes back as `RETAIL_ALFAMART_LINKQU`.

The authoritative list comes from the gateway, not from this page — `SingaPay::paymentLinks()->paymentMethods()` returns `payment_methods` (each with `code`, `name`, `group`, `desc`) plus `available_codes`. As of sandbox 2026-08-21 there are 20 codes in five groups:

| Group | Codes |
|---|---|
| `card` | `NICEPAY_CARD` |
| `ewallet` | `EWALLET_DANA`, `EWALLET_GOPAY`, `EWALLET_OVO`, `EWALLET_SHOPEEPAY` |
| `offline_store` | `ALFAMART`, `INDOMARET` |
| `qris` | `QRIS` |
| `va` | `VA_BCA`, `VA_BNC`, `VA_BNI`, `VA_BRI`, `VA_BSI`, `VA_CIMB`, `VA_DANAMON`, `VA_MANDIRI`, `VA_MAYBANK`, `VA_MUAMALAT`, `VA_OCBC`, `VA_PERMATA` |

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

## Recurring payments, cards, and direct debit

All three were verified against sandbox on 2026-08-21. All are money-in — none of them needs `SINGAPAY_MONEY_OUT=true`.

### Subscriptions (recurring plans)

```php
$plan = SingaPay::subscriptions()->createPlan([
    'name' => 'Monthly Plan',
    'customer_name' => 'Budi',
    'customer_email' => 'budi@example.id',
    'customer_phone' => '081234567890',
    'amount' => Amount::rupiah(99_000),     // or 'items' — exactly one of the two
    'subscription_id' => 'SUB-ORDER-4021',  // your correlation key
    'schedule' => [
        'interval' => 1,
        'interval_unit' => 'month',
        'total_interval' => 12,
        'start_time' => now()->addDay()->toIso8601String(),
    ],
]);

$plan->data('payment_link_url'); // where the customer links their card
$plan->data('status');           // "pending_card_linking" until they do
```

**Correlate on `subscription_id`, not `merchant_reff_no`.** The gateway accepts `merchant_reff_no` without complaint and then discards it — plans always come back with `merchant_reff_no: null`. `subscription_id` is echoed back verbatim, and generated for you (`SUB-…`) when omitted. `payment_type` is likewise always `null` at creation; it is populated once the customer links an instrument.

The rest: `findPlan($id)`, `updatePlan($id, [...])` (changing `amount`/`items` performs an upgrade/downgrade — the response then carries an `upgrade` object with proration), and `cancelPlan($id, $reason)`, which records `cancellation_reason` under `metadata.extra`.

### Cards

```php
$tx = SingaPay::card()->payment([
    'amount' => Amount::rupiah(150_000),
    'goods_name' => 'Invoice #0001',
    'card_number' => '4111111111111111',
    'card_expiry' => '3012',   // YYMM — December 2030
    'card_cvv' => '123',
    // ... the required customer_* fields, see Card::payment() PHPDoc
]);

$tx->data('transaction_id');
$tx->data('provider_transaction_id');
```

⚠️ **`card_expiry` is YYMM, not MMYY.** December 2030 is `3012`. The reverse order is rejected with `SP001 Card Expiri Date Check Please.` — and SP001 elsewhere means "outcome unknown, go inquire", when here it is purely a format error.

`inquireStatus($id)` and `cancel($id)` accept either `transaction_id` or `provider_transaction_id` (both verified). `cancel()` refuses an already-`success` transaction with `SP012`.

⚠️ A server that touches raw card numbers is in PCI-DSS scope. Use Payment Link unless you understand the consequences.

### Direct debit

```php
$binding = SingaPay::directDebit()->bindCard([
    'customer_ref' => 'CUST-4021',
    'phone_no' => '081234567890',   // digits only, NO leading "+"
]);

$binding->data('redirect_url');  // binding webview, expires in ~10 minutes
$binding->data('status');        // PENDING_AUTH
```

⚠️ **`phone_no` must not carry a leading `+`.** `081234567890` and `6281234567890` are accepted; `+6281234567890` is rejected with a completely uninformative `SP002 General Failure`.

The customer has to finish the binding at `redirect_url` (an AyoConnect webview) — there is no automated path. Poll `bindingStatus($id)` until `PENDING_AUTH` becomes `ACTIVE`; charging an inactive binding is refused with `SP018`. `charge()` and `unbindCard()` may answer HTTP 202 with `requires_otp`; finish those through `verifyOtp()`.

Direct debit is **not** subject to the money-out guard even though its request is signed: it collects from the customer rather than sending funds out.

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
| ↳ `IpNotWhitelistedException` | SP017, or a bare HTTP 403 "IP … not registered" from the token endpoint |
| ↳ `ValidationException` | SP018, or HTTP 422 from the money-in endpoints (has per-field `->errors()`) |
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

The money-out guard still applies under `fake()`: money-out requests throw `MoneyOutDisabledException` unless `SINGAPAY_MONEY_OUT=true`. That is deliberate — a test must never pass on a path production refuses. To exercise money-out, turn the flag on in your test environment (e.g. `<env name="SINGAPAY_MONEY_OUT" value="true"/>` in `phpunit.xml`).

For testing webhook listeners, see [webhooks.md](webhooks.md#testing-your-listeners).
