<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events;

covers(
    Events\WebhookReceived::class,
    Events\MoneyOutWebhook::class,
    Events\VirtualAccountPaid::class,
    Events\QrisAcquirerPaid::class,
    Events\PaymentLinkPaid::class,
    Events\EwalletPaid::class,
    Events\SubscriptionCycleProcessed::class,
    Events\DisbursementProcessed::class,
    Events\EwalletTopupProcessed::class,
    Events\QrisIssuerProcessed::class,
    Events\SettlementProcessed::class,
    Events\DirectDebitBindingUpdated::class,
    Events\PaymentLinkInquiryReceived::class,
    Events\ProductsExpired::class,
    Events\MoneyInTransactionsExpired::class,
);

it('exposes the raw event name and dot access into data', function (): void {
    $event = new Events\WebhookReceived(['event' => 'va-transaction', 'data' => ['a' => ['b' => 1]]], WebhookType::VirtualAccount);

    expect($event->event())->toBe('va-transaction')
        ->and($event->data())->toBe(['a' => ['b' => 1]])
        ->and($event->data('a.b'))->toBe(1)
        ->and($event->data('missing', 'fallback'))->toBe('fallback');
});

it('degrades gracefully when payload sections are malformed', function (): void {
    $event = new Events\WebhookReceived(['event' => 42, 'data' => 'not-an-array']);

    expect($event->event())->toBeNull()
        ->and($event->data('anything', 'default'))->toBe('default')
        ->and($event->type)->toBeNull();
});

it('returns null from every typed accessor when fields are absent', function (): void {
    expect(new Events\VirtualAccountPaid([]))
        ->reffNo()->toBeNull()
        ->transactionId()->toBeNull()
        ->vaNumber()->toBeNull();

    expect(new Events\QrisAcquirerPaid([]))
        ->reffNo()->toBeNull()
        ->merchantReffNo()->toBeNull();

    expect(new Events\PaymentLinkPaid([]))
        ->reffNo()->toBeNull()
        ->paymentLinkReffNo()->toBeNull()
        ->paymentLinkId()->toBeNull();

    expect(new Events\EwalletPaid([]))
        ->reffNo()->toBeNull()
        ->vendor()->toBeNull();

    expect(new Events\DisbursementProcessed([]))
        ->transactionId()->toBeNull()
        ->referenceNumber()->toBeNull()
        ->transactionStatus()->toBeNull()
        ->isSuccessful()->toBeFalse();

    expect(new Events\SettlementProcessed([]))->referenceNo()->toBeNull();
    expect(new Events\DirectDebitBindingUpdated([]))->bindingId()->toBeNull()->status()->toBeNull();
    expect(new Events\PaymentLinkInquiryReceived([]))->historyReffNo()->toBeNull()->isExpired()->toBeFalse();
    expect(new Events\SubscriptionCycleProcessed([]))->planId()->toBeNull()->billNumber()->toBeNull();
});

it('reads the payment link accessors from a realistic payload', function (): void {
    $event = new Events\PaymentLinkPaid([
        'data' => [
            'transaction' => ['reff_no' => 'TX-1', 'type' => 'pl'],
            'payment' => ['method' => 'payment_link', 'additional_info' => ['payment_link' => ['id' => 189, 'reff_no' => 'PL-1']]],
        ],
    ]);

    expect($event->reffNo())->toBe('TX-1')
        ->and($event->paymentLinkReffNo())->toBe('PL-1')
        ->and($event->paymentLinkId())->toBe(189);
});

it('reads e-wallet vendor from either documented location', function (): void {
    $fromTransaction = new Events\EwalletPaid(['data' => ['transaction' => ['ewallet_vendor' => 'GOPAY']]]);
    $fromPayment = new Events\EwalletPaid(['data' => ['payment' => ['vendor' => 'DANA']]]);

    expect($fromTransaction->vendor())->toBe('GOPAY')
        ->and($fromPayment->vendor())->toBe('DANA');
});

it('exposes settlement and inquiry sub-event checks', function (): void {
    $refunded = new Events\SettlementProcessed(['event' => 'settlement.refunded', 'data' => ['settlement' => ['reference_no' => 'S-1']]]);

    expect($refunded->isRefunded())->toBeTrue()
        ->and($refunded->isCompleted())->toBeFalse()
        ->and($refunded->isRefundCancelled())->toBeFalse()
        ->and($refunded->referenceNo())->toBe('S-1');

    $expired = new Events\PaymentLinkInquiryReceived(['event' => 'payment_link.inquiry.expired', 'data' => []]);

    expect($expired->isExpired())->toBeTrue();

    $changed = new Events\SubscriptionCycleProcessed(['event' => 'subscription.plan.status_changed', 'data' => []]);

    expect($changed->isStatusChange())->toBeTrue()
        ->and($changed->isPaymentSuccess())->toBeFalse();

    $success = new Events\SubscriptionCycleProcessed(['event' => 'subscription.cycle.payment_success', 'data' => []]);

    expect($success->isPaymentSuccess())->toBeTrue()
        ->and($success->isPaymentFailed())->toBeFalse()
        ->and($success->isStatusChange())->toBeFalse();
});

it('filters non-array entries out of expiration batches', function (): void {
    $products = new Events\ProductsExpired(['data' => [
        'payment_links' => [['id' => 1], 'garbage', ['id' => 2]],
        'virtual_accounts' => 'not-a-list',
    ]]);

    expect($products->paymentLinks())->toBe([['id' => 1], ['id' => 2]])
        ->and($products->virtualAccounts())->toBe([])
        ->and($products->qrisTransactions())->toBe([]);

    $transactions = new Events\MoneyInTransactionsExpired(['data' => [
        'payment_link_histories' => [['id' => 9]],
        'qris_histories' => [['id' => 3]],
    ]]);

    expect($transactions->paymentLinkHistories())->toHaveCount(1)
        ->and($transactions->virtualAccountTransactions())->toBe([])
        ->and($transactions->qrisHistories())->toHaveCount(1);
});

it('resolves money-out statuses on every family member', function (): void {
    $topup = new Events\EwalletTopupProcessed(['data' => ['transaction_status' => ['code' => '00']]]);
    $qris = new Events\QrisIssuerProcessed(['data' => ['transaction_status' => ['code' => '06']]]);

    expect($topup->isSuccessful())->toBeTrue()
        ->and($qris->isSuccessful())->toBeFalse()
        ->and($qris->transactionStatus()?->isTerminal())->toBeTrue();
});

it('surfaces the retail outlet a payment link was paid at', function (array $case): void {
    // Verbatim from the real deliveries captured 2026-08-21 — one per outlet.
    // Note payment_method_additional arrives as a JSON *string*.
    $inquiry = new Events\PaymentLinkInquiryReceived([
        'event' => 'payment-link-inquiry',
        'data' => [
            'payment_link_history' => [
                'reff_no' => $case['reff'],
                'status' => 'pending',
                'payment_method_name' => $case['name'],
                'payment_method_value' => $case['code'],
                'payment_method_additional' => $case['additional'],
            ],
        ],
    ]);

    expect($inquiry->retailCode())->toBe($case['retail_code'])
        ->and($inquiry->paymentMethodName())->toBe($case['name'])
        ->and($inquiry->paymentMethodValue())->toBe($case['code'])
        ->and($inquiry->paymentMethodAdditional()['partner_reff'])->toBe($case['partner_reff'])
        // Dot access into the raw field cannot work: it is a string.
        ->and($inquiry->data('payment_link_history.payment_method_additional.retail_code'))->toBeNull();

    // The paid delivery says nothing about retail, so the two are joined on
    // this reference — that is the only route to "paid at Alfamart".
    $paid = new Events\PaymentLinkPaid([
        'event' => 'payment-link-transaction',
        'data' => [
            'transaction' => ['reff_no' => $case['reff'], 'type' => 'pl', 'status' => 'paid'],
            'payment' => ['method' => 'payment_link'],
        ],
    ]);

    expect($paid->reffNo())->toBe($inquiry->historyReffNo())
        ->and($paid->data('payment.method'))->toBe('payment_link');
})->with([
    'alfamart' => [[
        'reff' => '23035417720260821223309470BHfLKZaH',
        'name' => 'Alfamart (Linkqu)',
        'code' => '211744000000012',
        'additional' => '{"retail_code":"ALFAMART","partner_reff":"20260821223309002303"}',
        'retail_code' => 'ALFAMART',
        'partner_reff' => '20260821223309002303',
    ]],
    'indomaret' => [[
        'reff' => '23045417720260821223830478rdQo9Z4D',
        'name' => 'Indomaret (Linkqu)',
        'code' => '111741000000012',
        'additional' => '{"retail_code":"INDOMARET","partner_reff":"20260821223830002304"}',
        'retail_code' => 'INDOMARET',
        'partner_reff' => '20260821223830002304',
    ]],
]);

it('reports no retail code for a card inquiry, whose additional field is null', function (): void {
    $card = new Events\PaymentLinkInquiryReceived([
        'data' => [
            'payment_link_history' => [
                'reff_no' => '22835417720260821080151402DqftJQZD',
                'payment_method_name' => 'Credit Card',
                // A card inquiry echoes the reff_no back as the "value".
                'payment_method_value' => '22835417720260821080151402DqftJQZD',
                'payment_method_additional' => null,
            ],
        ],
    ]);

    expect($card->retailCode())->toBeNull()
        ->and($card->paymentMethodAdditional())->toBe([])
        ->and($card->paymentMethodName())->toBe('Credit Card');
});

it('tolerates a decoded object or malformed json in the additional field', function (): void {
    $object = new Events\PaymentLinkInquiryReceived([
        'data' => ['payment_link_history' => ['payment_method_additional' => ['retail_code' => 'INDOMARET']]],
    ]);
    $broken = new Events\PaymentLinkInquiryReceived([
        'data' => ['payment_link_history' => ['payment_method_additional' => 'not json']],
    ]);

    expect($object->retailCode())->toBe('INDOMARET')
        ->and($broken->paymentMethodAdditional())->toBe([])
        ->and($broken->retailCode())->toBeNull();
});
