# Changelog

All notable changes to `kg-bot/hookbox` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First public release preparation.

### Added

- Initial Hookbox core: receiver, source configuration, signature verification, redacted message persistence, replay receipts, dedupe, queued processing, in-process replay, pruning, repositories, and lifecycle events.
- Public action registration through the shared action registry and `Hookbox::handle(...)->when(...)->through(...)`.
- Built-in verifiers for Stripe, GitHub, Shopify, Slack, Mailgun, Standard Webhooks, PayPal, and AWS SNS.
- Shared outbound verifier transport and fail-closed failure policy for network-backed verification flows.
- Documentation for `StandardWebhooksVerifier` as the fallback path for providers like Make and Zapier that do not expose a stable provider-managed verification protocol.

### Changed

- Finalized the first-release support matrix at Laravel 12-13 while keeping the package PHP floor at 8.2.
- Package migrations now auto-load through the service provider, so installation no longer requires publishing migrations first.
- The internal unpublished handler flow was replaced by the shared action registry and `Illuminate\Pipeline\Pipeline` runner used by both queued processing and replay.
- Replay filtering was renamed from `handlersFilter` to `actionsFilter` before first release.
