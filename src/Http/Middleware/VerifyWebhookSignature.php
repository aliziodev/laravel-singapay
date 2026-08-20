<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http\Middleware;

use Aliziodev\Singapay\Exceptions\WebhookVerificationException;
use Aliziodev\Singapay\Support\SingaPayConfig;
use Aliziodev\Singapay\Webhooks\WebhookVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects SingaPay webhook deliveries whose signature or timestamp does not
 * verify, before they ever reach the controller.
 *
 * The webhook route is registered without the `web` middleware group on
 * purpose: `web` includes CSRF verification, and SingaPay posts without a
 * CSRF token, so every delivery would bounce with 419 and loop through the
 * gateway's retry queue forever.
 */
final class VerifyWebhookSignature
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly SingaPayConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->webhookVerifySignature) {
            return $next($request);
        }

        try {
            $this->verifier->verify(
                rawBody: (string) $request->getContent(),
                signature: $request->header('X-Signature'),
                timestamp: $request->header('X-Timestamp'),
                authorization: $request->header('Authorization'),
                endpoint: $request->getRequestUri(),
                clientSecret: $this->config->requireClientSecret(),
                toleranceSeconds: $this->config->webhookTolerance,
            );
        } catch (WebhookVerificationException $exception) {
            $this->logger->warning('singapay.webhook.rejected', [
                'reason' => $exception->getMessage(),
                'ip' => $request->ip(),
            ]);

            // Deliberately terse: never tell the caller *why* verification failed.
            return new JsonResponse(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
