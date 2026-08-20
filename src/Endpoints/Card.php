<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Card money-in (Nicepay). Supports one-time payments and 3/6/12-month
 * installments. Responds with the v2 envelope; validation failures use
 * HTTP 400 (not 422).
 *
 * ⚠️ **PCI-DSS warning.** {@see payment()} accepts the raw card number, CVV,
 * and expiry. Any server that touches these values is inside PCI-DSS scope,
 * with everything that implies (audits, network segmentation, storage
 * rules). Unless you are certain you need raw card data, use a Payment Link
 * instead — the customer enters card details on SingaPay's hosted page and
 * your servers never see them. This SDK never logs request bodies, so card
 * data cannot leak into log files through the SDK, but your own application
 * code must be equally careful.
 */
class Card extends Endpoint
{
    /**
     * Charge a card (one-time payment or installment).
     *
     * `POST /api/v2.0/card/{account_id}/payment`
     *
     * ⚠️ PCI-DSS scope — see the class-level warning. Never store, log, or
     * echo the card fields.
     *
     * @param  array<string, mixed>  $data  Required: `amount`, `goods_name`,
     *                                      `customer_name`, `customer_email`, `customer_phone`, `customer_address`,
     *                                      `customer_city`, `customer_state`, `customer_postal_code`,
     *                                      `customer_country` (2-letter ISO), `card_number`, `card_expiry` (**YYMM** —
     *                                      December 2030 is `3012`, not `1230`; the wrong order is rejected with the
     *                                      misleading `SP001 Card Expiri Date Check Please.`, and SP001 normally means
     *                                      "outcome unknown, go inquire" — here it is simply a format error),
     *                                      `card_cvv`, `card_holder_name`, `card_holder_email`. Optional:
     *                                      `reference_no`, `description`, `installment` (bool),
     *                                      `installment_month` ("3"|"6"|"12").
     * @param  string|null  $accountId  Account ULID; defaults to `singapay.account_id`.
     */
    public function payment(array $data, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('POST', "/api/v2.0/card/{$this->accountId($accountId)}/payment", body: $data));
    }

    /**
     * Cancel a card transaction — void or refund depending on its state.
     *
     * `PATCH /api/v2.0/card/{account_id}/cancel/{id}`
     *
     * @param  string  $id  Flexible lookup key: numeric DB id, internal
     *                      `transaction_id`, or `provider_transaction_id`.
     * @param  string|null  $accountId  Account ULID.
     */
    public function cancel(string $id, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('PATCH', "/api/v2.0/card/{$this->accountId($accountId)}/cancel/{$this->segment($id)}"));
    }

    /**
     * Retrieve a card transaction's status (queried from the provider when
     * applicable).
     *
     * `GET /api/v2.0/card/{account_id}/inquiry-status/{id}`
     *
     * @param  string  $id  Same flexible lookup key as {@see cancel()}.
     * @param  string|null  $accountId  Account ULID.
     */
    public function inquireStatus(string $id, ?string $accountId = null): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v2.0/card/{$this->accountId($accountId)}/inquiry-status/{$this->segment($id)}"));
    }
}
