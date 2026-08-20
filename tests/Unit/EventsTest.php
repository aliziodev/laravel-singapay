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
