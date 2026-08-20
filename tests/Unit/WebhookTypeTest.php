<?php

declare(strict_types=1);

use Aliziodev\Singapay\Enums\WebhookType;
use Aliziodev\Singapay\Events;

covers(WebhookType::class);

it('discriminates deliveries by the event field', function (string $event, WebhookType $expected): void {
    expect(WebhookType::fromPayload(['event' => $event]))->toBe($expected);
})->with([
    'va-transaction' => ['va-transaction', WebhookType::VirtualAccount],
    'qris-acquirer-transaction' => ['qris-acquirer-transaction', WebhookType::QrisAcquirer],
    'payment-link-transaction' => ['payment-link-transaction', WebhookType::PaymentLink],
    'ewallet-native-transaction' => ['ewallet-native-transaction', WebhookType::EwalletMoneyIn],
    'disbursement' => ['disbursement', WebhookType::Disbursement],
    'ewallet-topup' => ['ewallet-topup', WebhookType::EwalletTopup],
    'qris-issuer' => ['qris-issuer', WebhookType::QrisIssuer],
    'subscription payment success' => ['subscription.cycle.payment_success', WebhookType::SubscriptionCycle],
    'subscription payment failed' => ['subscription.cycle.payment_failed', WebhookType::SubscriptionCycle],
    'subscription status changed' => ['subscription.plan.status_changed', WebhookType::SubscriptionCycle],
    'settlement completed' => ['settlement.completed', WebhookType::Settlement],
    'settlement refunded' => ['settlement.refunded', WebhookType::Settlement],
    'payment link inquiry' => ['payment_link.inquiry', WebhookType::PaymentLinkInquiry],
    'payment link inquiry expired' => ['payment_link.inquiry.expired', WebhookType::PaymentLinkInquiry],
    'product expiration' => ['product-expiration', WebhookType::ProductExpiration],
    'transaction expiration' => ['transaction-expiration', WebhookType::TransactionExpiration],
    // The docs spell these two with underscores; the gateway sends hyphens.
    'product expiration (underscore spelling)' => ['product_expiration', WebhookType::ProductExpiration],
    'transaction expiration (underscore spelling)' => ['transaction_expiration', WebhookType::TransactionExpiration],
    'direct debit' => ['direct-debit-binding', WebhookType::DirectDebit],
]);

it('falls back to payload shape for payment-link deliveries without an event field', function (): void {
    expect(WebhookType::fromPayload([
        'status' => 200,
        'success' => true,
        'data' => ['transaction' => ['type' => 'pl'], 'payment' => ['method' => 'payment_link']],
    ]))->toBe(WebhookType::PaymentLink);

    expect(WebhookType::fromPayload([
        'data' => ['payment' => ['method' => 'payment_link']],
    ]))->toBe(WebhookType::PaymentLink);
});

it('returns null for unrecognized payloads instead of guessing', function (): void {
    expect(WebhookType::fromPayload(['event' => 'brand-new-webhook']))->toBeNull()
        ->and(WebhookType::fromPayload([]))->toBeNull()
        ->and(WebhookType::fromPayload(['data' => ['transaction' => ['type' => 'va']]]))->toBeNull();
});

it('builds the dedicated event class for every type', function (): void {
    foreach (WebhookType::cases() as $type) {
        $event = $type->makeEvent(['event' => 'x', 'data' => []]);

        expect($event)->toBeInstanceOf($type->eventClass())
            ->and($event)->toBeInstanceOf(Events\WebhookReceived::class)
            ->and($event->type)->toBe($type);
    }
});

it('exposes typed accessors on the money-out events', function (): void {
    $event = new Events\DisbursementProcessed([
        'response_code' => 'SP000',
        'event' => 'disbursement',
        'data' => [
            'transaction_id' => 'TX-9',
            'reference_number' => 'REF-9',
            'transaction_status' => ['code' => '00', 'desc' => 'Success'],
        ],
    ]);

    expect($event->transactionId())->toBe('TX-9')
        ->and($event->referenceNumber())->toBe('REF-9')
        ->and($event->isSuccessful())->toBeTrue();

    $failed = new Events\EwalletTopupProcessed([
        'data' => ['transaction_status' => ['code' => '06', 'desc' => 'Failed']],
    ]);

    expect($failed->isSuccessful())->toBeFalse()
        ->and($failed->transactionStatus()?->isTerminal())->toBeTrue();
});

it('exposes typed accessors on the money-in events', function (): void {
    $event = new Events\VirtualAccountPaid([
        'event' => 'va-transaction',
        'data' => [
            'transaction' => ['reff_no' => 'INV-1', 'transaction_id' => '321'],
            'payment' => ['additional_info' => ['va_number' => '7872955146576837']],
        ],
    ]);

    expect($event->reffNo())->toBe('INV-1')
        ->and($event->transactionId())->toBe('321')
        ->and($event->vaNumber())->toBe('7872955146576837')
        ->and($event->event())->toBe('va-transaction');
});

it('exposes batch accessors on expiration events', function (): void {
    $event = new Events\ProductsExpired([
        'event' => 'product_expiration',
        'data' => [
            'payment_links' => [['id' => 1], ['id' => 2]],
            'virtual_accounts' => [['id' => 3]],
            'qris_transactions' => [],
        ],
    ]);

    expect($event->paymentLinks())->toHaveCount(2)
        ->and($event->virtualAccounts())->toHaveCount(1)
        ->and($event->qrisTransactions())->toBe([]);
});

it('distinguishes subscription sub-events', function (): void {
    $failed = new Events\SubscriptionCycleProcessed([
        'event' => 'subscription.cycle.payment_failed',
        'data' => ['plan' => ['id' => 'P1'], 'bill' => ['bill_number' => 'B1']],
    ]);

    expect($failed->isPaymentFailed())->toBeTrue()
        ->and($failed->isPaymentSuccess())->toBeFalse()
        ->and($failed->planId())->toBe('P1')
        ->and($failed->billNumber())->toBe('B1');
});
