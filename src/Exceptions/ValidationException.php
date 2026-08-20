<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised for SP018: one or more request fields failed gateway validation.
 */
class ValidationException extends RequestException
{
    /**
     * Per-field validation errors reported by the gateway.
     *
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        $errors = $this->response->data('errors', []);

        return is_array($errors) ? $errors : [];
    }
}
