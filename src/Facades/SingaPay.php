<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Facades;

use Aliziodev\Singapay\Contracts\SingaPayClientInterface;
use Aliziodev\Singapay\Http\Response;
use Aliziodev\Singapay\SingaPay as SingaPayManager;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Testing\FakeSingaPayClient;
use Aliziodev\Singapay\Testing\SingaPayFake;
use Closure;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * Facade for the SingaPay SDK.
 *
 * @method static \Aliziodev\Singapay\Endpoints\Accounts accounts()
 * @method static \Aliziodev\Singapay\Endpoints\Balance balance()
 * @method static \Aliziodev\Singapay\Endpoints\AccountTransfer accountTransfer()
 * @method static \Aliziodev\Singapay\Endpoints\Statements statements()
 * @method static \Aliziodev\Singapay\Endpoints\PaymentLinks paymentLinks()
 * @method static \Aliziodev\Singapay\Endpoints\PaymentLinkHistories paymentLinkHistories()
 * @method static \Aliziodev\Singapay\Endpoints\VirtualAccounts virtualAccounts()
 * @method static \Aliziodev\Singapay\Endpoints\VaTransactions vaTransactions()
 * @method static \Aliziodev\Singapay\Endpoints\Qris qris()
 * @method static \Aliziodev\Singapay\Endpoints\EwalletMoneyIn ewallet()
 * @method static \Aliziodev\Singapay\Endpoints\Card card()
 * @method static \Aliziodev\Singapay\Endpoints\Subscriptions subscriptions()
 * @method static \Aliziodev\Singapay\Endpoints\DirectDebit directDebit()
 * @method static \Aliziodev\Singapay\Endpoints\Disbursement disbursement()
 * @method static \Aliziodev\Singapay\Endpoints\QrisMoneyOut qrisMoneyOut()
 * @method static \Aliziodev\Singapay\Endpoints\EwalletMoneyOut ewalletMoneyOut()
 * @method static \Aliziodev\Singapay\Endpoints\CardlessWithdrawal cardlessWithdrawal()
 * @method static \Aliziodev\Singapay\Endpoints\Biller biller()
 * @method static \Aliziodev\Singapay\Endpoints\IdentityVerification identity()
 * @method static \Aliziodev\Singapay\Charges\Charges charges()
 * @method static \Aliziodev\Singapay\Charges\ChargeResult pay(\Aliziodev\Singapay\Enums\PaymentMethod|string $method, array<string, mixed> $data, string|null $accountId = null)
 * @method static \Aliziodev\Singapay\Contracts\SingaPayClientInterface client()
 * @method static \Aliziodev\Singapay\Support\SingaPayConfig config()
 * @method static void assertSent(string|callable $matcher)
 * @method static void assertNotSent(string|callable $matcher)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 * @method static void assertPaymentLinkCreated(callable|null $callback = null)
 * @method static void assertDisbursementRequested(callable|null $callback = null)
 *
 * @see SingaPayManager
 */
class SingaPay extends Facade
{
    /**
     * Replace the SDK with a recording fake for the current test.
     *
     * All endpoint groups keep working; requests are recorded instead of
     * sent, and responses come from the given fixtures (path pattern =>
     * data array | Response | Closure). Also rebinds
     * {@see SingaPayClientInterface} so code injecting the contract
     * directly is faked too.
     *
     * @param  array<string, array<array-key, mixed>|Response|Closure>  $fixtures
     */
    public static function fake(array $fixtures = []): SingaPayFake
    {
        $app = static::getFacadeApplication()
            ?? throw new RuntimeException('The SingaPay facade has no application; boot Laravel before calling fake().');

        $fakeClient = new FakeSingaPayClient($fixtures);
        $fake = new SingaPayFake($fakeClient, $app->make(SingaPayConfig::class));

        $app->instance(SingaPayClientInterface::class, $fakeClient);
        $app->instance(SingaPayManager::class, $fake);

        static::clearResolvedInstance(static::getFacadeAccessor());

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return SingaPayManager::class;
    }
}
