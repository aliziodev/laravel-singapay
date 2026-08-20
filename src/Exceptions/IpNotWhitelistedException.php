<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use Aliziodev\Singapay\Http\Response;

/**
 * Raised for SP017: the request came from an IP that is not whitelisted
 * for the merchant account.
 *
 * SingaPay requires every calling server's egress IP to be registered.
 * Serverless platforms (Vercel, Netlify, Cloudflare Workers) use dynamic
 * egress IPs and cannot call SingaPay directly — route the calls through a
 * server or proxy with a static IP instead.
 */
class IpNotWhitelistedException extends RequestException
{
    protected static function buildMessage(Response $response): string
    {
        return parent::buildMessage($response)
            .' Register this server\'s public egress IP in the SingaPay merchant dashboard. '
            .'Serverless platforms with dynamic egress IPs cannot be whitelisted — use a static-IP host or proxy.';
    }
}
