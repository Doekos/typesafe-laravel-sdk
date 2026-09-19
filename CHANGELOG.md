# Changelog

All notable changes to `doekos/typesafe-laravel-sdk` are documented here. This project follows the
official TypeSafe SDK version line and adheres to [Semantic Versioning](https://semver.org).

## 0.7.0 - 2026-09-21

Initial release. A standalone, Laravel-native SDK for the TypeSafe (Jev / System One) API.
Requires PHP 8.3+ and Laravel 12 or 13; Guzzle 7 and 8 are both supported.
Mirrors Python SDK 0.7.0 and JavaScript SDK 0.6.0.

- `TypeSafe::systemOne()` with `noul`, `choice`, and `score` questions (objects or raw arrays).
- `TypeSafe::listModels()`, or `app(TypeSafeClient::class)->models->list()`.
- `TypeSafe::pool()` concurrency over `Http::pool`, retrying in rounds; never throws per item.
- `RetryPolicy`: exponential backoff with jitter, `Retry-After` handling, `maxRetryAfter` cap,
  total-budget stop, retryable statuses, extra exception types, and a custom predicate.
- Full error mapping with server-message extraction, plus `ApiConnectionException` /
  `ApiTimeoutException` (timeouts detected on both Guzzle 7 and 8).
- `SystemOneResponse` with `answers`/`nouls`/`choices`/`scores` maps and `noul()`/`choice()`/`score()`
  getters; `Usage`, `ModelsResponse`, `ModelMetadata`.
- `responseModel` DTO hydration by question name, with type checking.
- Laravel `Log` integration with credential redaction, gated by log level.
- Service provider, `TypeSafe` facade, and publishable `config/typesafe.php` (tag `typesafe-config`).
