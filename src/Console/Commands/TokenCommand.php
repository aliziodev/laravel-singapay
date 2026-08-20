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

        // A full bearer token pasted into a logged shell or CI job is
        // directly replayable against the payment API — make revealing it
        // a conscious, confirmed act (non-interactive runs stay truncated).
        $reveal = (bool) $this->option('full')
            && $this->confirm('The complete bearer token will be printed and may persist in terminal or CI logs. Continue?');

        $this->components->twoColumnDetail('Environment', $config->environment->value);
        $this->components->twoColumnDetail('Auth version', $config->authVersion);
        $this->components->twoColumnDetail(
            'Access token',
            $reveal ? $token : Str::limit($token, 24).' (use --full to reveal)',
        );

        return self::SUCCESS;
    }
}
