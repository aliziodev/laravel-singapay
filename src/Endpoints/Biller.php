<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Enums\Host;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Biller (PPOB) services — a separate host with a command-style API.
 *
 * Every call is a POST whose body carries a `command` plus a `data` object.
 * The response envelope also differs: `response_code` carries a short numeric
 * status alongside `response_text` — `"00"` success, `"04"` rejected format,
 * `"99"` system error, and `"6"` transaction-not-found (note it is not
 * zero-padded). Product categories include PLN tokens, mobile credit, data
 * packages, game top-ups, utilities, and government/social insurance.
 *
 * ⚠️ **Payments need a third secret.** Every `*Payment()` call takes a
 * `password` in its `data` — the *merchant credential password*, distinct
 * from the client secret used to obtain the token. The SDK deliberately does
 * not read it from config: passing it explicitly at the call site keeps a
 * stray code path from spending real money, the same reasoning behind the
 * money-out guard.
 *
 * The biller also mixes response envelopes — a `401` comes back in the v1
 * shape (`{status, success, error}`) with no `command` at all. {@see Response}
 * handles both.
 */
class Biller extends Endpoint
{
    /**
     * Retrieve the biller deposit balance.
     *
     * `POST {biller}/api/v1/check-balance`
     */
    public function checkBalance(): Response
    {
        return $this->command('/api/v1/check-balance', 'check-balance');
    }

    /**
     * Retrieve one biller transaction.
     *
     * `POST {biller}/api/v1/detail-bill-transaction`
     */
    public function transactionDetail(string $transactionId): Response
    {
        return $this->command('/api/v1/detail-bill-transaction', 'detail-bill-transaction', ['transaction_id' => $transactionId]);
    }

    /**
     * List biller transactions.
     *
     * `POST {biller}/api/v1/list-bill-transaction`
     *
     * @param  array<string, mixed>  $filters  `transaction_id`, `customer_id`,
     *                                         `status` (pending|success|refunded|failed), `start_date`/`end_date`
     *                                         (Y-m-d, required together), `page`.
     */
    public function listTransactions(array $filters = []): Response
    {
        return $this->command('/api/v1/list-bill-transaction', 'list-bill-transaction', $filters);
    }

    /**
     * Reset the test customer ID (sandbox/development only).
     *
     * `POST {biller}/api/v1/reset-customer-id`
     */
    public function resetCustomerId(): Response
    {
        return $this->command('/api/v1/reset-customer-id', 'reset-customer-id');
    }

    /**
     * Retrieve game top-up parameter definitions (server list, etc.).
     *
     * `POST {biller}/api/v2/prepaid/get-parameter-game-topup`
     */
    public function gameTopupParameters(string $categoryCode): Response
    {
        return $this->command('/api/v2/prepaid/get-parameter-game-topup', 'get-parameter-game-topup', ['category_code' => $categoryCode]);
    }

    /**
     * Prepaid inquiry (v2) — required before PLN token and game top-up payments.
     *
     * `POST {biller}/api/v2/prepaid/inquiry`
     *
     * @param  string  $command  Product command: `plntok` or `topupg`.
     * @param  array<string, mixed>  $data  `customer_id`, `product_code`; for
     *                                      `topupg` also `server_id` (from {@see gameTopupParameters()}), `username`, `platform`.
     */
    public function prepaidInquiry(string $command, array $data): Response
    {
        return $this->command('/api/v2/prepaid/inquiry', $command, $data);
    }

    /**
     * Prepaid payment (v2).
     *
     * `POST {biller}/api/v2/prepaid/payment`
     *
     * `pulsa`, `data` and `vouchg` are paid directly, with no inquiry first —
     * for those `customer_id` is simply the phone number. `plntok` and
     * `topupg` must be inquired first and carry that inquiry's
     * `reference_number`.
     *
     * @param  string  $command  `pulsa`, `data`, `plntok`, `topupg`, or `vouchg`.
     * @param  array<string, mixed>  $data  `product_code`, `password` (the
     *                                      merchant credential password, not the client secret),
     *                                      `customer_id`; `reference_number` (required for plntok/topupg, from the
     *                                      inquiry).
     */
    public function prepaidPayment(string $command, array $data): Response
    {
        return $this->command('/api/v2/prepaid/payment', $command, $data);
    }

    /**
     * Postpaid inquiry (v2) — always required before a postpaid payment.
     *
     * `POST {biller}/api/v2/postpaid/inquiry`
     *
     * @param  string  $command  `pdam`, `plnpos`, `plnnon`, `intv`, `bpjsks`, `bputk`, `putk`, or `mobpos`.
     * @param  array<string, mixed>  $data  `customer_id`, `product_code`;
     *                                      `period` (required for bpjsks 1–12, bputk 1/2/3/6/12, putk — send it as
     *                                      an **integer**, though the response echoes it back as a string),
     *                                      `phone_number` (max 25).
     *                                      The reply carries `amount`, `late_fee` and `price`, where `price` is the
     *                                      total actually payable (amount + late fee + admin fee) — bill that, not
     *                                      `amount`.
     */
    public function postpaidInquiry(string $command, array $data): Response
    {
        return $this->command('/api/v2/postpaid/inquiry', $command, $data);
    }

    /**
     * Postpaid payment (v2).
     *
     * `POST {biller}/api/v2/postpaid/payment`
     *
     * @param  string  $command  Same enum as {@see postpaidInquiry()}.
     * @param  array<string, mixed>  $data  `password`, `product_code`, `customer_id`,
     *                                      `reference_number` (from the inquiry); `phone_number` optional.
     */
    public function postpaidPayment(string $command, array $data): Response
    {
        return $this->command('/api/v2/postpaid/payment', $command, $data);
    }

    /**
     * Prepaid inquiry (v1, legacy — PLN token only).
     *
     * `POST {biller}/api/v1/prepaid/inquiry`
     *
     * @param  array<string, mixed>  $data  `customer_id`, `product_code`.
     */
    public function legacyPrepaidInquiry(array $data): Response
    {
        return $this->command('/api/v1/prepaid/inquiry', 'plntok', $data);
    }

    /**
     * Prepaid payment (v1, legacy).
     *
     * `POST {biller}/api/v1/prepaid/payment`
     *
     * @param  string  $command  `pulsa`, `data`, or `plntok`.
     * @param  array<string, mixed>  $data  `product_code`, `password`, `customer_id`;
     *                                      `reference_number` for plntok.
     */
    public function legacyPrepaidPayment(string $command, array $data): Response
    {
        return $this->command('/api/v1/prepaid/payment', $command, $data);
    }

    /**
     * Postpaid inquiry (v1, legacy). Same schema as {@see postpaidInquiry()}.
     *
     * `POST {biller}/api/v1/postpaid/inquiry`
     *
     * @param  array<string, mixed>  $data
     */
    public function legacyPostpaidInquiry(string $command, array $data): Response
    {
        return $this->command('/api/v1/postpaid/inquiry', $command, $data);
    }

    /**
     * Postpaid payment (v1, legacy). Same schema as {@see postpaidPayment()}.
     *
     * `POST {biller}/api/v1/postpaid/payment`
     *
     * @param  array<string, mixed>  $data
     */
    public function legacyPostpaidPayment(string $command, array $data): Response
    {
        return $this->command('/api/v1/postpaid/payment', $command, $data);
    }

    /**
     * Fire a command-style biller request.
     *
     * @param  array<string, mixed>  $data
     */
    private function command(string $path, string $command, array $data = []): Response
    {
        $body = ['command' => $command];

        if ($data !== []) {
            $body['data'] = $data;
        }

        return $this->send(new ApiRequest('POST', $path, body: $body, host: Host::Biller));
    }
}
