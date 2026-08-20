<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Tests\Feature;

use Aliziodev\Singapay\Tests\WebhooksDisabledTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class WebhookRouteDisabledTest extends WebhooksDisabledTestCase
{
    #[Test]
    public function it_does_not_register_the_webhook_route_when_webhooks_are_disabled(): void
    {
        $this->assertFalse(Route::has('singapay.webhook'));
    }
}
