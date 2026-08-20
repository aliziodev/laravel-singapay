<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Console\Commands;

use Aliziodev\Singapay\Exceptions\IpNotWhitelistedException;
use Aliziodev\Singapay\Exceptions\SingaPayException;
use Aliziodev\Singapay\SingaPay;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Console\Command;

/**
 * Connectivity check: requests a token and performs a merchant balance
 * inquiry, with a dedicated diagnosis for the IP-whitelist failure mode.
 */
class PingCommand extends Command
{
    protected $signature = 'singapay:ping';

    protected $description = 'Verify SingaPay connectivity, credentials, and IP whitelisting';

    public function handle(SingaPay $singapay, SingaPayConfig $config): int
    {
        $this->components->twoColumnDetail('Environment', $config->environment->value);

        $started = microtime(true);

        try {
            $response = $singapay->balance()->merchant();
        } catch (IpNotWhitelistedException $exception) {
            $this->components->error('SingaPay rejected this server\'s IP (SP017).');
            $this->components->bulletList([
                'Register this server\'s public egress IP in the SingaPay merchant dashboard.',
                'Serverless platforms (Vercel, Netlify, Cloudflare Workers) have dynamic egress IPs and cannot be whitelisted — call SingaPay from a static-IP server or proxy instead.',
                'Full message: '.$exception->getMessage(),
            ]);

            return self::FAILURE;
        } catch (SingaPayException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->components->twoColumnDetail('Token + balance inquiry', "OK ({$durationMs} ms)");
        $this->components->twoColumnDetail('Available balance', (string) $response->data('available_balance.value', 'n/a'));

        $this->components->info('SingaPay connectivity looks good.');

        return self::SUCCESS;
    }
}
