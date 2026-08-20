<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-shot setup: publishes the config file and the webhook idempotency
 * migration, then prints the .env keys to fill in.
 */
class InstallCommand extends Command
{
    protected $signature = 'singapay:install
        {--force : Overwrite previously published files}';

    protected $description = 'Publish the SingaPay config and migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'singapay-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'singapay-migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->components->info('SingaPay installed. Next steps:');
        $this->components->bulletList([
            'Add SINGAPAY_CLIENT_ID, SINGAPAY_CLIENT_SECRET, SINGAPAY_PARTNER_ID, and SINGAPAY_ACCOUNT_ID to your .env',
            'Run "php artisan migrate" to create the webhook idempotency table',
            'Whitelist your server\'s public IP in the SingaPay merchant dashboard',
            'Run "php artisan singapay:ping" to verify connectivity',
        ]);

        return self::SUCCESS;
    }
}
