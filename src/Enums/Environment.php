<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Enums;

/**
 * SingaPay deployment environments.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Whether this is the production environment (real money movement).
     */
    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
