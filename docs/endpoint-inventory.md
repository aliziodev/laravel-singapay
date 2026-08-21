# Endpoint Inventory & Source Reconciliation

Working reference produced while building the SDK (2026-08-20), cross-checking
`llms.txt` × the OpenAPI specs × each resource's Overview page, as required by
the internal design brief. **[S]** marks the six endpoints that require the request signature
(scheme C) and are guarded by `singapay.money_out.enabled`.

Hosts:

| Host | Sandbox | Production |
|---|---|---|
| Payment B2B | `https://sandbox-payment-b2b.singapay.id` | `https://payment-b2b.singapay.id` |
| Biller | `https://sandbox-biller-b2b.singapay.id` | `https://biller-b2b.singapay.id` |
| Identity (KYC) | `https://sandbox-apigw.singapay.id` | `https://api.singapay.id` |

> Initial research assumed the identity service lived on `core.singapay.id`; that host
> only serves its documentation. The API hosts are the ones above.

## Auth

| Method | Path | Scheme | SDK |
|---|---|---|---|
| POST | `/api/v1.1/access-token/b2b` | A (HMAC-SHA512, Jakarta date) | `AccessTokenManager` (default) |
| POST | `/api/v1.0/access-token/b2b` | B (Basic) — also the biller host's token endpoint | `AccessTokenManager` (`auth_version=1.0` / biller) |
| POST | `/api/v1/kyc/auth/get-auth-token` | D (HMAC-SHA256, RFC 3339 UTC) — identity host | `IdentityTokenManager` |

Token responses return `expires_in` as a **string** on the payment/biller hosts
and an **integer** on the identity host. The SDK casts defensively.

## Payment host endpoints

| Method | Path | SDK method |
|---|---|---|
| GET | `/api/v1.0/accounts` | `accounts()->list()` |
| POST | `/api/v1.0/accounts` | `accounts()->create()` |
| GET | `/api/v1.0/accounts/{id}` | `accounts()->find()` |
| PATCH | `/api/v1.0/accounts/update/{id}` | `accounts()->update()` |
| ~~PATCH~~ | ~~`/api/v1.0/accounts/update-status/{id}`~~ | route does not exist (404) — `accounts()->updateStatus()` uses `update/{id}` |
| DELETE | `/api/v1.0/accounts/{id}` | `accounts()->delete()` |
| GET | `/api/v1.0/balance-inquiry` | `balance()->merchant()` |
| GET | `/api/v1.0/balance-inquiry/{account_id}` | `balance()->account()` |
| GET | `/api/v1.0/account-transfer/{account_id}` | `accountTransfer()->list()` |
| GET | `/api/v1.0/account-transfer/{account_id}/{transaction_id}` | `accountTransfer()->find()` |
| POST | `/api/v1.0/account-transfer/{account_id}/transfer` **[S]** | `accountTransfer()->transfer()` |
| GET | `/api/v1.0/statements/{account_id}` | `statements()->list()` |
| GET | `/api/v1.0/statements/{account_id}/{statement_id}` | `statements()->find()` |
| GET | `/api/v1.0/payment-link-manage/{account_id}` | `paymentLinks()->list()` |
| GET | `/api/v1.0/payment-link-manage/payment-methods` | `paymentLinks()->paymentMethods()` |
| POST | `/api/v2.0/payment-link/{account_id}` | `paymentLinks()->create()` |
| GET | `/api/v2.0/payment-link/{payment_link_id}` | `paymentLinks()->find()` |
| PUT | `/api/v2.0/payment-link/update/{payment_link_id}` | `paymentLinks()->update()` |
| DELETE | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` | `paymentLinks()->delete()` |
| GET | `/api/v1.0/payment-link-histories/{account_id}` | `paymentLinkHistories()->list()` |
| GET | `/api/v1.0/payment-link-histories/{account_id}/{history_id}` | `paymentLinkHistories()->find()` |
| GET | `/api/v1.0/virtual-accounts/{account_id}` | `virtualAccounts()->list()` |
| POST | `/api/v1.0/virtual-accounts/{account_id}` | `virtualAccounts()->create()` |
| GET | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` | `virtualAccounts()->find()` |
| PUT/PATCH | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` | `virtualAccounts()->update()` (PUT) |
| DELETE | `/api/v1.0/virtual-accounts/{account_id}/{virtual_account_id}` | `virtualAccounts()->delete()` |
| GET | `/api/v1.0/va-transactions/{account_id}` | `vaTransactions()->list()` |
| GET | `/api/v1.0/va-transactions/{account_id}/{transaction_id}` | `vaTransactions()->find()` |
| GET | `/api/v1.0/va-transactions/{account_id}/detail-by-va-number/{va_number}` | `vaTransactions()->listByVaNumber()` |
| POST | `/api/v1.0/qris-dynamic/{account_id}/generate-qr` | `qris()->generate()` |
| GET | `/api/v1.0/qris-dynamic/{account_id}` | `qris()->list()` |
| GET | `/api/v1.0/qris-dynamic/{account_id}/show/{id}` | `qris()->find()` |
| POST | `/api/v1.0/ewallet-native/{account_id}/create-checkout` | `ewallet()->createCheckout()` |
| POST | `/api/v2.0/ewallet-native/create-order` | `ewallet()->createOrder()` |
| GET | `/api/v1.0/ewallet-native-transactions/{account_id}` | `ewallet()->listTransactions()` |
| GET | `/api/v1.0/ewallet-native-transactions/{account_id}/{transaction_id}` | `ewallet()->findTransaction()` |
| GET | `/api/v1.0/ewallet-native/{account_id}/inquiry-status/{id}` | `ewallet()->inquireStatus()` |
| POST | `/api/v2.0/card/{account_id}/payment` | `card()->payment()` ⚠️ PCI |
| PATCH | `/api/v2.0/card/{account_id}/cancel/{id}` | `card()->cancel()` |
| GET | `/api/v2.0/card/{account_id}/inquiry-status/{id}` | `card()->inquireStatus()` |
| POST | `/api/v2.0/recurring/plans` | `subscriptions()->createPlan()` |
| GET | `/api/v2.0/recurring/plans/{id}` | `subscriptions()->findPlan()` |
| PATCH | `/api/v2.0/recurring/plans/{id}` | `subscriptions()->updatePlan()` |
| POST | `/api/v2.0/recurring/plans/cancel/{id}` | `subscriptions()->cancelPlan()` |
| POST | `/api/v2.0/direct-debit/binding` | `directDebit()->bindCard()` |
| GET | `/api/v2.0/direct-debit/binding/{binding_id}` | `directDebit()->bindingStatus()` |
| POST | `/api/v2.0/direct-debit/binding/{binding_id}/unbind` | `directDebit()->unbindCard()` |
| POST | `/api/v2.0/direct-debit/charge` **[S]** | `directDebit()->charge()` |
| POST | `/api/v2.0/direct-debit/verify-otp` | `directDebit()->verifyOtp()` |
| GET | `/api/v2.0/direct-debit/transaction/{transaction_id}` | `directDebit()->findTransaction()` |
| GET | `/api/v1.0/disbursement/{account_id}` | `disbursement()->list()` |
| GET | `/api/v1.0/disbursement/{account_id}/{transaction_id}` | `disbursement()->find()` |
| POST | `/api/v1.0/disbursement/{account_id}/check-fee` | `disbursement()->checkFee()` |
| POST | `/api/v1.0/disbursement/check-beneficiary` (no account id) | `disbursement()->checkBeneficiary()` |
| POST | `/api/v2.0/disbursement/transfer` **[S]** | `disbursement()->transfer()` |
| POST | `/api/v2.0/disbursement/{account_id}/inquiry-status` | `disbursement()->inquireStatus()` |
| POST | `/api/v2.0/qris/issuer/mpm/inquiry-merchant` | `qrisMoneyOut()->inquireMerchant()` |
| POST | `/api/v2.0/qris/issuer/mpm/payment-credit` **[S]** | `qrisMoneyOut()->triggerPaymentCredit()` |
| POST | `/api/v2.0/qris/status/{account_id}` | `qrisMoneyOut()->inquireStatus()` |
| POST | `/api/v2.0/ewallet/account-inquiry` | `ewalletMoneyOut()->inquireAccount()` |
| POST | `/api/v2.0/ewallet/trigger-topup` **[S]** | `ewalletMoneyOut()->triggerTopup()` |
| POST | `/api/v2.0/ewallet/{account_id}/inquiry-status` | `ewalletMoneyOut()->inquireStatus()` |
| POST | `/api/v1.0/cardless-withdrawals/create` **[S]** | `cardlessWithdrawal()->create()` |
| GET | `/api/v1.0/cardless-withdrawals/transaction/{account_id}` | `cardlessWithdrawal()->list()` |
| GET | `/api/v1.0/cardless-withdrawals/transaction/{account_id}/{reference_number}` | `cardlessWithdrawal()->find()` |
| POST | `/api/v1.0/cardless-withdrawals/cancel` | `cardlessWithdrawal()->cancel()` |

## Biller host (command-style: POST with `{"command", "data"}`)

| Path | Command(s) | SDK method |
|---|---|---|
| `/api/v1/check-balance` | `check-balance` | `biller()->checkBalance()` |
| `/api/v1/detail-bill-transaction` | `detail-bill-transaction` | `biller()->transactionDetail()` |
| `/api/v1/list-bill-transaction` | `list-bill-transaction` | `biller()->listTransactions()` |
| `/api/v1/reset-customer-id` | `reset-customer-id` (dev only) | `biller()->resetCustomerId()` |
| `/api/v2/prepaid/get-parameter-game-topup` | `get-parameter-game-topup` | `biller()->gameTopupParameters()` |
| `/api/v2/prepaid/inquiry` | `plntok`, `topupg` | `biller()->prepaidInquiry()` |
| `/api/v2/prepaid/payment` | `pulsa`, `data`, `plntok`, `topupg`, `vouchg` | `biller()->prepaidPayment()` |
| `/api/v2/postpaid/inquiry` | `pdam`, `plnpos`, `plnnon`, `intv`, `bpjsks`, `bputk`, `putk`, `mobpos` | `biller()->postpaidInquiry()` |
| `/api/v2/postpaid/payment` | same enum | `biller()->postpaidPayment()` |
| `/api/v1/prepaid/inquiry` | `plntok` | `biller()->legacyPrepaidInquiry()` |
| `/api/v1/prepaid/payment` | `pulsa`, `data`, `plntok` | `biller()->legacyPrepaidPayment()` |
| `/api/v1/postpaid/inquiry` | same as v2 | `biller()->legacyPostpaidInquiry()` |
| `/api/v1/postpaid/payment` | same as v2 | `biller()->legacyPostpaidPayment()` |

Biller envelope: `{command, response_code: "00"|"99", response_text, data}`.
Note it **reuses the key `response_code`** for a status that is not an SP code,
and puts the message under `response_text` rather than `response_message`.
`Response` detects it by the presence of `command` and treats `"00"` as success
— read as a v2 envelope, every successful biller call would look like a failure.
Codes are not zero-padded consistently: success is `00`, rejected format is
`04`, general failure `99`, but "transaction not found" is `6`.

**The biller mixes envelopes.** Business replies use the shape above, but a
`401` comes back in the **v1** shape (`{status, success, error:{code,message}}`)
with no `command` at all, and a `404` omits `data` entirely. Requiring
`command` for detection is what makes all three land correctly; a detector
keyed on `response_code` alone would misread the lot.

On a rejected request the biller's `data` **is** the field-error map, keyed by
dotted path (`{"data.transaction_id": "The data.transaction id field is
required."}`) with no `errors` wrapper. `fieldErrors()` returns it for a failed
biller response and stays empty for a successful one.

All 13 business paths in `openapi-biller.json` are implemented, and the v1 ones
are exposed only under explicit `legacy*` names — v2 is the default for prepaid
and postpaid. `check-balance`, `list-bill-transaction` and `reset-customer-id`
exist only as v1; there is no v2 of them.

44. **PPOB payments need a third secret.** Every `*Payment()` call carries a
    `password` in its `data` — the *merchant credential password*, distinct
    from the client secret used to obtain the token. It is deliberately not
    read from config: passing it at the call site keeps a stray code path
    from spending real money, the same reasoning as the money-out guard.

45. **All 13 biller paths verified against `biller-b2b.singapay.id/docs`**
    (2026-08-21) — command enums, required fields, the conditional `period`
    for bpjsks/bputk/putk, and `reference_number` sourcing all match the SDK.
    Notes worth keeping: `pulsa`, `data` and `vouchg` are paid directly with
    no inquiry (there `customer_id` is the phone number), while `plntok` and
    `topupg` require one; `period` is sent as an integer but echoed back as a
    string; and on postpaid inquiry `price` is the total payable
    (amount + late fee + admin fee), so bill `price`, never `amount`.

46. **The `disbursement` webhook, captured live** (2026-08-21) — the eighth
    event type confirmed against a genuine payload. It uses the v2 envelope
    (`response_code` at the top level) and reports the outcome twice:
    `response_code` is `SP000` on success and **`SP001` on failure**, with
    `response_message: "Transaction Failure"`, while `data.transaction_status`
    carries `00`/`Success` or `06`/`Failed`. Read the status object — SP001
    elsewhere in the API means "outcome unknown, inquire before reacting",
    so the envelope code alone actively misleads here.

    A failed delivery adds two fields a successful one omits: `failed_reason`
    (the upstream text) and `failed_code`. It also leaves
    `processed_timestamp` as an **empty string** rather than null, and
    `balance_after.value` as `"0"` — one more instance of discrepancy 23.
    Successful payloads carry `gross_amount` = `net_amount` + `fee`, so the
    fee is charged on top of the transfer rather than netted out of it.

47. **One callback URL can receive deliveries signed by two different
    credentials.** With the same Notif URL configured on both the merchant
    Default credential and a Specific one, a disbursement *triggered with the
    Specific credential* was notified by the **Default** credential: the
    delivery carried Default's `X-PARTNER-ID`, and recomputing the signature
    against four candidates matched only Default's client secret (normalized
    body hash — the SDK's primary candidate, confirming discrepancy 19's
    scheme). Verified 2026-08-21.

    This is a trap for any merchant that follows SP403's advice and calls the
    API with a Specific credential: every money-out notification then fails
    verification and is answered 401, visible only as a
    `singapay.webhook.rejected` log line. The SDK gained
    `webhooks.secrets` (`SINGAPAY_WEBHOOK_SECRETS`, comma-separated) for
    exactly this — extra keys join the verification candidates, while
    outbound signing still uses `client_secret` alone.

48. **SingaPay retries a rejected delivery for about eight minutes.** A
    money-out delivery answered 401 was re-sent roughly once a minute, nine
    times, then abandoned. The earlier note said "about a minute later",
    which understated it — but the repair window is still minutes, not hours.

49. **Virtual account `expired_at` is Unix milliseconds; the v2 payment link's
    is a date string.** Creating a temporary VA with `2026-08-21 21:15:00`
    fails with HTTP 422, while `1787321700000` is accepted and echoed back
    verbatim. The v2 payment link takes the ordinary date string (discrepancy
    29) — the two products genuinely disagree, so do not share a helper.

50. **Gateway timestamps are WIB, and the expiry sweep runs about once a
    minute.** A `transaction-expiration` delivery stamped
    `21 Aug 2026 04:35:02` arrived at 21:35:03 UTC the day before, i.e. WIB
    (UTC+7); it reported a QRIS that expired at `04:34:08`, so the batch
    picked it up 54 seconds later. Payment links and virtual accounts,
    however, were still `open`/`active` minutes past their own `expired_at`,
    so the product sweep is on a different (slower) schedule than the
    transaction sweep.

51. **E-wallet checkout returns a different artifact per vendor, and OVO
    returns none at all.** All four vendors answer with an identical *key
    set*, which makes the difference easy to miss — it is in the values.
    Verified in sandbox 2026-08-21:

    | Vendor | `checkout_url` | `checkout_url_app` |
    |---|---|---|
    | `EWALLET_DANA` | `https://m.sandbox.dana.id/...` | null |
    | `EWALLET_OVO` | **null** | **null** |
    | `EWALLET_GOPAY` | `https://simulator.sandbox.midtrans...` | deeplink |
    | `EWALLET_SHOPEEPAY` | `https://app.uat.shopeepay.co.id/...` | `shopeepayid://...` |

    OVO is push-to-pay: the request goes to the customer's app, keyed on
    `customer_phone` — which OVO alone *requires* (omitting it is HTTP 422,
    while DANA accepts the call without it). So `checkoutUrl()` is null for
    OVO by design, and any consumer that redirects without a null check
    sends the customer nowhere.

    **How far each vendor can actually be taken in sandbox** (all four
    attempted 2026-08-21, distinct amounts so the outcomes cannot be
    confused):

    | Vendor | Outcome | How |
    |---|---|---|
    | GoPay | **paid** | its `checkout_url` is a **public Midtrans sandbox simulator** (`simulator.sandbox.midtrans.com/v2/deeplink/detail`) — no vendor account needed, just open and confirm |
    | DANA | **paid** | the DANA sandbox cashier, account `0817345545` / PIN `123321` |
    | OVO | reaches `open`, then fails unconfirmed | fully provisioned — see below; completing it needs an OVO handset to accept the push |
    | ShopeePay | stays `open` | its `checkout_url` does resolve to a real UAT checkout page (`uat.shopeepay.co.id/checkout/payment`), but curl gets 403 — bot protection rather than proof an account is required |

    **OVO is not broken, and it validates the phone number upstream.** An
    unregistered number is refused at creation with a bare **HTTP 400 and no
    SP code**, carrying a customer-ready Indonesian message:
    `Nomor HP tidak terdaftar di aplikasi OVO. Pastikan nomor HP yang
    dimasukkan sudah terdaftar.` Surface that text to the customer — there
    is no code to branch on. `0817345545` and `081234567890` are accepted and
    create an `open` transaction awaiting the push; the earlier reading that
    OVO "fails on its own" was wrong, it fails only because nothing ever
    confirms the push.

    `payment_channel` is null at creation and filled in after payment:
    `GOPAY` for GoPay, and `BALANCE_` — with a trailing underscore — for
    DANA. Do not match on it exactly.

    **A failed e-wallet checkout emits no webhook at all.** The OVO failures
    produced zero deliveries (ledger and rejection log both empty for them),
    while both successes delivered `ewallet-native-transaction` within
    seconds. So never wait on a webhook to learn that an e-wallet payment
    failed — poll `inquireStatus()` or act on expiry.

52. **Post-refactor live sweep** (2026-08-21, after connections landed).
    Nineteen endpoint groups re-exercised against the sandbox with the new
    container wiring: accounts, balance (merchant + account), statements,
    account transfer, payment links (list, payment methods, v2 create),
    payment link histories, virtual accounts (list + create), VA
    transactions, QRIS (list + generate), e-wallet (list + create checkout
    ×4 vendors), disbursement (list, check-fee, check-beneficiary) and
    recurring plans — all green. `paymentMethods()` returns two keys,
    `payment_methods` and `available_codes`, of twenty entries each; count
    the entries, not the top-level keys.

53. **Retail outlet, verified end to end** (2026-08-21) — and the paid
    webhook does not say it was retail. A payment link whitelisted to
    `ALFAMART` produced a code the dashboard's Retail Outlet simulator
    accepted, and the resulting `payment-link-transaction` reported
    `data.payment.method` as the literal **`payment_link`**, with nothing
    about the channel anywhere in the payload.

    The channel is announced only in the earlier `payment-link-inquiry`
    delivery, under `data.payment_link_history`:
    `payment_method_name: "Alfamart (Linkqu)"`,
    `payment_method_value` = the retail payment code, and
    `payment_method_additional` = `{"retail_code":"ALFAMART","partner_reff":...}`.

    Two traps there. `payment_method_additional` is a **JSON-encoded
    string**, not an object, so dot access silently returns null — hence
    `PaymentLinkInquiryReceived::paymentMethodAdditional()` and
    `retailCode()`. And `payment_method_value` is only meaningful for
    methods that have a code: a card inquiry just echoes the history
    `reff_no` back into it.

    The join is `payment_link_history.reff_no` (inquiry) ==
    `transaction.reff_no` (paid) — identical strings in the captured pair.
    Recording "paid at Alfamart" therefore *requires* listening to the
    inquiry event; the paid event alone cannot tell you.

    **Both outlets paid end to end**, each through a link whitelisted to it
    alone: Alfamart code `211744000000012` (`Alfamart (Linkqu)`) and
    Indomaret code `111741000000012` (`Indomaret (Linkqu)`). The codes carry
    an outlet-specific prefix — `2117` vs `1117` — but treat that as
    observation, not contract; read `retail_code`. `partner_reff` is a
    concatenation of the WIB timestamp and the zero-padded payment-link id
    (`20260821223309` + `002303` for link 2303), which makes it a usable
    secondary correlation key.

    Also visible in the inquiry payload: SingaPay's own margin, broken out
    as `vendor_fee: 1800` + `our_margin: 1200` = `merchant_fee: 3000`, with
    `net_amount: 24000` on a 27,000 charge. And the amount typing splits
    across the pair — the inquiry reports `27000` (integer), the paid
    delivery `"27000.00"` (string), one more case for discrepancy 23.

54. **Expiry is computed at read time and never written back — and the two
    products disagree about whether you can see it.** A payment link an hour
    past `expired_at` still reports `status: "open"`, while
    `status_computed` is `"expired"` and `is_expired` is `true`. A
    `temporary` virtual account past its own `expired_at` still reports
    `status: "active"`, and the VA endpoints carry **no** `status_computed`
    or `is_expired` at all (identical key sets from `list()` and `find()`),
    so the only way to know is to compare `expired_at` — Unix
    *milliseconds* — against your own clock.

    Anyone gating on `status` will treat long-dead links and VAs as live.
    This also explains the silence around `product-expiration`: neither
    product ever changes state, so there is no batch to fire it, which is
    why baits left well past their expiry provoked nothing.

## Identity host

| Method | Path | SDK method |
|---|---|---|
| POST | `/api/v1/kyc/bank/verify` | `identity()->verifyBankAccount()` |
| POST | `/api/v1/kyc/ewallet/verify` | `identity()->verifyEwalletAccount()` |

Flat envelope: `{code, data, message, pricing, request_id}`. Only
`code: "SUCCESS"` responses are billed.

## ID types per resource (silent-bug territory)

| Context | Type |
|---|---|
| `account_id`, `virtual_account_id` | ULID string |
| `payment_link_id`, `history_id`, QRIS `id`, e-wallet `transaction_id` | integer |
| VA transaction `transaction_id` | business string (`VA-20251024-...`) |
| Disbursement show | business `transaction_id` string |
| Cardless withdrawal show | `reference_number` (not transaction_id) |
| Subscription plan, direct-debit binding/transaction | UUID |
| Card cancel/inquiry `{id}` | flexible: DB id / transaction_id / provider id |

## Time formats

| Where | Format |
|---|---|
| `X-Timestamp` header | Unix **seconds** |
| Payment link / VA `expired_at` (requests) | 13-digit Unix **milliseconds** string |
| QRIS / e-wallet `expired_at` | ISO 8601 |
| VA transaction filters | Unix milliseconds |
| Token signature date | `YYYYMMDD` in **Asia/Jakarta** |
| Identity token timestamp | RFC 3339 **UTC** |
| Money-in webhook root timestamp | `d M Y H:i:s` in Asia/Jakarta |
| Money-out webhook body timestamps | Unix milliseconds as strings |

## Discrepancies found during reconciliation

1. **`llms.txt` omits the Card endpoints** — they exist only in
   `openapi.json` (`/v2.0/card/...`). Implemented, flagged PCI.
2. **`check-beneficiary` is not account-scoped.** `llms.txt` omits it, and the
   SDK originally called
   `POST /api/v1.0/disbursement/{account_id}/check-beneficiary` — that path is
   a *different* resource (a GET transaction lookup answering
   `404 Disbursement Transaction not found`), which is why POST there returned
   `405 Supported methods: GET, HEAD`. `merchant-api.json` documents the real
   one with **no account id**:
   `POST /api/v1.0/disbursement/check-beneficiary` with
   `{bank_swift_code, bank_account_number}`. Verified working in sandbox
   2026-08-21 — returns `status`, `bank_name`, `bank_number_code`,
   `bank_swift_code`, `bank_account_number`, `bank_account_name`. A
   `POST /api/v2.0/disbursement/check-beneficiary` taking `bank_code` is also
   documented but answers SP014 in sandbox.
3. **Account update**: docs say `PATCH /accounts/update/{id}` (name/status/
   invite_members); the OpenAPI spec says `PATCH /accounts/update-status/{id}`
   (status only). Settled in sandbox 2026-08-21 — **the docs are right**:
   `update/{id}` works and accepts a status change, while
   `update-status/{id}` answers `404 The route ... could not be found`.
   `updateStatus()` is now a thin wrapper over `update()`.
4. **QRIS money-out inquiry-status**: the overview claims it needs the request
   signature, the endpoint page says it does not. The SDK follows the endpoint
   page (unsigned); verify in sandbox before production.
5. **`X-Timestamp` format**: the canonical signature guide says Unix seconds;
   the direct-debit charge page mentions ISO-8601. The SDK sends Unix seconds
   everywhere (canonical guide + reference implementations) — confirmed correct
   in sandbox on 2026-08-21: signed direct-debit charges and all four money-in
   methods are accepted, so the signature (and its timestamp) verifies.

12. **`card_expiry` is YYMM, not MMYY.** December 2030 is `3012`. `1230` is
    rejected with `SP001 Card Expiri Date Check Please.` — and SP001 otherwise
    means "outcome unknown, inquire before reacting", so the error actively
    misdirects. Verified in sandbox both ways.

13. **Direct-debit `phone_no` is not E.164.** A leading `+` is rejected with a
    contentless `SP002 General Failure`; `081234567890` and `6281234567890`
    both succeed. Verified in sandbox, five attempts.

14. **Recurring plans silently drop `merchant_reff_no`.** The field is
    accepted and the created plan always reports `merchant_reff_no: null`.
    `subscription_id` *is* honoured (and auto-generated when omitted), so it is
    the only usable correlation key. `payment_type` is likewise always null at
    creation.

15. **Direct-debit charge is signed but is not money-out.** It collects from
    the customer. The SDK guards on a separate `moneyOut` flag so accepting
    direct-debit payments never requires unlocking real disbursement.

16. **`amount` shape is not uniform across money-out.** Disbursement
    (`check-fee`, `transfer`) and cardless withdrawal want a **plain number**;
    the `{value, currency}` object is rejected with `422 Validation error
    amount` / `SP018 "The amount field must be a number."`. The e-wallet
    money-out endpoints want the **object** and were verified working with it.
    Verified in sandbox 2026-08-21 across all four shapes.

17. **Undocumented response code `SP403`** — "This account requires its own
    credential. Please use the account-specific API key." Returned by money-out
    endpoints when a merchant-wide Default credential is used for an account
    that has its own Specific credential. Added to `ResponseCode` as
    `AccountCredentialRequired`. See also discrepancy 10.

18. **The Biller host needs its own credentials.** Its token exchange rejects
    the payment host's `SINGAPAY_PARTNER_ID` with `403 Invalid X-PARTNER-ID`,
    and `openapi-biller.json` requires `X-PARTNER-ID` on every biller call.
    The SDK now takes `SINGAPAY_BILLER_CLIENT_ID`,
    `SINGAPAY_BILLER_CLIENT_SECRET` and `SINGAPAY_BILLER_PARTNER_ID`, each
    falling back to the payment values. The biller token path
    (`POST /api/v1.0/access-token/b2b`, HTTP Basic) already matched the spec.
6. **VA `bank_code` enum**: docs list 12 banks, the spec only 5 — the docs are
   right. The SDK does not restrict the value. Probed against sandbox on
   2026-08-21: all twelve `VA_*` codes returned by
   `payment-link-manage/payment-methods` are accepted — `BCA`, `BNC`, `BNI`,
   `BRI`, `BSI`, `CIMB`, `DANAMON`, `MANDIRI`, `MAYBANK`, `MUAMALAT`, `OCBC`,
   `PERMATA` (pass them to `virtual-accounts` without the `VA_` prefix).
   `BTN`, `BJB`, `SINARMAS`, `MEGA` and `BUKOPIN` are rejected with HTTP 422
   `bank_code: "The selected bank code is invalid."` Treat
   `paymentMethods()` as the live source of truth rather than any static list.

11. **Retail outlet (Alfamart/Indomaret) has no endpoint of its own.** The
    dashboard ships a Retail Outlet simulator and `payment-methods` advertises
    `ALFAMART` / `INDOMARET` under group `offline_store`, but every plausible
    dedicated path 404s (`retail-outlet`, `retail-outlets`, `retail`,
    `offline-store`, `retail-transactions`, `convenience-store`, under both
    v1.0 and v2.0). Retail is reachable only as a
    `whitelisted_payment_method` on a payment link; the gateway echoes the
    code back normalised (`ALFAMART` → `RETAIL_ALFAMART_LINKQU`). Paid end
    to end on 2026-08-21 — see discrepancy 53 for what the webhooks do and
    do not tell you about it.
7. **Token endpoint version**: docs use v1.1, the OpenAPI spec still
   references v1.0. Configurable via `auth_version` (default 1.1).
8. **Payment-link webhook has no `event` field** in its documented example,
   although shared-endpoints claims `payment-link-transaction`. Settled
   2026-08-21 against a real delivery: it **does** carry
   `"event": "payment-link-transaction"`, and it carries both fallback
   discriminators too (`data.transaction.type: "pl"` and
   `data.payment.method: "payment_link"`). The SDK's belt-and-braces
   discrimination is correct; the documented example was simply incomplete.

19. **Webhooks are signed with the Client Secret, not the HMAC Validation
    Key.** Settled on 2026-08-21 against a live delivery: the signature was
    recomputed with both keys and only the client secret matched. The SDK
    accepts either, so this is documentation rather than a behaviour change.

20. **The two expiration events use hyphens, not underscores.** A live
    delivery carried `"event": "transaction-expiration"` while the SDK enum
    (and the docs) spelled it `transaction_expiration`, so those deliveries
    landed with `event_type: null` and never dispatched
    `MoneyInTransactionsExpired` / `ProductsExpired`. Fixed, and the resolver
    now normalises the separator either way.

21. **The dashboard "Test" button sends unsigned requests** — no
    `X-Signature`, `X-Timestamp` or `Authorization` at all, so a correctly
    configured app answers 401. Only real deliveries are signed. Callback URLs
    are configured through eight separate Notif URL fields (money in, money
    out, payment link inquiry, product expiration, transaction expiration,
    subscription cycle, settlement, direct debit); pointing all eight at one
    route is correct.

22. **Webhooks are scoped to the credential that owns the account.** An
    earlier reading of this pass concluded that sandbox Simulator payments
    emit no money-in webhooks; that was wrong, and this entry replaces it.
    Notifications go to the Notif URL of the credential listed as owning the
    account under *Assigned Accounts* — the webhook panel says as much
    ("Applies to all accounts sharing this credential"). The seven earlier VA
    payments were delivered all along, to the placeholder URL on the owning
    credential, and the dashboard's Callback Activity History is scoped the
    same way, which is why it looked empty.

    Two things must match: the Notif URL on the owning credential, and the
    app's `SINGAPAY_CLIENT_SECRET`, which must be that credential's — the
    signature is computed with it. Verified 2026-08-21 by recomputing a real
    `va-transaction` signature against four candidates (client secret and
    HMAC key of two credentials); only the owning credential's client secret
    matched.

    Bonus, observed in passing: SingaPay **retries** a delivery answered with
    401 at roughly one-minute intervals, and the SDK's claim-then-dispatch
    ledger absorbed a genuine retry — one ledger row, listener fired exactly
    once. The account page's Notification Configuration toggles are unrelated
    to any of this; they sit beside Notification Email.

23. **Amount `value` is inconsistently typed in responses.** The same
    statement row returns `2500` (integer) for a populated amount and
    `"0.00"` (string) for zero, while the balance endpoint returns
    `"1203500.00"` for the value the statement reported as integer
    `1203500`. Strict comparisons break; funnel every incoming amount
    through `Amount::from()`.

25. **`paymentLinks()->update()` only moves the status.** `status`
    (open|closed|expired) is required — omitting it fails with
    `422 {"status": ["Status is required"]}` — and a `title` sent alongside it
    is accepted but ignored. Treat everything else as write-once at creation.

26. **`virtualAccounts()->update()` needs `status` *and* the amount fields.**
    Sending only `status` fails with `422 {"amount": ["Amount is required"]}`;
    both together succeed.

29. **The SDK now uses the v2 Payment Link API for create/show/update.**
    `merchant-api.json` documents `POST /api/v2.0/payment-link/{account_id}`
    (create), `GET /api/v2.0/payment-link/{link_id}` (show, no account id) and
    `PUT /api/v2.0/payment-link/update/{link_id}` (update). All three verified
    working in sandbox 2026-08-21; created links record `source: "api v2"`.
    It is materially better than v1: `payment_link_type: total|items` removes
    the need to synthesise an `items` array just to satisfy `total_amount`,
    `expired_at` takes an ordinary date string instead of 13-digit
    milliseconds, and the update accepts partial fields where v1 requires
    `status` and silently ignores everything else. `list()`, `delete()` and
    `paymentMethods()` stay on v1 — v2 has no equivalent.

31. **The six previously-untouched endpoints, now exercised.**
    `accounts()->create()` and `accounts()->delete()` both return 200 — and
    `DELETE /api/v1.0/accounts/{id}` works despite being absent from
    `merchant-api.json`. `accountTransfer()->transfer()` succeeds and actually
    moves balance between sub-accounts (`merchant_ref_no` — one `f` — is
    echoed back). `cardlessWithdrawal()->cancel()` answers SP009 for an
    unknown reference. `directDebit()->verifyOtp()` requires either
    `transaction_id` or `binding_id` + `unbind_context`, and `unbind_context`
    must be an **array** — a boolean is rejected. `triggerPaymentCredit()`
    validates that `amount` equals the amount encoded in `qr_data`
    (`amount inside 'qr_data' and 'amount' request param does not match`).

33. **SP403 means you are calling with the wrong credential for that
    account.** The dashboard's Credential Details page has an *Assigned
    Accounts* list: every account named there is served only by that Specific
    credential, and the merchant-wide Default credential is refused for it
    with "This account requires its own credential. Please use the
    account-specific API key." Switching to the Specific credential that owns
    the account makes `checkFee` and `ewalletMoneyOut()->triggerTopup()`
    succeed immediately — verified 2026-08-21.

    Accounts that are *not yet assigned to any integration* stay reachable
    with the Default credential, which is why disbursement worked from a
    freshly created sub-account while failing on the assigned one. So the
    rule is simply: use the credential that owns the account.

    Two consequences worth knowing. Webhook URLs are configured **per
    credential** ("Applies to all accounts sharing this credential"), so
    switching credentials switches webhook configuration with it. The
    per-credential *Static IP Address* box can be left empty without breaking
    calls — IP whitelisting is enforced merchant-wide.

34. **`disbursement()->transfer()` wants `bank_code`, `checkFee()` wants
    `bank_swift_code`.** Sending only a swift code to transfer fails with
    `SP018 'bank_code': 'The bank code field is required.'` The asymmetry is
    real and easy to trip over.

35. **Direct debit is not live.** SingaPay's own documentation navigation
    labels the group "Direct Debit (SOON)". Bindings can be created but never
    reach `ACTIVE` — no sandbox test card is published by SingaPay, BRI or
    Ayoconnect — so charge, verify-otp, unbind and get-transaction cannot be
    verified end to end. Note the binding webview asks for the expiry as
    **MM/YY** while the card API demands **YYMM**.

36. **Cardless withdrawal `create()` cannot be exercised, and the gateway
    makes it impossible to tell why.** Re-diagnosed 2026-08-21 with 26
    `vendor_code` candidates across both credentials — every one answered
    HTTP 500.

    The SDK is demonstrably not at fault: the gateway accepts the signature,
    parses the body and **runs its validator**. An empty body, a missing
    `vendor_code` and an amount that is not a multiple of 50,000 each answer
    `SP018 Validation error`. Auth, signing and body shape are therefore all
    correct.

    The decisive detail is that `vendor_code` is **required but not validated
    against any list**. A deliberately absurd value
    (`DEFINITELY_NOT_A_VENDOR`) produces exactly the same HTTP 500 as every
    plausible one, so from outside, "wrong code" and "vendor integration not
    provisioned" are indistinguishable. No vendor catalogue endpoint exists
    either (eight candidate paths, all 404). Codes tried include
    `CLWD_{BANK}`, bare bank names, the `{TYPE}_{BRAND}_{PROVIDER}` shape
    borrowed from the retail codes (`CLWD_BRI_LINKQU`), switch operators
    (ALTO/ARTAJASA/JALIN), case variants and numeric bank codes.

    **Settled by SingaPay's own published docs** (read 2026-08-21), which
    remove the last doubt:

    - Their success example uses `vendor_code: CLWD_BRI` — exactly the
      format we sent. The code was never wrong.
    - The documented response when no vendor is provisioned is
      **`SP011 Beneficiary Vendor Not Active`** (HTTP 400), and the show
      endpoint even documents a **503** for "the Cardless Withdrawal feature
      is not available yet in the current environment". The gateway sends
      neither; it sends a bare 500.
    - Replaying their example payload verbatim — `CLWD_BRI`, amount 500,000,
      `customer_id: CUST-00123`, `customer_name: Budi Santoso` — still 500s.

    So the product is simply not provisioned in this sandbox, and `create()`
    answers an unhandled 500 instead of the SP011 or 503 its own spec
    promises. **Worth reporting to SingaPay**, together with the fact that an
    unrecognised `vendor_code` should be a 422.

    One more documentation defect: the overview's flow diagram shows
    `POST /cardless-withdrawals/{account_id}` taking `bank_type` and
    `cust_id`. That route does not exist (404) — the OpenAPI spec is right
    (`/create`, `vendor_code`, `customer_id`, `account_id` in the body) and
    the diagram is wrong. The SDK follows the spec.

55. **Cardless withdrawal contract reconciled against the official docs**
    without a working endpoint (2026-08-21). Everything the SDK sends
    matches, and the enums already cover the documented outcomes
    (`TransactionStatus::Initiated` = `01`, plus SP003/SP004/SP011/SP020).
    Facts now in the docblocks that appear nowhere else:

    - **Create and show are two different contracts for one transaction.**
      Create answers the v2 envelope (`transaction_status`, `otp_number`,
      `gross_amount`/`fee`/`net_amount`); show and list answer a flat
      resource (`status`, `amount`, `fee`, `total_paid`, `otp_expired_at`).
    - **`balance_after` on the create response is always `"0"`** — not the
      post-debit balance. Read show or list for the real figure.
    - On show, `fee` is the platform margin alone and **excludes** the
      vendor/bank fee; `total_paid` includes both.
    - `status` on show is lowercase and includes `refunded` and `canceled`;
      the initial value is `open`, despite the platform enum calling that
      state `pending`.
    - A failed or expired withdrawal reverses the **merchant** balance
      automatically; any customer-side balance is the merchant's to refund.
    - `create()` is rate-limited.

    The read side of the product is entirely healthy: `list()` returns `[]`,
    `find()` answers a proper 404, and `cancel()` answers SP009. Only
    `create()` is blocked. Note the dashboard's Cardless Withdrawals
    simulator only offers "Manually Set CLWD Success" (Customer ID + OTP) —
    it marks an existing withdrawal as successful and has no create form, so
    it presupposes the very API call that cannot be made.

37. **QRIS issuer credit is unprovisioned.** `triggerPaymentCredit` answers
    SP019 even when paying a QR generated on a *different* sub-account, so it
    is not the "cannot pay your own QR" case. `inquireStatus` is fully
    accepted (SP009 for a reference that does not exist) — note its second
    argument is `scope` (`issuer`|`acquirer`), not an account id.

39. **Sandbox disbursement outcomes are chosen by the account-number
    prefix — but not the way the dashboard says.** The *New Transaction*
    modal claims `1000`/`1001`/`1002`/`1003` settle SUCCESS and
    `1004`/`1006`/`1007`/`4000` settle FAILED. Measured against the gateway
    on 2026-08-21 (14 transfers, every outcome read from its genuine
    `disbursement` webhook), the hint is wrong in **both** directions, and
    each failing prefix simulates a *distinct* upstream error:

    | Prefix | Outcome | `failed_reason` |
    |---|---|---|
    | `1000` | Success (3/3) | — |
    | `1001` | **Failed** (3/3) | `ACCOUNT-Bad Request` |
    | `1002` | Success | — |
    | `1003` | **Failed** (2/2) | `ACCOUNT-Insufficient Funds` |
    | `1004` | **Failed** (2/2) | `ACCOUNT-INTERNAL_SERVER_ERROR` |
    | `1005` | **Failed** | `ACCOUNT-Invalid Account` (worded `Invalid beneficiary account`, not `Account validation failed`) |
    | `1006`, `1007`, `1008`, `4000` | Success | — |

    The remaining digits are free as long as the total length matches the
    bank's; the same prefix with three different tails gave the same outcome
    every time, so the selector really is the prefix.

    **Failures resolve far more slowly than successes.** A success settles in
    seconds, while `1004` and `1005` took seven to ten minutes and read
    `Pending` the whole way. An arbitrary number outside the table
    (`123456789012`) stays `Pending` indefinitely. So `Pending` never means
    "failed" — it means "not yet", and `inquireStatus()` (or the webhook) is
    mandatory in sandbox exactly as in production. `checkBeneficiary()`
    accepts these numbers and returns a deterministic fake holder name per
    number.

    One oddity, and it is consistent rather than random: the `1004` scenario
    reports a *different bank* than the one requested — a `bank_code: "002"`
    (BRI) transfer failed with
    `Account validation failed [Bank: PERMATA, ...]` on both attempts, while
    the payload's own `bank.name` said BRI. Trust `bank.code`; the prose in
    `failed_reason` belongs to the simulated scenario, not to your request.

40. **`PaymentMethod` is not SingaPay's payment-method catalogue.** The enum's
    four cases are SDK charge builders; the catalogue is the ~20 codes from
    `paymentLinks()->paymentMethods()` used in `whitelisted_payment_method`.
    That list is per-merchant and grows as SingaPay adds channels, so it is
    deliberately read from the gateway rather than frozen into an enum. Cards
    stay out of `pay()` on purpose — the convenience API should not be the
    thing that puts a server into PCI-DSS scope — as do retail outlets (no
    endpoint of their own) and direct debit (bind-then-charge, unreleased).

38. **Subscription proration, verified.** An upgrade on an `active` plan
    returns `upgrade.prorated_charge` with a `bill_id`, the difference, a
    `status` and a `payment_link_url`. While that charge is `pending` any
    further update is refused with `409 Plan cannot be updated in its current
    state`. `payment_type` stays `null` even after the card is linked.

43. **KYC `code: SUCCESS` does not mean the name matched.** The e-wallet
    verify endpoint answers `SUCCESS` whenever the lookup *ran*, including
    when there is no account for the number — and bills `PAID` for it either
    way. The real outcome is in `data.status` (`found with kyc` /
    `found without kyc` / `not found`) and `data.suggestion`
    (`pass`/`review`/`reject`). `found without kyc` returns `similarity: 0`,
    which means "no name to compare", not "names differ". `message` is
    always the literal `OK`. Anyone treating `successful()` as "safe to pay
    this person" would pay out to unverified accounts.

42. **The identity (KYC) service uses a fifth envelope shape**, and the SDK
    was misreading it. Responses are
    `{"code": "SUCCESS", "data": {...}, "message": "OK", "request_id": "...",
    "pricing": "PAID"}` — no `success`, no `response_code`, no `command` — so
    they fell through to the flat branch, where `data` became the whole
    envelope and every `data('similarity')` style read quietly returned null.
    Detected now by `code` + `request_id`; `SUCCESS` is the only success
    code, against CLIENT_ERROR, UNAUTHORIZED, DUPLICATE_REFERENCE,
    INSUFFICIENT_BALANCE, SERVER_ERROR and INTERNAL_ERROR.

    From the same spec, and now in the endpoint docblock: verification is
    idempotent on `request_id` and billed once (bank re-runs after an
    upstream FAILED); every response carries `pricing` (PAID/FREE); the rate
    limit is 60 rps with `429` + `Retry-After`; a credential can hold its own
    IP allowlist rejecting others with `403 IP_NOT_ALLOWED`; and all auth
    failures return `401` regardless of cause so client-id existence does not
    leak. Credentials come from a separate **merchant KYC dashboard** and
    look like `kc_live_a3f2c4`.

41. **The identity host really does need its own credential pair.** Not an
    assumption — tested 2026-08-21 with three combinations of the payment
    credentials (client_id + client_secret, API key as client_id, client_id +
    HMAC key). All three answered `401 invalid credential or signature`. The
    endpoint itself is reachable and accepts the request shape, so what is
    missing is genuinely `SINGAPAY_IDENTITY_CLIENT_ID` /
    `SINGAPAY_IDENTITY_CLIENT_SECRET`, issued separately by SingaPay.

32. **KYC contract verified against `swagger.json` without live credentials.**
    All three paths, both verify payloads
    (`request_id, account_number, name, bank_code` /
    `request_id, phone_number, name, ewallet_code`) and the auth scheme
    (hex HMAC-SHA256 of `{client_id}:{timestamp}` with an RFC 3339 timestamp)
    match the SDK exactly.

30. **Spec-vs-SDK sweep** (`merchant-api.json`, 71 operations). Besides the v2
    payment link group, the spec also carries v1 alternatives the SDK does not
    expose — `POST /api/v1.0/disbursement/{account_id}/transfer` (takes
    `bank_swift_code`) and `POST /api/v1.0/disbursement/{account_id}/inquiry-status`
    — where the SDK uses the v2.0 signed equivalents. `check-fee` is confirmed
    to take `bank_swift_code` and a numeric `amount`.

27. **QRIS money-out `inquireMerchant` works and is correctly unguarded.**
    Called with a real `qr_data` string from a generated dynamic QRIS it
    returns 200 with the fully parsed EMV payload, while the money-out guard
    is off — it is an inquiry, not a transfer.

28. **Card `cancel()` cannot be exercised in sandbox.** Transactions created
    with the test PAN report `processing` and reach `success` before a
    follow-up call lands, so every cancel answers
    `SP012 Cannot cancel: transaction status is success`. The void path is
    unverified.

24. **Settlement, as observed in sandbox** (partially answers discrepancy 9):
    VA transactions settle with `settlement_method: AUTO BALANCE` and
    `settlement_flow_type: SEQUENTIAL`, marked "parallel auto-settle" with no
    `settle_eligible_at`. The gateway fee books as a **separate debit
    statement row** rather than being netted into the credit — a Rp88,000
    payment produces `+88,000 credit` and `-2,500 debit` against the same
    `transaction_id`. Production behaviour is still unconfirmed.
9. **Settlement schedule and rolling reserve are undocumented** — ask SingaPay
   directly before going to production.
10. **"Default" vs "Specific" credentials are undocumented.** The dashboard's
    Credential Details page offers merchant-wide Default credentials and named
    Specific credentials bound to particular sub-accounts (observed fields:
    Name, Accounts, Client Secret, API Key), but no docs page describes them.
    Treat Specific credentials as least-privilege keys: one per product, and
    make sure `SINGAPAY_ACCOUNT_ID` points at an account assigned to the
    credential in use, or expect 403/SP015. The dashboard also issues an
    "HMAC Validation Key" (rotatable) that the docs never mention — see
    `SINGAPAY_HMAC_KEY`.
