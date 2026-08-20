## 1.1.0 (2026-08-20)

* docs: document the unified charge api ([b91a190](https://github.com/aliziodev/laravel-singapay/commit/b91a190))
* ci: raise the coverage gate to 90% and audit dependencies ([0839517](https://github.com/aliziodev/laravel-singapay/commit/0839517))
* test: cover event accessors, token edge paths, and lockless stores ([b4cd190](https://github.com/aliziodev/laravel-singapay/commit/b4cd190))
* feat: add unified charge api across money-in methods ([905776a](https://github.com/aliziodev/laravel-singapay/commit/905776a))
* fix: url-encode path parameters and pin the no-body-logging guarantee ([11eccd7](https://github.com/aliziodev/laravel-singapay/commit/11eccd7))

## 1.0.0 (2026-08-20)

* ci: add test matrix and semantic-release workflows ([498617e](https://github.com/aliziodev/laravel-singapay/commit/498617e))
* ci: lift the composer advisory block for laravel 11 cells ([6f41876](https://github.com/aliziodev/laravel-singapay/commit/6f41876))
* ci: pin conventionalcommits preset to major 7 ([640f239](https://github.com/aliziodev/laravel-singapay/commit/640f239))
* feat!: require php 8.3 and laravel 13 ([0cbe283](https://github.com/aliziodev/laravel-singapay/commit/0cbe283))
* docs: add indonesian documentation with english alternative ([3d12197](https://github.com/aliziodev/laravel-singapay/commit/3d12197))
* docs: recommend laravel 12+ over post-eol laravel 11 ([dbdbea8](https://github.com/aliziodev/laravel-singapay/commit/dbdbea8))
* test: add transport, endpoint contract, and webhook http coverage ([56af686](https://github.com/aliziodev/laravel-singapay/commit/56af686))
* feat: add canonical json normalizer pinned by signature vectors ([b914055](https://github.com/aliziodev/laravel-singapay/commit/b914055))
* feat: add endpoint groups covering the full api surface ([5461a03](https://github.com/aliziodev/laravel-singapay/commit/5461a03))
* feat: add hmac signers for all three signature schemes ([da274fa](https://github.com/aliziodev/laravel-singapay/commit/da274fa))
* feat: add http transport, token management, and typed exceptions ([8d96fed](https://github.com/aliziodev/laravel-singapay/commit/8d96fed))
* feat: add install, token, ping, and verify-signature commands ([0d4f6b8](https://github.com/aliziodev/laravel-singapay/commit/0d4f6b8))
* feat: add integer-only amount value object and jakarta clock ([5f719c9](https://github.com/aliziodev/laravel-singapay/commit/5f719c9))
* feat: add recording fake and consumer test helpers ([6c577a5](https://github.com/aliziodev/laravel-singapay/commit/6c577a5))
* feat: add response-code and status enums with typed configuration ([13125df](https://github.com/aliziodev/laravel-singapay/commit/13125df))
* feat: add webhook verification, typed events, and idempotency ([2f938ef](https://github.com/aliziodev/laravel-singapay/commit/2f938ef))
* feat: wire service provider, manager, facade, and config ([49e21d3](https://github.com/aliziodev/laravel-singapay/commit/49e21d3))
* chore: scaffold package with tooling and test bootstrap ([133d1c4](https://github.com/aliziodev/laravel-singapay/commit/133d1c4))

### BREAKING CHANGE

* PHP 8.2, Laravel 11, and Laravel 12 are no longer
supported; require ^13.0 of the framework and PHP 8.3+.

# Changelog

All notable changes to `aliziodev/laravel-singapay` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Releases are generated automatically by semantic-release from Conventional Commits.
