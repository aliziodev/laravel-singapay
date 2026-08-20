<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Tests;

/**
 * Boots the package with the webhook route disabled.
 */
abstract class WebhooksDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('singapay.webhooks.enabled', false);
    }
}
