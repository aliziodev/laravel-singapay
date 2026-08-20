<?php

declare(strict_types=1);

namespace Aliziodev\Singapay;

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Support\SingaPayConfig;

/**
 * Entry point of the SDK: exposes one accessor per endpoint group.
 *
 * Resolve it from the container, or use the {@see Facades\SingaPay}
 * facade:
 *
 * ```php
 * SingaPay::paymentLinks()->create([...]);
 * SingaPay::balance()->merchant();
 * SingaPay::disbursement()->transfer([...]); // requires the money-out guard
 * ```
 */
class SingaPay
{
    /**
     * Instantiated endpoint groups, keyed by class name.
     *
     * @var array<class-string, Endpoints\Endpoint>
     */
    private array $endpoints = [];

    public function __construct(
        protected readonly SingaPayClientInterface $client,
        protected readonly SingaPayConfig $config,
    ) {}

    /** Sub-account management. */
    public function accounts(): Endpoints\Accounts
    {
        return $this->endpoint(Endpoints\Accounts::class);
    }

    /** Merchant and per-account balance inquiry. */
    public function balance(): Endpoints\Balance
    {
        return $this->endpoint(Endpoints\Balance::class);
    }

    /** Balance transfers between own sub-accounts (money-out). */
    public function accountTransfer(): Endpoints\AccountTransfer
    {
        return $this->endpoint(Endpoints\AccountTransfer::class);
    }

    /** Account statements. */
    public function statements(): Endpoints\Statements
    {
        return $this->endpoint(Endpoints\Statements::class);
    }

    /** Payment link management. */
    public function paymentLinks(): Endpoints\PaymentLinks
    {
        return $this->endpoint(Endpoints\PaymentLinks::class);
    }

    /** Payment link payment histories. */
    public function paymentLinkHistories(): Endpoints\PaymentLinkHistories
    {
        return $this->endpoint(Endpoints\PaymentLinkHistories::class);
    }

    /** Virtual account provisioning. */
    public function virtualAccounts(): Endpoints\VirtualAccounts
    {
        return $this->endpoint(Endpoints\VirtualAccounts::class);
    }

    /** Virtual account transactions (read-only). */
    public function vaTransactions(): Endpoints\VaTransactions
    {
        return $this->endpoint(Endpoints\VaTransactions::class);
    }

    /** QRIS money-in (dynamic QR). */
    public function qris(): Endpoints\Qris
    {
        return $this->endpoint(Endpoints\Qris::class);
    }

    /** E-wallet money-in (native checkout). */
    public function ewallet(): Endpoints\EwalletMoneyIn
    {
        return $this->endpoint(Endpoints\EwalletMoneyIn::class);
    }

    /** Card money-in. ⚠️ PCI-DSS scope — prefer payment links. */
    public function card(): Endpoints\Card
    {
        return $this->endpoint(Endpoints\Card::class);
    }

    /** Recurring subscription plans. */
    public function subscriptions(): Endpoints\Subscriptions
    {
        return $this->endpoint(Endpoints\Subscriptions::class);
    }

    /** Direct debit card binding and charging. */
    public function directDebit(): Endpoints\DirectDebit
    {
        return $this->endpoint(Endpoints\DirectDebit::class);
    }

    /** Bank disbursements (money-out). */
    public function disbursement(): Endpoints\Disbursement
    {
        return $this->endpoint(Endpoints\Disbursement::class);
    }

    /** QRIS money-out (issuer). */
    public function qrisMoneyOut(): Endpoints\QrisMoneyOut
    {
        return $this->endpoint(Endpoints\QrisMoneyOut::class);
    }

    /** E-wallet money-out (top-up). */
    public function ewalletMoneyOut(): Endpoints\EwalletMoneyOut
    {
        return $this->endpoint(Endpoints\EwalletMoneyOut::class);
    }

    /** Cardless ATM withdrawals. */
    public function cardlessWithdrawal(): Endpoints\CardlessWithdrawal
    {
        return $this->endpoint(Endpoints\CardlessWithdrawal::class);
    }

    /** Biller (PPOB) services on the biller host. */
    public function biller(): Endpoints\Biller
    {
        return $this->endpoint(Endpoints\Biller::class);
    }

    /** Identity verification (KYC) on the identity host. */
    public function identity(): Endpoints\IdentityVerification
    {
        return $this->endpoint(Endpoints\IdentityVerification::class);
    }

    /**
     * The underlying transport, for advanced use (e.g. calling an endpoint
     * the SDK does not wrap yet).
     */
    public function client(): SingaPayClientInterface
    {
        return $this->client;
    }

    /**
     * The immutable SDK configuration snapshot.
     */
    public function config(): SingaPayConfig
    {
        return $this->config;
    }

    /**
     * @template TEndpoint of Endpoints\Endpoint
     *
     * @param  class-string<TEndpoint>  $class
     * @return TEndpoint
     */
    private function endpoint(string $class): Endpoints\Endpoint
    {
        /** @var TEndpoint */
        return $this->endpoints[$class] ??= new $class($this->client, $this->config);
    }
}
