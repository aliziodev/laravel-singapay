<?php

declare(strict_types=1);

use Aliziodev\Singapay\Auth\RequestSigner;
use Aliziodev\Singapay\Exceptions\JsonNormalizationException;
use Aliziodev\Singapay\Support\JsonNormalizer;

covers(JsonNormalizer::class, JsonNormalizationException::class);

beforeEach(function (): void {
    $this->normalizer = new JsonNormalizer;
});

/**
 * The single most important test in the repository: every vector pins the
 * exact canonical bytes, body hash, and request signature. If this test
 * breaks, every money-out signature breaks.
 */
it('matches every shared signature vector', function (stdClass $vector): void {
    $fixture = signatureVectors();

    $normalized = $this->normalizer->normalize($vector->payload);

    expect($normalized)->toBe($vector->normalized_json)
        ->and($this->normalizer->hash($vector->payload))->toBe($vector->hashed_body);

    $signature = (new RequestSigner($this->normalizer))->signHashedBody(
        $fixture->method,
        $fixture->endpoint,
        $fixture->access_token,
        $vector->hashed_body,
        $fixture->timestamp,
        $fixture->secret,
    );

    expect($signature)->toBe($vector->expected_signature);
})->with(function (): Generator {
    foreach (signatureVectors()->vectors as $vector) {
        yield $vector->name => [$vector];
    }
});

it('serializes an empty object as {} and an empty array as []', function (): void {
    expect($this->normalizer->normalize(new stdClass))->toBe('{}')
        ->and($this->normalizer->normalize([]))->toBe('[]');
});

it('rejects float values with the offending path', function (): void {
    $this->normalizer->normalize(['data' => ['amount' => 100000.5]]);
})->throws(JsonNormalizationException::class, '$.data.amount');

it('rejects whole floats too, because they serialize ambiguously', function (): void {
    $this->normalizer->normalize(['amount' => 100000.0]);
})->throws(JsonNormalizationException::class, 'Float value detected');

it('rejects floats hidden inside lists', function (): void {
    $this->normalizer->normalize(['items' => [['price' => 1.5]]]);
})->throws(JsonNormalizationException::class, '$.items[0].price');

it('rejects values that have no canonical JSON representation', function (): void {
    $this->normalizer->normalize(['callback' => static fn (): bool => true]);
})->throws(JsonNormalizationException::class, 'Unsupported value');

it('sorts stdClass properties recursively while keeping object semantics', function (): void {
    $payload = (object) ['b' => (object) ['z' => 1, 'a' => 2], 'a' => new stdClass];

    expect($this->normalizer->normalize($payload))->toBe('{"a":{},"b":{"a":2,"z":1}}');
});

it('never mutates the input payload', function (): void {
    $payload = ['b' => 2, 'a' => 1];
    $copy = $payload;

    $this->normalizer->normalize($payload);

    expect($payload)->toBe($copy);
});
