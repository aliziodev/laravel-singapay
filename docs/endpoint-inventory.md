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
| PATCH | `/api/v1.0/accounts/update-status/{id}` | `accounts()->updateStatus()` |
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
| POST | `/api/v1.0/payment-link-manage/{account_id}` | `paymentLinks()->create()` |
| GET | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` | `paymentLinks()->find()` |
| PUT | `/api/v1.0/payment-link-manage/{account_id}/{payment_link_id}` | `paymentLinks()->update()` |
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
| POST | `/api/v1.0/disbursement/{account_id}/check-beneficiary` | `disbursement()->checkBeneficiary()` |
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
2. **`llms.txt` omits `check-beneficiary`** — referenced by the disbursement
   overview; path recovered (`POST /api/v1.0/disbursement/{account_id}/check-beneficiary`)
   but its body schema is publicly undocumented. Verify against sandbox.
3. **Account update**: docs say `PATCH /accounts/update/{id}` (name/status/
   invite_members); the OpenAPI spec says `PATCH /accounts/update-status/{id}`
   (status only). The SDK exposes both.
4. **QRIS money-out inquiry-status**: the overview claims it needs the request
   signature, the endpoint page says it does not. The SDK follows the endpoint
   page (unsigned); verify in sandbox before production.
5. **`X-Timestamp` format**: the canonical signature guide says Unix seconds;
   the direct-debit charge page mentions ISO-8601. The SDK sends Unix seconds
   everywhere (canonical guide + reference implementations).
6. **VA `bank_code` enum**: docs list 12 banks, the spec only 5. The SDK does
   not restrict the value. Probed against sandbox on 2026-08-21 — exactly nine
   are accepted: `BRI`, `BNI`, `BCA`, `MANDIRI`, `PERMATA`, `CIMB`, `DANAMON`,
   `BSI`, `MAYBANK`. `BTN`, `BJB`, `SINARMAS`, `MEGA` and `BUKOPIN` are
   rejected with HTTP 422 `bank_code: "The selected bank code is invalid."`
   Sandbox availability is not a promise about production — confirm with
   SingaPay before relying on the list.
7. **Token endpoint version**: docs use v1.1, the OpenAPI spec still
   references v1.0. Configurable via `auth_version` (default 1.1).
8. **Payment-link webhook has no `event` field** in its documented example,
   although shared-endpoints claims `payment-link-transaction`. The SDK
   discriminates by `event` first, then by payload shape
   (`data.transaction.type == "pl"` / `data.payment.method == "payment_link"`).
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
