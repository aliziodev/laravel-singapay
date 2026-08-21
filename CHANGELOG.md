## 2.0.0 (2026-08-21)

* docs: confirm the identity host needs credentials of its own ([2413744](https://github.com/aliziodev/laravel-singapay/commit/2413744))
* docs: correct the money-in webhook finding — they follow the credential ([042924c](https://github.com/aliziodev/laravel-singapay/commit/042924c))
* docs: explain SP403 as a credential-ownership error ([3cc2962](https://github.com/aliziodev/laravel-singapay/commit/3cc2962))
* docs: record how to trigger each webhook event in sandbox ([7386092](https://github.com/aliziodev/laravel-singapay/commit/7386092))
* docs: record the sandbox disbursement prefix rule and what PaymentMethod is ([d3aad48](https://github.com/aliziodev/laravel-singapay/commit/d3aad48))
* docs: record what the full sandbox sweep proved ([d95d281](https://github.com/aliziodev/laravel-singapay/commit/d95d281))
* docs: verify the biller V2 surface and flag its third secret ([10cf613](https://github.com/aliziodev/laravel-singapay/commit/10cf613))
* docs: warn that a successful KYC verify is not a name match ([9f31115](https://github.com/aliziodev/laravel-singapay/commit/9f31115))
* fix: call check-beneficiary at the path that actually serves it ([b81c05c](https://github.com/aliziodev/laravel-singapay/commit/b81c05c))
* fix: read the biller envelope instead of misreading it as v2 ([ce828c8](https://github.com/aliziodev/laravel-singapay/commit/ce828c8))
* fix: read the identity (KYC) envelope instead of misreading it as flat ([1c3cd04](https://github.com/aliziodev/laravel-singapay/commit/1c3cd04))
* fix: surface biller field errors, which have no dedicated key ([ffa7de6](https://github.com/aliziodev/laravel-singapay/commit/ffa7de6))
* feat: allow the biller host its own credentials ([d3d7bb4](https://github.com/aliziodev/laravel-singapay/commit/d3d7bb4))
* feat!: move payment links onto the v2 API ([bc5b773](https://github.com/aliziodev/laravel-singapay/commit/bc5b773))

### BREAKING CHANGE

* paymentLinks()->create() now requires payment_link_type,
and the $accountId parameter is gone from find() and update() since the v2
paths have no account segment. SingaPay::pay('payment_link', ...) maps onto
v2 automatically: 'title' becomes 'description', 'redirect_url' becomes
'success_redirect_url', and expires_at is sent as ISO 8601.

## <small>1.4.3 (2026-08-20)</small>

* fix: reject a verify-signature endpoint that lost its leading slash ([ef3d296](https://github.com/aliziodev/laravel-singapay/commit/ef3d296))
* fix: route accounts()->updateStatus() through the path that exists ([b47bb1c](https://github.com/aliziodev/laravel-singapay/commit/b47bb1c))

## <small>1.4.2 (2026-08-20)</small>

* Merge branch 'main' of https://github.com/aliziodev/laravel-singapay ([88fa01a](https://github.com/aliziodev/laravel-singapay/commit/88fa01a))
* docs: document payment-method whitelisting and retail outlet ([a5f3958](https://github.com/aliziodev/laravel-singapay/commit/a5f3958))
* docs: record how the dashboard actually delivers webhooks ([a3ec061](https://github.com/aliziodev/laravel-singapay/commit/a3ec061))
* docs: settle the sandbox webhook question and the amount-typing trap ([6a494b2](https://github.com/aliziodev/laravel-singapay/commit/6a494b2))
* fix: match the hyphenated expiration event names SingaPay actually sends ([979b6aa](https://github.com/aliziodev/laravel-singapay/commit/979b6aa))
* fix: send check-beneficiary as GET and record what sandbox proved ([f904f7d](https://github.com/aliziodev/laravel-singapay/commit/f904f7d))
* fix: stop the money-out guard from blocking direct-debit charges ([19d3cf0](https://github.com/aliziodev/laravel-singapay/commit/19d3cf0))

## <small>1.4.1 (2026-08-20)</small>

* fix: allow guzzlehttp/guzzle ^8.0 ([1cd3c61](https://github.com/aliziodev/laravel-singapay/commit/1cd3c61))
* fix: enforce the money-out guard inside SingaPay::fake() ([dc7abcc](https://github.com/aliziodev/laravel-singapay/commit/dc7abcc))
* fix: raise IpNotWhitelistedException when the token exchange rejects the IP ([e64c07a](https://github.com/aliziodev/laravel-singapay/commit/e64c07a))
* fix: surface per-field validation errors from the v1 envelope ([995a7e3](https://github.com/aliziodev/laravel-singapay/commit/995a7e3))
* chore: stop publishing the internal prd ([3231d8e](https://github.com/aliziodev/laravel-singapay/commit/3231d8e))
* test: reach 100% line coverage ([917154e](https://github.com/aliziodev/laravel-singapay/commit/917154e))
* ci: upload coverage to codecov from the primary cell ([ca4497a](https://github.com/aliziodev/laravel-singapay/commit/ca4497a))
* docs: expand readme badges and fix the license source ([77e8c96](https://github.com/aliziodev/laravel-singapay/commit/77e8c96))

## 1.4.0 (2026-08-20)

* test: cover audit-surfaced edges, raising coverage to 99.4% ([b6e67ea](https://github.com/aliziodev/laravel-singapay/commit/b6e67ea))
* refactor: centralize webhook body normalization ([f21dcae](https://github.com/aliziodev/laravel-singapay/commit/f21dcae))
* feat: add webhook replay helper mirroring live dispatch ([7e89ddd](https://github.com/aliziodev/laravel-singapay/commit/7e89ddd))
* fix: claim webhook deliveries before dispatching listeners ([4908abf](https://github.com/aliziodev/laravel-singapay/commit/4908abf))
* fix: harden amount parsing and token caching edge cases ([927cbe5](https://github.com/aliziodev/laravel-singapay/commit/927cbe5))
* fix: require confirmation before revealing a full bearer token ([0ebd4d0](https://github.com/aliziodev/laravel-singapay/commit/0ebd4d0))
* fix: surface charge expiry mistakes as charge exceptions ([bbb8b0c](https://github.com/aliziodev/laravel-singapay/commit/bbb8b0c))
* docs: record the undocumented default-vs-specific credential model ([8189b17](https://github.com/aliziodev/laravel-singapay/commit/8189b17))

## 1.3.0 (2026-08-20)

* feat: support the dashboard hmac validation key for webhook verification ([093d5fb](https://github.com/aliziodev/laravel-singapay/commit/093d5fb))
* docs: state why the singapay timezone is a constant, not config ([62cfe03](https://github.com/aliziodev/laravel-singapay/commit/62cfe03))

## 1.2.0 (2026-08-20)

* feat: expose the webhook ledger as an eloquent model ([4e9ff24](https://github.com/aliziodev/laravel-singapay/commit/4e9ff24))

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
