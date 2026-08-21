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
Note it **reuses the key `response_code`** for a two-digit status that is not an
SP code, and puts the message under `response_text` rather than
`response_message`. `Response` detects it by the presence of `command` and
treats `"00"` as success — read as a v2 envelope, every successful biller call
would look like a failure. Observed codes: `00` success, `04` rejected format
error, `99` general failure. On `04` the per-field errors arrive as a flat map
inside `data` (`{"data.transaction_id": "The data.transaction id field is
required."}`), not under `data.errors`.

All 13 business paths in `openapi-biller.json` are implemented, and the v1 ones
are exposed only under explicit `legacy*` names — v2 is the default for prepaid
and postpaid. `check-balance`, `list-bill-transaction` and `reset-customer-id`
exist only as v1; there is no v2 of them.

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
    code back normalised (`ALFAMART` → `RETAIL_ALFAMART_LINKQU`).
7. **Token endpoint version**: docs use v1.1, the OpenAPI spec still
   references v1.0. Configurable via `auth_version` (default 1.1).
8. **Payment-link webhook has no `event` field** in its documented example,
   although shared-endpoints claims `payment-link-transaction`. The SDK
   discriminates by `event` first, then by payload shape
   (`data.transaction.type == "pl"` / `data.payment.method == "payment_link"`).

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

36. **Cardless withdrawal answers HTTP 500 for every input.** Eight different
    `vendor_code` values, all identical 500s, on a funded account with a valid
    multiple-of-50,000 amount. The spec itself says "contact support for the
    list of available vendor codes"; the product looks unprovisioned.

37. **QRIS issuer credit is unprovisioned.** `triggerPaymentCredit` answers
    SP019 even when paying a QR generated on a *different* sub-account, so it
    is not the "cannot pay your own QR" case. `inquireStatus` is fully
    accepted (SP009 for a reference that does not exist) — note its second
    argument is `scope` (`issuer`|`acquirer`), not an account id.

38. **Subscription proration, verified.** An upgrade on an `active` plan
    returns `upgrade.prorated_charge` with a `bill_id`, the difference, a
    `status` and a `payment_link_url`. While that charge is `pending` any
    further update is refused with `409 Plan cannot be updated in its current
    state`. `payment_type` stays `null` even after the card is linked.

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
