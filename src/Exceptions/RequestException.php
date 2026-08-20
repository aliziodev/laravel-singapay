<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

use Aliziodev\Singapay\Enums\ResponseCode;
use Aliziodev\Singapay\Http\Response;

/**
 * Raised when SingaPay answered but reported a failure — either an HTTP
 * error status or an SP error code in the response envelope.
 *
 * Specific failure modes that require distinct handling have dedicated
 * subclasses ({@see InsufficientBalanceException},
 * {@see DuplicateReferenceException}, {@see ValidationException}, ...);
 * everything else raises this class directly. The full gateway response is
 * always available via {@see RequestException::$response}.
 */
class RequestException extends SingaPayException
{
    final public function __construct(
        public readonly Response $response,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the appropriately typed exception for a failed response.
     */
    public static function fromResponse(Response $response): self
    {
        $class = $response->code?->exceptionClass() ?? self::class;

        /** @var self $exception */
        $exception = new $class($response, $class::buildMessage($response));

        return $exception;
    }

    /**
     * The SP response code reported by the gateway, when present.
     */
    public function responseCode(): ?ResponseCode
    {
        return $this->response->code;
    }

    /**
     * Whether the real outcome is unknown and an inquiry-status call must be
     * made before reacting (SP001 Transaction Failure, SP005 Timeout).
     * Never blindly retry a money-out request in this state.
     */
    public function shouldInquireStatus(): bool
    {
        return $this->response->code?->shouldInquireStatus() ?? false;
    }

    /**
     * Compose the human-readable exception message for a failed response.
     */
    protected static function buildMessage(Response $response): string
    {
        $code = $response->code->value ?? "HTTP {$response->status}";

        return "SingaPay request failed [{$code}]: {$response->message}";
    }
}
