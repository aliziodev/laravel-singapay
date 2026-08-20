<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Endpoints;

use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Recurring subscription plans.
 *
 * Creating a plan returns a `payment_link_url` where the customer completes
 * a one-time card (or GoPay) linking; SingaPay then charges each cycle
 * automatically. Plan IDs are UUIDs.
 */
class Subscriptions extends Endpoint
{
    /**
     * Create a recurring plan.
     *
     * `POST /api/v2.0/recurring/plans`
     *
     * @param  array<string, mixed>  $data  Required: `name`, `customer_name`,
     *                                      `customer_email`, `customer_phone`, `schedule` (interval, interval_unit,
     *                                      total_interval, start_time), and exactly one of `amount` or `items`.
     *                                      `account_id` defaults to the configured account. Optional:
     *                                      `subscription_id`, `merchant_reff_no`, `currency`, `customer_id`,
     *                                      `payment_type` (credit_card|gopay), `return_url`, `retry_policy`,
     *                                      `charge_immediately`, `allow_manual_payment`, `allow_user_notification`,
     *                                      `metadata`.
     */
    public function createPlan(array $data): Response
    {
        return $this->send(new ApiRequest('POST', '/api/v2.0/recurring/plans', body: $this->withAccountId($data)));
    }

    /**
     * Retrieve a plan.
     *
     * `GET /api/v2.0/recurring/plans/{id}`
     *
     * @param  string  $planId  Plan UUID.
     */
    public function findPlan(string $planId): Response
    {
        return $this->send(new ApiRequest('GET', "/api/v2.0/recurring/plans/{$this->segment($planId)}"));
    }

    /**
     * Update a plan in place, or upgrade/downgrade it when `amount`/`items`
     * change (the response then carries an `upgrade` object with proration
     * details).
     *
     * `PATCH /api/v2.0/recurring/plans/{id}`
     *
     * @param  string  $planId  Plan UUID.
     * @param  array<string, mixed>  $data  Any of `name`, `merchant_reff_no`,
     *                                      `amount` or `items`, `metadata`, `prorated_charge_mode` (auto|manual),
     *                                      `prorated_charge_amount`.
     */
    public function updatePlan(string $planId, array $data): Response
    {
        return $this->send(new ApiRequest('PATCH', "/api/v2.0/recurring/plans/{$this->segment($planId)}", body: $data));
    }

    /**
     * Cancel a plan.
     *
     * `POST /api/v2.0/recurring/plans/cancel/{id}`
     *
     * @param  string  $planId  Plan UUID.
     * @param  string|null  $reason  Defaults to "merchant_api_cancel" gateway-side.
     */
    public function cancelPlan(string $planId, ?string $reason = null): Response
    {
        return $this->send(new ApiRequest(
            'POST',
            "/api/v2.0/recurring/plans/cancel/{$this->segment($planId)}",
            body: $reason === null ? [] : ['reason' => $reason],
        ));
    }
}
