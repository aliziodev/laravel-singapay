# Signatures

> 🇮🇩 Versi Bahasa Indonesia: [docs/signatures.md](../signatures.md)

SingaPay uses **four different cryptographic schemes** in one product. The SDK keeps them strictly separated — you never implement any of this yourself, but this document explains what happens under the hood (useful when debugging SP016).

## Scheme A — Access token v1.1

```
payload     = "{client_id}_{client_secret}_{YYYYMMDD}"
X-Signature = HMAC-SHA512(payload, client_secret)   → lowercase hex
```

- `YYYYMMDD` is the **Asia/Jakarta** calendar day, never UTC. The Node.js example in SingaPay's official docs is wrong (it uses a UTC date) — between 00:00 and 07:00 WIB its signatures are rejected. The SDK pins the timezone via `JakartaClock`.
- Implemented in `Auth\AccessTokenSigner`.

## Scheme B — Access token v1.0 (legacy)

`Authorization: Basic base64(client_id:client_secret)` — no signature. Used when `auth_version = 1.0`, and always used for the biller host's token.

## Scheme C — Request signature (money-out & webhooks)

```
1. Normalize the body → recursive byte-order key sort, encode without unicode/slash escaping
2. hashed_body       = SHA-256(normalized_json)                    → hex
3. string_to_sign    = "{METHOD}:{ENDPOINT}:{ACCESS_TOKEN}:{hashed_body}:{TIMESTAMP}"
4. X-Signature       = HMAC-SHA512(string_to_sign, client_secret)  → lowercase hex
```

- `METHOD` is uppercase; `ENDPOINT` is the full path **including the query string and the `/api` prefix**, no domain; `TIMESTAMP` is Unix **seconds** (the `X-Timestamp` header).
- Implemented in `Auth\RequestSigner` + `Support\JsonNormalizer`.
- The SDK sends **the normalized bytes themselves** as the request body, so the wire body and the signed hash can never disagree.
- For inbound webhooks the same formula runs in reverse: the token comes from the `Authorization` header SingaPay sent, `ENDPOINT` is your own callback path, and comparison uses `hash_equals` (constant time).

## Scheme D — Identity/KYC

```
timestamp = RFC 3339 UTC, second precision (e.g. 2026-05-26T07:30:00Z)
signature = HMAC-SHA256("{client_id}:{timestamp}", client_secret)   → hex
```

⚠️ SHA-**256** (not 512), a **colon** separator (not underscore), a **UTC** timestamp (not a Jakarta date), and a **separate credential pair**. Isolated in `Auth\IdentitySigner` + `Auth\IdentityTokenManager` so it can never be confused with the payment schemes.

## JSON canonicalization rules (the most critical component)

1. Object keys are sorted **recursively** with byte-order comparison (`ksort(..., SORT_STRING)`).
2. Arrays (lists) are **not** sorted — element order is preserved; each element's contents are still normalized.
3. Encoding uses `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, no whitespace.
4. **Floats are rejected.** `json_encode(100000.0)` yields `100000.0` in PHP but `100000` in JS — one byte of difference destroys the signature. Send amounts as integers (`Amount`).
5. An empty PHP associative array (`[]`) encodes as `[]`; when the gateway expects an empty object `{}`, pass `new stdClass` / `(object) []`.

This behaviour is pinned by 18 *signature vectors* in `tests/Fixtures/signature-vectors.json` — each stores the canonical bytes, the SHA-256 hash, and the expected HMAC signature for a fixed test secret. Any sibling SDK (e.g. a TypeScript port) must produce byte-identical output against the same fixture.

## Debugging signatures

```bash
php artisan singapay:verify-signature body.json \
  --endpoint="/api/v2.0/disbursement/transfer" \
  --token="<access-token>" \
  --timestamp=1755657600
```

The output shows every intermediate value (normalized JSON, body hash, string-to-sign, final signature) so mismatches can be compared step by step. See also the symptom table in [troubleshooting.md](troubleshooting.md).
