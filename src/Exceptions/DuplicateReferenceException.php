<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Exceptions;

/**
 * Raised for SP004: the reference number was already used.
 *
 * Do not retry with the same reference. If you are unsure whether the
 * original request went through, call the relevant inquiry-status endpoint
 * with this reference before doing anything else.
 */
class DuplicateReferenceException extends RequestException {}
