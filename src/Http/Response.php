<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Http;

use Aliziodev\Singapay\Enums\ResponseCode;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Normalized SingaPay API response.
 *
 * The gateway answers with several envelope shapes depending on the API
 * generation:
 *
 * - v1: `{"status": 200, "success": true, "data": {...}}` with errors in
 *   `{"error": {"code", "message"}}`
 * - v2: `{"response_code": "SP000", "response_message": "...", "data": {...}}`
 * - flat: token exchanges and some auxiliary services return the payload
 *   directly with no envelope.
 *
 * This class folds all of them into one consistent shape so calling code
 * never has to know which generation an endpoint belongs to.
 *
 * @implements Arrayable<array-key, mixed>
 */
final readonly class Response implements Arrayable
{
    /**
     * @param  int  $status  HTTP status code.
     * @param  ResponseCode|null  $code  SP response code, when the envelope carries one.
     * @param  string  $message  Human-readable gateway message.
     * @param  array<array-key, mixed>  $data  The `data` portion of the envelope (or the whole payload for flat responses).
     * @param  array<array-key, mixed>  $raw  The complete decoded response body, untouched.
     */
    public function __construct(
        public int $status,
        public ?ResponseCode $code,
        public string $message,
        public array $data,
        public array $raw,
    ) {}

    /**
     * Build a normalized response from a Laravel HTTP client response,
     * detecting which envelope generation the gateway used.
     */
    public static function fromHttp(HttpResponse $response): self
    {
        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];

        // v2 envelope: {"response_code": "SPxxx", "response_message": ..., "data": ...}
        if (array_key_exists('response_code', $raw)) {
            $data = $raw['data'] ?? [];

            return new self(
                status: $response->status(),
                code: ResponseCode::tryFrom((string) $raw['response_code']),
                message: (string) ($raw['response_message'] ?? ''),
                data: is_array($data) ? $data : [],
                raw: $raw,
            );
        }

        // v1 envelope: {"status": ..., "success": bool, "data"|"error": ...}
        if (array_key_exists('success', $raw)) {
            $data = $raw['data'] ?? [];
            $message = $raw['success'] === true
                ? (string) ($raw['message'] ?? 'OK')
                : (string) Arr::get($raw, 'error.message', 'Request failed');

            return new self(
                status: $response->status(),
                code: null,
                message: $message,
                data: is_array($data) ? $data : [],
                raw: $raw,
            );
        }

        // Flat payload (token exchanges, auxiliary services).
        return new self(
            status: $response->status(),
            code: null,
            message: (string) ($raw['message'] ?? ''),
            data: $raw,
            raw: $raw,
        );
    }

    /**
     * Whether the gateway accepted the request.
     *
     * Note: for transactional endpoints this means the *request* succeeded,
     * not that money moved — always check the transaction status too.
     */
    public function successful(): bool
    {
        // An envelope that carries a response_code is only successful on
        // SP000 — an unknown code is conservatively treated as a failure.
        if (array_key_exists('response_code', $this->raw)) {
            return $this->code === ResponseCode::Success;
        }

        if (array_key_exists('success', $this->raw)) {
            return $this->raw['success'] === true;
        }

        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Retrieve a value from the `data` section using dot notation.
     *
     * ```php
     * $response->data('payment_url');
     * $response->data('amount.value');
     * ```
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->data : Arr::get($this->data, $key, $default);
    }

    /**
     * Retrieve a `data` value as a collection.
     *
     * @return Collection<array-key, mixed>
     */
    public function collect(?string $key = null): Collection
    {
        $value = $this->data($key, []);

        return new Collection(is_array($value) ? $value : [$value]);
    }

    /**
     * The complete decoded response body.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
