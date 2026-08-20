<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Console\Commands;

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Exceptions\SingaPayException;
use Aliziodev\Singapay\Support\JakartaClock;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Illuminate\Console\Command;

/**
 * Debug helper for signature mismatches (SP016): computes every
 * intermediate value of the request-signature scheme from a JSON body file
 * so it can be compared against the gateway's expectation step by step.
 */
class VerifySignatureCommand extends Command
{
    protected $signature = 'singapay:verify-signature
        {file : Path to a JSON file containing the request body}
        {--method=POST : HTTP method}
        {--endpoint=/api/v2.0/disbursement/transfer : Endpoint path including query string}
        {--token=ACCESS_TOKEN : Bearer token used in the string-to-sign}
        {--timestamp= : Unix seconds (defaults to now, Asia/Jakarta)}';

    protected $description = 'Compute a SingaPay request signature from a JSON file (debug)';

    public function handle(
        JsonNormalizerInterface $normalizer,
        RequestSigner $signer,
        JakartaClock $clock,
        SingaPayConfig $config,
    ): int {
        $file = $this->argument('file');

        if (! is_string($file)) {
            $this->components->error('The file argument must be a path string.');

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->components->error("File not found: {$file}");

            return self::FAILURE;
        }

        $body = json_decode((string) file_get_contents($file), true);

        if (! is_array($body)) {
            $this->components->error('The file does not contain a valid JSON object.');

            return self::FAILURE;
        }

        $timestampOption = $this->option('timestamp');
        $timestamp = is_string($timestampOption) && ctype_digit($timestampOption)
            ? (int) $timestampOption
            : $clock->unixSeconds();

        $method = strtoupper($this->stringOption('method', 'POST'));
        $endpoint = $this->stringOption('endpoint', '/api/v2.0/disbursement/transfer');
        $token = $this->stringOption('token', 'ACCESS_TOKEN');

        try {
            $normalized = $normalizer->normalize($body);
        } catch (SingaPayException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $hashedBody = hash('sha256', $normalized);
        $stringToSign = $signer->stringToSign($method, $endpoint, $token, $hashedBody, $timestamp);

        $this->components->twoColumnDetail('Normalized JSON', $normalized);
        $this->components->twoColumnDetail('SHA-256 body hash', $hashedBody);
        $this->components->twoColumnDetail('String to sign', $stringToSign);
        $this->components->twoColumnDetail(
            'X-Signature',
            $signer->signHashedBody($method, $endpoint, $token, $hashedBody, $timestamp, $config->requireClientSecret()),
        );
        $this->components->twoColumnDetail('X-Timestamp', (string) $timestamp);

        return self::SUCCESS;
    }

    /**
     * Read a string option, falling back when it is absent or not a string.
     */
    private function stringOption(string $key, string $default): string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
