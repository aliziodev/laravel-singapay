<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Events;

use Aliziodev\Singapay\Enums\WebhookType;

/**
 * A batch of products (payment links, virtual accounts, QRIS) expired
 * (webhook event `product-expiration`).
 */
class ProductsExpired extends WebhookReceived
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload, WebhookType::ProductExpiration);
    }

    /**
     * Expired payment links in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentLinks(): array
    {
        return $this->listOf('payment_links');
    }

    /**
     * Expired virtual accounts in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function virtualAccounts(): array
    {
        return $this->listOf('virtual_accounts');
    }

    /**
     * Expired QRIS transactions in this batch.
     *
     * @return list<array<string, mixed>>
     */
    public function qrisTransactions(): array
    {
        return $this->listOf('qris_transactions');
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
