<?php

declare(strict_types=1);

namespace Aliziodev\Singapay\Support;

use Aliziodev\Singapay\Contracts\JsonNormalizerInterface;
use Aliziodev\Singapay\Exceptions\JsonNormalizationException;
use JsonException;
use stdClass;

/**
 * Canonical JSON serializer for SingaPay signature payloads.
 *
 * This is the single most signature-critical component in the SDK: the
 * request-signature scheme hashes the serialized body, so the SDK and the
 * gateway must agree on every byte. The canonical form is:
 *
 * - Object keys sorted recursively with byte-order comparison (SORT_STRING).
 * - Lists (sequential arrays) keep their order; elements are normalized recursively.
 * - Encoded with JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, no whitespace.
 * - Floats are rejected outright — whole floats serialize differently across
 *   runtimes ("100000.0" vs "100000") and would silently break signatures.
 *
 * An empty PHP array is serialized as [] (a list). Pass an stdClass (for
 * example `(object) []`) when the gateway expects an empty JSON object {}.
 *
 * The behaviour of this class is pinned by the shared signature-vector
 * fixtures in tests/Fixtures/signature-vectors.json. Any change here must
 * keep those vectors green.
 */
final class JsonNormalizer implements JsonNormalizerInterface
{
    public function normalize(array|stdClass $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload, '$'),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw JsonNormalizationException::encodingFailed($exception);
        }
    }

    public function hash(array|stdClass $payload): string
    {
        return hash('sha256', $this->normalize($payload));
    }

    /**
     * Recursively sort object keys while preserving list order and JSON types.
     *
     * @param  string  $path  Dot-style location used for precise error messages.
     *
     * @throws JsonNormalizationException
     */
    private function canonicalize(mixed $value, string $path): mixed
    {
        if ($value instanceof stdClass) {
            // Cast back to an object so an empty stdClass still encodes as {}.
            return (object) $this->canonicalizeMap((array) $value, $path);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    fn (mixed $item, int $index): mixed => $this->canonicalize($item, "{$path}[{$index}]"),
                    $value,
                    array_keys($value)
                );
            }

            return $this->canonicalizeMap($value, $path);
        }

        if (is_float($value)) {
            throw JsonNormalizationException::floatDetected($path);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw JsonNormalizationException::unsupportedType(get_debug_type($value), $path);
    }

    /**
     * Sort an associative array's keys in byte order and normalize its values.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>
     *
     * @throws JsonNormalizationException
     */
    private function canonicalizeMap(array $map, string $path): array
    {
        ksort($map, SORT_STRING);

        $sorted = [];

        foreach ($map as $key => $item) {
            $sorted[$key] = $this->canonicalize($item, "{$path}.{$key}");
        }

        return $sorted;
    }
}
