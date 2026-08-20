<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised when the package is used before it has been configured correctly.
 */
class ConfigurationException extends SingaPayException
{
    /**
     * A required config value is missing.
     */
    public static function missing(string $key): self
    {
        return new self(
            "Missing SingaPay configuration [{$key}]. "
            .'Publish the config with `php artisan singapay:install` and set the corresponding value in your .env file.'
        );
    }

    /**
     * A config value holds something unexpected.
     */
    public static function invalid(string $key, string $reason): self
    {
        return new self("Invalid SingaPay configuration [{$key}]: {$reason}");
    }
}
