<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A batch of unpaid money-in transaction attempts expired
 * (webhook event `transaction_expiration`).
 *
 * Unlike {@see ProductsExpired}, the underlying products (e.g. the payment
 * link itself) remain active — only the individual attempts expired.
 */
class MoneyInTransactionsExpired extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::TransactionExpiration);
    }

    /**
     * Expired payment-link histories in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentLinkHistories(): array
    {
        return $this->listOf('payment_link_histories');
    }

    /**
     * Expired virtual-account transactions in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function virtualAccountTransactions(): array
    {
        return $this->listOf('virtual_account_transactions');
    }

    /**
     * Expired QRIS histories in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function qrisHistories(): array
    {
        return $this->listOf('qris_histories');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listOf(string $key): array
    {
        $items = $this->data($key, []);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
