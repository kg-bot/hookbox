# Contributing

Thanks for contributing to Hookbox.

## Development standards

- PHP files must declare `strict_types=1`.
- Public contracts should stay as small as possible.
- New behavior should land through tests first.
- UI concerns do not belong in this repository.
- Webhook processing behavior should flow through the shared action registry and `WebhookActionContext`, not ad-hoc direct handler loops.

## Verifier fixture convention

Each verifier should ship with fixture-driven tests that mirror the vendor's real request shape as closely as possible.

Directory convention:

```text
tests/
  Fixtures/
    Verifiers/
      Stripe/
        valid.json
        tampered.json
        expired-timestamp.json
      GitHub/
      Shopify/
      Slack/
      Mailgun/
      StandardWebhooks/
      PayPal/
      AwsSns/
```

Each fixture set should include:

- the raw request body bytes to verify
- the exact headers used for verification
- source config overrides needed by the verifier
- the expected verification status
- the expected idempotency key, when the provider supplies one
- the expected event type, when the provider supplies one

When vendor docs publish canonical signatures or test secrets, use those values directly instead of inventing package-specific test vectors.

## Running checks

```bash
composer test
composer analyse
composer format:test
```

## Supported matrix

- Laravel 12 on PHP 8.2+
- Laravel 13 on PHP 8.3+
- PHPUnit compatibility runs across the supported Laravel matrix in CI.
- Static analysis and formatting run on the latest supported framework/tooling lane in CI.

## Scope guardrails

- No admin UI, Blade views, or frontend assets in core.
- No HTTP replay endpoints in core.
- No new runtime dependencies outside `illuminate/*` unless a network-backed verifier has a concrete, tested need. Native PHP extensions already required by the platform, such as OpenSSL for signature verification, are preferred.
- Migration guidance for `spatie/laravel-webhook-client` belongs in docs unless there is a concrete, tested runtime compatibility need.

## Provider-specific verifier bar

- Ship a provider-named built-in verifier only when the provider documents a stable authenticity protocol that Hookbox can verify honestly.
- If a provider only supports user-configured headers, basic auth, or other ad-hoc request settings, document the fallback path through `StandardWebhooksVerifier` or a host-app custom verifier instead of hardcoding a provider class.
