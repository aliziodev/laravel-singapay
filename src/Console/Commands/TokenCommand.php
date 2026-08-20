<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Console\Commands;

use Aliziodev\Singapay\Auth\AccessTokenManager;
use Aliziodev\Singapay\Exceptions\SingaPayException;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Debug helper: fetches (or reuses) an access token and prints it.
 */
class TokenCommand extends Command
{
    protected $signature = 'singapay:token
        {--fresh : Discard the cached token and request a new one}
        {--full : Print the complete token instead of a truncated preview}';

    protected $description = 'Fetch and display a SingaPay access token (debug)';

    public function handle(AccessTokenManager $tokens, SingaPayConfig $config): int
    {
        if ($this->option('fresh')) {
            $tokens->forget();
        }

        try {
            $token = $tokens->token();
        } catch (SingaPayException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Environment', $config->environment->value);
        $this->components->twoColumnDetail('Auth version', $config->authVersion);
        $this->components->twoColumnDetail(
            'Access token',
            $this->option('full') ? $token : Str::limit($token, 24).' (use --full to reveal)',
        );

        return self::SUCCESS;
    }
}
