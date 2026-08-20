<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Contracts;

use Aliziodev\Singapay\Exceptions\ConnectionException;
use Aliziodev\Singapay\Exceptions\MoneyOutDisabledException;
use Aliziodev\Singapay\Exceptions\RequestException;
use Aliziodev\Singapay\Http\ApiRequest;
use Aliziodev\Singapay\Http\Response;

/**
 * Transport boundary of the SDK.
 *
 * Endpoint classes depend on this contract only, which is what allows
 * `SingaPay::fake()` to swap in a recording fake without touching HTTP.
 */
interface SingaPayClientInterface
{
    /**
     * Execute an API call against SingaPay.
     *
     * Implementations are responsible for authentication headers, the
     * money-out request signature, the money-out guard, envelope
     * normalization, and mapping gateway errors to typed exceptions.
     *
     * @throws MoneyOutDisabledException When a signed request is attempted while money-out is disabled.
     * @throws ConnectionException When SingaPay cannot be reached.
     * @throws RequestException When the gateway reports a failure.
     */
    public function send(ApiRequest $request): Response;
}
