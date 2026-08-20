<?php

declare(strict_types=1);

use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PAYMENT_HOST = 'https://sandbox-payment-b2b.singapay.id';
const BILLER_HOST = 'https://sandbox-biller-b2b.singapay.id';
const IDENTITY_HOST = 'https://sandbox-apigw.singapay.id';
const ACC = TestCase::ACCOUNT_ID;

beforeEach(function (): void {
    config()->set('singapay.money_out.enabled', true);
    reloadSingaPay();

    Http::fake([
        ...tokenEndpointFixtures(),
        '*' => Http::response(['response_code' => 'SP000', 'response_message' => 'Successfully', 'data' => []]),
    ]);
});

/**
 * Every SDK method must hit the documented method + path, byte for byte.
 * The paths come from docs/endpoint-inventory.md.
 */
it('calls the documented endpoint', function (Closure $call, string $method, string $url): void {
    $call(app(SingaPay::class));

    Http::assertSent(
        fn (Request $request): bool => $request->method() === $method && $request->url() === $url,
    );
})->with([
    // Accounts
    'accounts list' => [fn (SingaPay $sp) => $sp->accounts()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/accounts'],
    'accounts create' => [fn (SingaPay $sp) => $sp->accounts()->create(['name' => 'Store']), 'POST', PAYMENT_HOST.'/api/v1.0/accounts'],
    'accounts find' => [fn (SingaPay $sp) => $sp->accounts()->find('01J0EXAMPLE'), 'GET', PAYMENT_HOST.'/api/v1.0/accounts/01J0EXAMPLE'],
    'accounts update' => [fn (SingaPay $sp) => $sp->accounts()->update('01J0EXAMPLE', ['name' => 'New']), 'PATCH', PAYMENT_HOST.'/api/v1.0/accounts/update/01J0EXAMPLE'],
    // update-status/{id} does not exist on the gateway; updateStatus() goes through update/{id}.
    'accounts update status' => [fn (SingaPay $sp) => $sp->accounts()->updateStatus('01J0EXAMPLE', 'inactive'), 'PATCH', PAYMENT_HOST.'/api/v1.0/accounts/update/01J0EXAMPLE'],
    'accounts delete' => [fn (SingaPay $sp) => $sp->accounts()->delete('01J0EXAMPLE'), 'DELETE', PAYMENT_HOST.'/api/v1.0/accounts/01J0EXAMPLE'],

    // Balance
    'balance merchant' => [fn (SingaPay $sp) => $sp->balance()->merchant(), 'GET', PAYMENT_HOST.'/api/v1.0/balance-inquiry'],
    'balance account (default)' => [fn (SingaPay $sp) => $sp->balance()->account(), 'GET', PAYMENT_HOST.'/api/v1.0/balance-inquiry/'.ACC],

    // Account transfer
    'account transfer list' => [fn (SingaPay $sp) => $sp->accountTransfer()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/account-transfer/'.ACC],
    'account transfer find' => [fn (SingaPay $sp) => $sp->accountTransfer()->find('TX-1'), 'GET', PAYMENT_HOST.'/api/v1.0/account-transfer/'.ACC.'/TX-1'],
    'account transfer transfer' => [fn (SingaPay $sp) => $sp->accountTransfer()->transfer(['amount' => 1000, 'beneficiary_account_number' => '123456789012']), 'POST', PAYMENT_HOST.'/api/v1.0/account-transfer/'.ACC.'/transfer'],

    // Statements
    'statements list' => [fn (SingaPay $sp) => $sp->statements()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/statements/'.ACC],
    'statements find' => [fn (SingaPay $sp) => $sp->statements()->find('ST-1'), 'GET', PAYMENT_HOST.'/api/v1.0/statements/'.ACC.'/ST-1'],

    // Payment links
    'payment links list' => [fn (SingaPay $sp) => $sp->paymentLinks()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/payment-link-manage/'.ACC],
    'payment links methods' => [fn (SingaPay $sp) => $sp->paymentLinks()->paymentMethods(), 'GET', PAYMENT_HOST.'/api/v1.0/payment-link-manage/payment-methods'],
    'payment links create' => [fn (SingaPay $sp) => $sp->paymentLinks()->create(['reff_no' => 'INV-1']), 'POST', PAYMENT_HOST.'/api/v1.0/payment-link-manage/'.ACC],
    'payment links find' => [fn (SingaPay $sp) => $sp->paymentLinks()->find(103), 'GET', PAYMENT_HOST.'/api/v1.0/payment-link-manage/'.ACC.'/103'],
    'payment links update' => [fn (SingaPay $sp) => $sp->paymentLinks()->update(103, ['status' => 'open', 'max_usage' => 5]), 'PUT', PAYMENT_HOST.'/api/v1.0/payment-link-manage/'.ACC.'/103'],
    'payment links delete' => [fn (SingaPay $sp) => $sp->paymentLinks()->delete(103), 'DELETE', PAYMENT_HOST.'/api/v1.0/payment-link-manage/'.ACC.'/103'],

    // Payment link histories
    'histories list' => [fn (SingaPay $sp) => $sp->paymentLinkHistories()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/payment-link-histories/'.ACC],
    'histories find' => [fn (SingaPay $sp) => $sp->paymentLinkHistories()->find(85), 'GET', PAYMENT_HOST.'/api/v1.0/payment-link-histories/'.ACC.'/85'],

    // Virtual accounts
    'va list' => [fn (SingaPay $sp) => $sp->virtualAccounts()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/virtual-accounts/'.ACC],
    'va create' => [fn (SingaPay $sp) => $sp->virtualAccounts()->create(['bank_code' => 'BRI', 'kind' => 'permanent']), 'POST', PAYMENT_HOST.'/api/v1.0/virtual-accounts/'.ACC],
    'va find' => [fn (SingaPay $sp) => $sp->virtualAccounts()->find('01JVAEXAMPLE'), 'GET', PAYMENT_HOST.'/api/v1.0/virtual-accounts/'.ACC.'/01JVAEXAMPLE'],
    'va update' => [fn (SingaPay $sp) => $sp->virtualAccounts()->update('01JVAEXAMPLE', ['status' => 'active']), 'PUT', PAYMENT_HOST.'/api/v1.0/virtual-accounts/'.ACC.'/01JVAEXAMPLE'],
    'va delete' => [fn (SingaPay $sp) => $sp->virtualAccounts()->delete('01JVAEXAMPLE'), 'DELETE', PAYMENT_HOST.'/api/v1.0/virtual-accounts/'.ACC.'/01JVAEXAMPLE'],

    // VA transactions
    'va tx list' => [fn (SingaPay $sp) => $sp->vaTransactions()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/va-transactions/'.ACC],
    'va tx list filtered' => [fn (SingaPay $sp) => $sp->vaTransactions()->list(filters: ['status' => 'paid']), 'GET', PAYMENT_HOST.'/api/v1.0/va-transactions/'.ACC.'?status=paid'],
    'va tx find' => [fn (SingaPay $sp) => $sp->vaTransactions()->find('VA-20251024-0001'), 'GET', PAYMENT_HOST.'/api/v1.0/va-transactions/'.ACC.'/VA-20251024-0001'],
    'va tx by number' => [fn (SingaPay $sp) => $sp->vaTransactions()->listByVaNumber('88810012345678'), 'GET', PAYMENT_HOST.'/api/v1.0/va-transactions/'.ACC.'/detail-by-va-number/88810012345678'],

    // QRIS money-in
    'qris generate' => [fn (SingaPay $sp) => $sp->qris()->generate(['amount' => 10000]), 'POST', PAYMENT_HOST.'/api/v1.0/qris-dynamic/'.ACC.'/generate-qr'],
    'qris list' => [fn (SingaPay $sp) => $sp->qris()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/qris-dynamic/'.ACC],
    'qris find' => [fn (SingaPay $sp) => $sp->qris()->find(103), 'GET', PAYMENT_HOST.'/api/v1.0/qris-dynamic/'.ACC.'/show/103'],

    // E-wallet money-in
    'ewallet checkout v1' => [fn (SingaPay $sp) => $sp->ewallet()->createCheckout(['amount' => 10000, 'ewallet_vendor' => 'EWALLET_DANA']), 'POST', PAYMENT_HOST.'/api/v1.0/ewallet-native/'.ACC.'/create-checkout'],
    'ewallet order v2' => [fn (SingaPay $sp) => $sp->ewallet()->createOrder(['amount' => 10000, 'ewallet_vendor' => 'EWALLET_DANA']), 'POST', PAYMENT_HOST.'/api/v2.0/ewallet-native/create-order'],
    'ewallet tx list' => [fn (SingaPay $sp) => $sp->ewallet()->listTransactions(), 'GET', PAYMENT_HOST.'/api/v1.0/ewallet-native-transactions/'.ACC],
    'ewallet tx find' => [fn (SingaPay $sp) => $sp->ewallet()->findTransaction(2001), 'GET', PAYMENT_HOST.'/api/v1.0/ewallet-native-transactions/'.ACC.'/2001'],
    'ewallet inquiry' => [fn (SingaPay $sp) => $sp->ewallet()->inquireStatus(2001), 'GET', PAYMENT_HOST.'/api/v1.0/ewallet-native/'.ACC.'/inquiry-status/2001'],

    // Card
    'card payment' => [fn (SingaPay $sp) => $sp->card()->payment(['amount' => 100000]), 'POST', PAYMENT_HOST.'/api/v2.0/card/'.ACC.'/payment'],
    'card cancel' => [fn (SingaPay $sp) => $sp->card()->cancel('55'), 'PATCH', PAYMENT_HOST.'/api/v2.0/card/'.ACC.'/cancel/55'],
    'card inquiry' => [fn (SingaPay $sp) => $sp->card()->inquireStatus('55'), 'GET', PAYMENT_HOST.'/api/v2.0/card/'.ACC.'/inquiry-status/55'],

    // Subscriptions
    'subscription create' => [fn (SingaPay $sp) => $sp->subscriptions()->createPlan(['name' => 'Pro']), 'POST', PAYMENT_HOST.'/api/v2.0/recurring/plans'],
    'subscription find' => [fn (SingaPay $sp) => $sp->subscriptions()->findPlan('9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f'), 'GET', PAYMENT_HOST.'/api/v2.0/recurring/plans/9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f'],
    'subscription update' => [fn (SingaPay $sp) => $sp->subscriptions()->updatePlan('9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f', ['name' => 'Pro+']), 'PATCH', PAYMENT_HOST.'/api/v2.0/recurring/plans/9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f'],
    'subscription cancel' => [fn (SingaPay $sp) => $sp->subscriptions()->cancelPlan('9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f', 'churn'), 'POST', PAYMENT_HOST.'/api/v2.0/recurring/plans/cancel/9f8b6c2e-1a2b-4c3d-8e4f-5a6b7c8d9e0f'],

    // Direct debit
    'dd bind' => [fn (SingaPay $sp) => $sp->directDebit()->bindCard(['customer_ref' => 'cust-9001', 'phone_no' => '+628123456789']), 'POST', PAYMENT_HOST.'/api/v2.0/direct-debit/binding'],
    'dd binding status' => [fn (SingaPay $sp) => $sp->directDebit()->bindingStatus('9a1c5b3e-2d4f-4d8c-93cf-9a1c5b3e2d4f'), 'GET', PAYMENT_HOST.'/api/v2.0/direct-debit/binding/9a1c5b3e-2d4f-4d8c-93cf-9a1c5b3e2d4f'],
    'dd unbind' => [fn (SingaPay $sp) => $sp->directDebit()->unbindCard('9a1c5b3e-2d4f-4d8c-93cf-9a1c5b3e2d4f'), 'POST', PAYMENT_HOST.'/api/v2.0/direct-debit/binding/9a1c5b3e-2d4f-4d8c-93cf-9a1c5b3e2d4f/unbind'],
    'dd charge' => [fn (SingaPay $sp) => $sp->directDebit()->charge(['binding_id' => 'b', 'merchant_reference' => 'INV-1', 'amount' => 10000]), 'POST', PAYMENT_HOST.'/api/v2.0/direct-debit/charge'],
    'dd verify otp' => [fn (SingaPay $sp) => $sp->directDebit()->verifyOtp(['otp' => '123456', 'transaction_id' => 'tx']), 'POST', PAYMENT_HOST.'/api/v2.0/direct-debit/verify-otp'],
    'dd find tx' => [fn (SingaPay $sp) => $sp->directDebit()->findTransaction('7c2e1a4b-9d6f-4e3a-8b1c-2d4f9a1c5b3e'), 'GET', PAYMENT_HOST.'/api/v2.0/direct-debit/transaction/7c2e1a4b-9d6f-4e3a-8b1c-2d4f9a1c5b3e'],

    // Disbursement
    'disbursement list' => [fn (SingaPay $sp) => $sp->disbursement()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/disbursement/'.ACC],
    'disbursement find' => [fn (SingaPay $sp) => $sp->disbursement()->find('TX-9'), 'GET', PAYMENT_HOST.'/api/v1.0/disbursement/'.ACC.'/TX-9'],
    'disbursement check fee' => [fn (SingaPay $sp) => $sp->disbursement()->checkFee(['bank_swift_code' => 'BRINIDJA', 'amount' => 50000]), 'POST', PAYMENT_HOST.'/api/v1.0/disbursement/'.ACC.'/check-fee'],
    'disbursement check beneficiary' => [fn (SingaPay $sp) => $sp->disbursement()->checkBeneficiary(['bank_code' => '014', 'bank_account_number' => '123']), 'GET', PAYMENT_HOST.'/api/v1.0/disbursement/'.ACC.'/check-beneficiary?bank_code=014&bank_account_number=123'],
    'disbursement transfer' => [fn (SingaPay $sp) => $sp->disbursement()->transfer(['reference_number' => 'R', 'bank_code' => '014', 'bank_account_number' => '123', 'amount' => 1000]), 'POST', PAYMENT_HOST.'/api/v2.0/disbursement/transfer'],
    'disbursement inquiry' => [fn (SingaPay $sp) => $sp->disbursement()->inquireStatus('REF-1'), 'POST', PAYMENT_HOST.'/api/v2.0/disbursement/'.ACC.'/inquiry-status'],

    // QRIS money-out
    'qris out inquiry merchant' => [fn (SingaPay $sp) => $sp->qrisMoneyOut()->inquireMerchant('000201'), 'POST', PAYMENT_HOST.'/api/v2.0/qris/issuer/mpm/inquiry-merchant'],
    'qris out payment credit' => [fn (SingaPay $sp) => $sp->qrisMoneyOut()->triggerPaymentCredit(['reference_number' => 'R', 'amount' => 10000, 'qr_data' => 'q', 'customer_name' => 'n']), 'POST', PAYMENT_HOST.'/api/v2.0/qris/issuer/mpm/payment-credit'],
    'qris out inquiry status' => [fn (SingaPay $sp) => $sp->qrisMoneyOut()->inquireStatus('REF-1'), 'POST', PAYMENT_HOST.'/api/v2.0/qris/status/'.ACC],

    // E-wallet money-out
    'ewallet out inquiry account' => [fn (SingaPay $sp) => $sp->ewalletMoneyOut()->inquireAccount(['ewallet_code' => 'DANA', 'customer_number' => '085733347341', 'amount' => ['value' => '10000.00', 'currency' => 'IDR']]), 'POST', PAYMENT_HOST.'/api/v2.0/ewallet/account-inquiry'],
    'ewallet out topup' => [fn (SingaPay $sp) => $sp->ewalletMoneyOut()->triggerTopup(['reference_number' => 'R', 'ewallet_code' => 'DANA', 'customer_number' => '085733347341', 'amount' => ['value' => '10000.00', 'currency' => 'IDR']]), 'POST', PAYMENT_HOST.'/api/v2.0/ewallet/trigger-topup'],
    'ewallet out inquiry status' => [fn (SingaPay $sp) => $sp->ewalletMoneyOut()->inquireStatus('REF-1'), 'POST', PAYMENT_HOST.'/api/v2.0/ewallet/'.ACC.'/inquiry-status'],

    // Cardless withdrawal
    'cardless create' => [fn (SingaPay $sp) => $sp->cardlessWithdrawal()->create(['reference_number' => 'R', 'customer_name' => 'n', 'customer_id' => 'c', 'amount' => 50000, 'vendor_code' => 'CLWD_BRI']), 'POST', PAYMENT_HOST.'/api/v1.0/cardless-withdrawals/create'],
    'cardless list' => [fn (SingaPay $sp) => $sp->cardlessWithdrawal()->list(), 'GET', PAYMENT_HOST.'/api/v1.0/cardless-withdrawals/transaction/'.ACC],
    'cardless find' => [fn (SingaPay $sp) => $sp->cardlessWithdrawal()->find('REF-1'), 'GET', PAYMENT_HOST.'/api/v1.0/cardless-withdrawals/transaction/'.ACC.'/REF-1'],
    'cardless cancel' => [fn (SingaPay $sp) => $sp->cardlessWithdrawal()->cancel('REF-1', 'customer request'), 'POST', PAYMENT_HOST.'/api/v1.0/cardless-withdrawals/cancel'],

    // Biller (separate host)
    'biller balance' => [fn (SingaPay $sp) => $sp->biller()->checkBalance(), 'POST', BILLER_HOST.'/api/v1/check-balance'],
    'biller detail' => [fn (SingaPay $sp) => $sp->biller()->transactionDetail('TX-1'), 'POST', BILLER_HOST.'/api/v1/detail-bill-transaction'],
    'biller list' => [fn (SingaPay $sp) => $sp->biller()->listTransactions(['status' => 'success']), 'POST', BILLER_HOST.'/api/v1/list-bill-transaction'],
    'biller reset customer' => [fn (SingaPay $sp) => $sp->biller()->resetCustomerId(), 'POST', BILLER_HOST.'/api/v1/reset-customer-id'],
    'biller game params' => [fn (SingaPay $sp) => $sp->biller()->gameTopupParameters('topupg'), 'POST', BILLER_HOST.'/api/v2/prepaid/get-parameter-game-topup'],
    'biller prepaid inquiry' => [fn (SingaPay $sp) => $sp->biller()->prepaidInquiry('plntok', ['customer_id' => 'c', 'product_code' => 'p']), 'POST', BILLER_HOST.'/api/v2/prepaid/inquiry'],
    'biller prepaid payment' => [fn (SingaPay $sp) => $sp->biller()->prepaidPayment('pulsa', ['customer_id' => 'c', 'product_code' => 'p', 'password' => 'x']), 'POST', BILLER_HOST.'/api/v2/prepaid/payment'],
    'biller postpaid inquiry' => [fn (SingaPay $sp) => $sp->biller()->postpaidInquiry('pdam', ['customer_id' => 'c', 'product_code' => 'p']), 'POST', BILLER_HOST.'/api/v2/postpaid/inquiry'],
    'biller postpaid payment' => [fn (SingaPay $sp) => $sp->biller()->postpaidPayment('pdam', ['customer_id' => 'c', 'product_code' => 'p', 'password' => 'x', 'reference_number' => 'r']), 'POST', BILLER_HOST.'/api/v2/postpaid/payment'],
    'biller legacy prepaid inquiry' => [fn (SingaPay $sp) => $sp->biller()->legacyPrepaidInquiry(['customer_id' => 'c', 'product_code' => 'p']), 'POST', BILLER_HOST.'/api/v1/prepaid/inquiry'],
    'biller legacy prepaid payment' => [fn (SingaPay $sp) => $sp->biller()->legacyPrepaidPayment('pulsa', ['customer_id' => 'c']), 'POST', BILLER_HOST.'/api/v1/prepaid/payment'],
    'biller legacy postpaid inquiry' => [fn (SingaPay $sp) => $sp->biller()->legacyPostpaidInquiry('pdam', ['customer_id' => 'c']), 'POST', BILLER_HOST.'/api/v1/postpaid/inquiry'],
    'biller legacy postpaid payment' => [fn (SingaPay $sp) => $sp->biller()->legacyPostpaidPayment('pdam', ['customer_id' => 'c']), 'POST', BILLER_HOST.'/api/v1/postpaid/payment'],

    // Identity verification (separate host + credentials)
    'kyc bank verify' => [fn (SingaPay $sp) => $sp->identity()->verifyBankAccount(['request_id' => 'r', 'account_number' => '123456', 'bank_code' => '014', 'name' => 'Budi']), 'POST', IDENTITY_HOST.'/api/v1/kyc/bank/verify'],
    'kyc ewallet verify' => [fn (SingaPay $sp) => $sp->identity()->verifyEwalletAccount(['request_id' => 'r', 'phone_number' => '08123456789', 'name' => 'Budi', 'ewallet_code' => 'DANA']), 'POST', IDENTITY_HOST.'/api/v1/kyc/ewallet/verify'],
]);

it('injects the default account id into v2 bodies that carry it', function (): void {
    app(SingaPay::class)->ewallet()->createOrder(['amount' => 10000, 'ewallet_vendor' => 'EWALLET_DANA']);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/api/v2.0/ewallet-native/create-order')
            && $request->data()['account_id'] === ACC;
    });
});

it('keeps an explicitly provided account id in v2 bodies', function (): void {
    app(SingaPay::class)->disbursement()->transfer([
        'account_id' => '01JOTHERACCOUNT',
        'reference_number' => 'R',
        'bank_code' => '014',
        'bank_account_number' => '123',
        'amount' => 1000,
    ]);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/api/v2.0/disbursement/transfer')
            && json_decode($request->body(), true)['account_id'] === '01JOTHERACCOUNT';
    });
});

it('wraps biller calls in the command envelope', function (): void {
    app(SingaPay::class)->biller()->transactionDetail('TX-1');

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/api/v1/detail-bill-transaction')
            && $request->data() === ['command' => 'detail-bill-transaction', 'data' => ['transaction_id' => 'TX-1']];
    });
});

it('authenticates identity calls with the KYC token and no partner header', function (): void {
    app(SingaPay::class)->identity()->verifyBankAccount(['request_id' => 'r', 'account_number' => '123456', 'bank_code' => '014', 'name' => 'Budi']);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/api/v1/kyc/bank/verify')
            && $request->header('Authorization') === ['Bearer kyc-access-token']
            && ! $request->hasHeader('X-PARTNER-ID');
    });
});

it('signs exactly the six documented money-out endpoints', function (): void {
    $sp = app(SingaPay::class);

    $sp->disbursement()->transfer(['reference_number' => 'R', 'bank_code' => '014', 'bank_account_number' => '1', 'amount' => 1]);
    $sp->qrisMoneyOut()->triggerPaymentCredit(['reference_number' => 'R', 'amount' => 1000, 'qr_data' => 'q', 'customer_name' => 'n']);
    $sp->ewalletMoneyOut()->triggerTopup(['reference_number' => 'R', 'ewallet_code' => 'DANA', 'customer_number' => '0812345678', 'amount' => ['value' => '10000.00', 'currency' => 'IDR']]);
    $sp->accountTransfer()->transfer(['amount' => 1, 'beneficiary_account_number' => '123456789012']);
    $sp->cardlessWithdrawal()->create(['reference_number' => 'R', 'customer_name' => 'n', 'customer_id' => 'c', 'amount' => 50000, 'vendor_code' => 'CLWD_BRI']);
    $sp->directDebit()->charge(['binding_id' => 'b', 'merchant_reference' => 'M', 'amount' => 10000]);

    // And one unsigned call for contrast.
    $sp->disbursement()->inquireStatus('REF-1');

    // The v1.1 token request also carries X-Signature (scheme A) — exclude it;
    // this test is about the scheme-C request signature.
    $signed = collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->hasHeader('X-Signature') && ! str_contains($pair[0]->url(), 'access-token'))
        ->map(fn (array $pair): string => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->values()
        ->all();

    expect($signed)->toBe([
        '/api/v2.0/disbursement/transfer',
        '/api/v2.0/qris/issuer/mpm/payment-credit',
        '/api/v2.0/ewallet/trigger-topup',
        '/api/v1.0/account-transfer/'.ACC.'/transfer',
        '/api/v1.0/cardless-withdrawals/create',
        '/api/v2.0/direct-debit/charge',
    ]);
});
