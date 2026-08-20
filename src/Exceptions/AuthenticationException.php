<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised for SP013 (invalid or expired access token) after the SDK has
 * already refreshed the token and retried once, and for failures while
 * requesting an access token.
 */
class AuthenticationException extends RequestException {}
