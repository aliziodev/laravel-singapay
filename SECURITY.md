# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| latest major | ✅ |
| older majors | ❌ |

The package targets the latest Laravel release only; security fixes land on the latest major version.

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Report privately via [GitHub Security Advisories](https://github.com/aliziodev/laravel-singapay/security/advisories/new) (preferred) or by email to `aliziodev@gmail.com`. You should receive a response within a few days. Please include a proof of concept where possible.

## Scope notes for integrators

- This SDK signs requests with your **client secret**; treat `.env` and your cache store (which holds access tokens) as sensitive.
- The SDK never logs request/response bodies, but your own application code might — never log card fields or webhook payloads containing customer data to shared sinks.
- Webhook signature verification is on by default; disabling `singapay.webhooks.verify_signature` in production removes the only authentication on that route.
- The money-out guard (`singapay.money_out.enabled`) is a safety net, not an authorization system — keep your own authorization checks in front of money-moving code.
