# Changelog

All notable changes to `saloonphp-oauth` will be documented in this file.

## v0.1.0 - 2026-04-15

Initial release.

### Features

- `HasAutoRefresh` trait for Saloon `AuthorizationCodeGrant` connectors — overrides `defaultAuth()` to load, check expiry, and refresh tokens automatically.
- `HasClientCredentialsCache` trait for `ClientCredentialsGrant` connectors — zero-config token caching with auto-acquisition.
- Distributed locking (`CacheTokenLocker`) with separate TTL and wait timeout, plus TOCTOU re-read inside the lock.
- `EloquentTokenStore` with encrypted at-rest storage, soft revocation, and a revoke-resistant `put()` that refuses to un-revoke keys.
- Best-effort persist in the traits: a transient DB failure after a successful refresh is reported via `report()` and the fresh token is still returned to the caller.
- `TokenStore` and `TokenLocker` contracts for swapping persistence and locking backends.
- `InMemoryTokenStore` and `NullLocker` shipped under `src/Testing` and `src/Support` for use in consumer tests.
- Custom exceptions: `TokenRevokedException`, `TokenAcquisitionFailedException`, `LockTimeoutException`, `InvalidCacheStoreException`.

### Requirements

- PHP 8.4+
- Laravel ^12.0 || ^13.0
- Saloon v4
- A cache store that implements `LockProvider` (Redis, Memcached, DynamoDB, or Database) — `file` is not supported.

### Notes

- API may change before v1.0.0 based on real-world integration feedback.
