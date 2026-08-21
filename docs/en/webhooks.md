# Webhooks

> 🇮🇩 Versi Bahasa Indonesia: [docs/webhooks.md](../webhooks.md)

## How it works

1. The package registers `POST /webhooks/singapay` (configurable path) **without** the `web` middleware group — no session, no CSRF. This matters: SingaPay sends no CSRF token, so a CSRF-protected route would reject every delivery with 419.
2. The `VerifyWebhookSignature` middleware verifies `X-Signature` (HMAC-SHA512, constant-time comparison) and rejects `X-Timestamp` values outside the tolerance window (default ±300s) to block replays. Failed deliveries get a terse **401** that never explains why.
3. The controller identifies the webhook **type** from the payload (the `event` field, with a payload-shape fallback for payment links) — never from the URL, because several types share one callback URL.
4. Idempotency uses a **claim-then-dispatch** protocol: a delivery claims its row in `singapay_webhook_events` (keyed by body hash, enforced by a unique index) **before** dispatching. Concurrent duplicates — including deliberate replays of a validly signed delivery within the tolerance window — lose the insert race and are acknowledged with 200 without dispatching, so listeners fire exactly once.
5. Two Laravel events fire: `WebhookReceived` (always) and the type-specific event. Listeners run synchronously; if a listener throws, the claim is released and SingaPay receives a 5xx, so the next retry is reprocessed (at-least-once). Claims stranded by a hard-crashed worker go stale after five minutes and are reclaimed by a later retry.

## SingaPay dashboard configuration

The dashboard exposes **eight separate Notif URL fields** — Transaction (Money In), Disbursement (Money Out), Payment Link Inquiry, Product Expiration, Transaction Expiration, Subscription Cycle, Settlement, and Direct Debit. Point every one of them at the same route:

```
https://your-app.com/webhooks/singapay
```

One route is enough: the controller identifies the type from the payload, never from the URL. Per the dashboard, direct-debit binding and unbinding always use the merchant-level Direct Debit URL rather than any per-account one.

### Webhooks follow the credential, not the merchant

This is the likeliest reason your webhooks never arrive, and nothing surfaces it until you know to look.

The dashboard's **Credential Details** page carries an *Assigned Accounts* list, and its webhook panel states *"Applies to all accounts sharing this credential."* A transaction's notification therefore goes to the Notif URL of the **credential that owns the account** — not the credential you happen to call the API with, and not one merchant-wide setting.

Two things must line up, and both must be right at once:

1. **The URL** belongs on the credential that owns the account. Filling it in on any other credential changes nothing — SingaPay still delivers, just somewhere else, and you see no trace of it.
2. **Your `SINGAPAY_CLIENT_SECRET` must belong to that same credential.** The webhook signature is computed with that credential's client secret; another credential's secret fails verification and answers 401.

Verified 2026-08-21: a genuine `va-transaction` delivery had its signature recomputed against four candidates — the client secret and HMAC key of two different credentials — and only the **client secret of the credential owning the account** matched.

SingaPay also **retries** a delivery answered with 401, about once a minute for roughly eight minutes before giving up. A misconfiguration therefore does not lose the notification outright — but the window to fix it is only a few minutes.

### One URL can receive deliveries from more than one credential

The "webhooks follow the credential" rule above holds for money-in, but it is not the whole story for money-out. Verified 2026-08-21: a `disbursement` **triggered with a Specific credential** was notified by the **Default** credential — the delivery carried Default's `X-PARTNER-ID`, and only Default's client secret matched the signature. The same URL was configured on both credentials.

So an app using a Specific credential (and SP403 forces exactly that for an account that owns one) rejects every money-out notification with 401 — silently, because all you see is a `singapay.webhook.rejected` line in the log.

The fix is to declare that other credential as a **connection**. Every connection's secret automatically joins the verification candidates.

```php
'connections' => [
    'default-credential' => [
        'client_id' => env('SINGAPAY_DEFAULT_CLIENT_ID'),
        'client_secret' => env('SINGAPAY_DEFAULT_CLIENT_SECRET'),
    ],
],
```

If that credential only ever **sends you webhooks** and you never call the API with it — a retired credential, or someone else's — it does not need to be a full connection; just list its secret:

```dotenv
SINGAPAY_WEBHOOK_SECRETS=retired-credential-secret,another-partys-secret
```

Keys from both sources are compared in constant time, and **outbound** signatures still use the calling connection's `client_secret` alone — so adding keys here loosens nothing except the set of signers you acknowledge.

A half-configured connection (no secret of its own) is skipped rather than allowed to break verification.

### The signing key is the Client Secret

SingaPay signs webhooks with the **Client Secret**, not the HMAC Validation Key. This was verified against a live delivery (2026-08-21) by recomputing the signature with both keys — only the Client Secret matched.

The SDK accepts either, so nothing needs changing on your side. But if `SINGAPAY_HMAC_KEY` is the only key you set and the Client Secret is not configured, verification will fail.

### The dashboard "Test" button sends an UNSIGNED request

The **Test** button beside each Notif URL field posts a dummy payload:

```json
{"status": 200, "success": true, "data": "VA Payment Notification testing"}
```

with **no** `X-Signature`, `X-Timestamp` or `Authorization` header. The SDK answers 401 `Invalid webhook signature.` — which is **correct behaviour**, not a sign your installation is broken.

So the Test button only proves your URL is reachable from the internet. To exercise the full path, trigger a real transaction through the dashboard **Simulator**; genuine deliveries carry all three headers and verify cleanly.

If you really want the Test button to succeed, the only way is to turn verification off (`SINGAPAY_WEBHOOK_VERIFY=false`) — never do that in production.

Deliveries arrive with the user-agent `GuzzleHttp/7`. SingaPay does not document their source IP and it is not guaranteed stable, so never make an IP allowlist your only control — the signature is the authority.

### Triggering each event in sandbox

Eight of the thirteen event types have been confirmed against genuine SingaPay payloads (2026-08-21). Here is how to provoke them:

| Event | How to trigger |
|---|---|
| `va-transaction` | Simulator → Virtual Account, enter the VA number |
| `qris-acquirer-transaction` | Simulator → QRIS & E-Wallet, paste the `qr_data` string |
| `payment-link-transaction` | open `payment_url`, pay with test card `4111111111111111` (expiry `12/30` in the UI, CVV `123`) |
| `payment-link-inquiry` | sent automatically a second before the payment link is paid, when the customer picks a method |
| retail outlet (Alfamart/Indomaret) | a payment link with `whitelisted_payment_method: ['ALFAMART']`, pick the method, then pay the code in the dashboard's **Retail Outlet** simulator |
| `ewallet-native-transaction` | open `checkout_url`, complete it in the DANA sandbox |
| `ewallet-topup` | `ewalletMoneyOut()->triggerTopup()` |
| `transaction-expiration` | automatic, scheduled batch (~1 minute after the due time) |
| `disbursement` | `disbursement()->transfer()` to an account number whose prefix selects the outcome — see the sandbox outcome table below |

The rest cannot be provoked in sandbox: `settlement` (a scheduled batch), `subscription-cycle` (waits for a billing cycle), `qris-issuer` and `direct-debit` (products not live). **`product-expiration` appears never to fire at all**: payment links and VAs left well past `expired_at` never change state — expiry is computed at read time rather than written back — so there is no state change for a batch to report.

**DANA sandbox account.** E-wallet checkout needs a number registered in DANA's sandbox; your own number is rejected. The working test account is **0817345545** with PIN **123321**, documented by [Faspay](https://docs.faspay.co.id/before-live/account-testing) — Indonesian PSPs share the same DANA sandbox environment. SingaPay does not publish it. Do not guess the PIN: test accounts lock after a few attempts.

**Money-in and money-out payloads differ in shape.** Money-in events arrive in the v1 envelope (`{"status":200,"success":true,"event":...}`), while `ewallet-topup` arrives in the v2 envelope (`{"response_code":"SP000","response_message":...,"event":...}`). The SDK normalises both, but never assume one shape if you read `$event->payload` directly.

### The paid webhook does not tell you how it was paid

Verified with a real Alfamart payment (2026-08-21): the `payment-link-transaction` delivery reports `data.payment.method` as the literal **`payment_link`**, however the customer actually paid. Nothing in that payload mentions retail, card or VA.

The method is announced only in the earlier `payment-link-inquiry` delivery, and the two are joined on an identical `reff_no`:

```php
// When the customer picks a method:
public function handle(PaymentLinkInquiryReceived $event): void
{
    $event->retailCode();           // "ALFAMART" | "INDOMARET" | null
    $event->paymentMethodName();    // "Alfamart (Linkqu)" — display label
    $event->paymentMethodValue();   // the retail payment code
    $event->historyReffNo();        // the join key
}

// When it is paid — match against what you stored above:
public function handle(PaymentLinkPaid $event): void
{
    $event->reffNo();               // identical to historyReffNo()
}
```

So recording "paid at Alfamart" **requires** listening to the inquiry event; the paid event alone can never tell you.

One more trap: `payment_method_additional` arrives as a **JSON string**, not an object, so `data('payment_link_history.payment_method_additional.retail_code')` quietly returns null. Use `paymentMethodAdditional()`, which decodes it.

## Event catalogue

| Event | Webhook type | Source |
|---|---|---|
| `VirtualAccountPaid` | `va-transaction` | `transaction_notif_url` |
| `QrisAcquirerPaid` | `qris-acquirer-transaction` | `transaction_notif_url` |
| `PaymentLinkPaid` | `payment-link-transaction` (or no `event` field) | `transaction_notif_url` |
| `EwalletPaid` | `ewallet-native-transaction` | `transaction_notif_url` |
| `SubscriptionCycleProcessed` | `subscription.cycle.*`, `subscription.plan.status_changed` | dedicated |
| `DisbursementProcessed` | `disbursement` | `disbursement_notif_url` |
| `EwalletTopupProcessed` | `ewallet-topup` | `disbursement_notif_url` |
| `QrisIssuerProcessed` | `qris-issuer` | `disbursement_notif_url` |
| `SettlementProcessed` | `settlement.*` | dedicated |
| `DirectDebitBindingUpdated` | `direct-debit*` | `direct_debit_notif_url` |
| `PaymentLinkInquiryReceived` | `payment_link.inquiry*` | dedicated |
| `ProductsExpired` | `product-expiration` (batch) | optional |
| `MoneyInTransactionsExpired` | `transaction-expiration` (batch) | optional |

Every event extends `WebhookReceived`, so all of them expose `->payload`, `->type`, `->event()`, and `->data('dot.notation')`.

## Listener examples

```php
// AppServiceProvider::boot() or EventServiceProvider
use Aliziodev\Singapay\Events\DisbursementProcessed;
use Aliziodev\Singapay\Events\VirtualAccountPaid;
use Aliziodev\Singapay\Events\WebhookReceived;

Event::listen(function (VirtualAccountPaid $event) {
    Order::where('reff_no', $event->reffNo())->first()?->markAsPaid();
});

Event::listen(function (DisbursementProcessed $event) {
    $payout = Payout::where('reference', $event->referenceNumber())->first();

    // Money-out webhooks fire on FAILURE too — always check the status:
    $event->isSuccessful() ? $payout?->markAsCompleted() : $payout?->markAsFailed();
});

// Catch everything, including types the SDK doesn't know yet:
Event::listen(function (WebhookReceived $event) {
    Log::info('singapay webhook', ['event' => $event->event()]);
});
```

Heavy work? Dispatch a job from the listener (`dispatch(new HandlePayment(...))`) so the webhook is acknowledged quickly.

## Important notes

- Money-in payloads use `d M Y H:i:s` timestamps in **Asia/Jakarta**; money-out uses Unix milliseconds as strings. Never parse them assuming UTC.
- `success: true` in subscription payloads means *the webhook was emitted*, not *the charge succeeded* — use `$event->isPaymentFailed()`.
- `amount.value` may be an integer (VA/QRIS) or a decimal string (payment link, money-out) depending on the webhook type.
- The bearer token in the webhook `Authorization` header is a random string generated by SingaPay — not your access token — yet it is still part of the signed string.

## Testing your listeners

The `InteractsWithSingaPay` concern can POST payloads to the webhook route with a valid signature, exactly like a SingaPay delivery:

```php
use Aliziodev\Singapay\Testing\Concerns\InteractsWithSingaPay;

uses(InteractsWithSingaPay::class);

it('marks the order paid when the VA is paid', function () {
    $order = Order::factory()->create(['reff_no' => 'INV-1']);

    $this->postSingaPayWebhook([
        'event' => 'va-transaction',
        'data' => ['transaction' => ['reff_no' => 'INV-1', 'status' => 'paid']],
    ])->assertOk();

    expect($order->fresh()->isPaid())->toBeTrue();
});
```

## The `WebhookEvent` model

Every processed delivery is recorded as an Eloquent model, `Aliziodev\Singapay\Models\WebhookEvent` (casts: `payload` → array, `processed_at` → datetime):

```php
use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Models\WebhookEvent;

// Inspect history
WebhookEvent::ofType(WebhookType::Disbursement)->latest()->limit(20)->get();

// Replay a delivery through your listeners — dispatches the generic
// AND the typed event, exactly like the original delivery
WebhookEvent::find($id)->replay();
```

`toEvent()` rebuilds the typed event object (e.g. `DisbursementProcessed`) from the stored payload — handy when a listener failed and you want to reprocess.

## Housekeeping

Rows are only needed for the duration of SingaPay's retry window (minutes). The model is `MassPrunable` — just schedule Laravel's standard pruner:

```php
use Aliziodev\Singapay\Models\WebhookEvent;

Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])->daily();
```

Retention defaults to 7 days, configurable via `SINGAPAY_WEBHOOK_PRUNE_DAYS` (config `singapay.webhooks.prune_after_days`).
