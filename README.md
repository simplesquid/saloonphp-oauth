# Saloon OAuth Auto-Refresh

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simplesquid/saloonphp-oauth.svg?style=flat-square)](https://packagist.org/packages/simplesquid/saloonphp-oauth)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/simplesquid/saloonphp-oauth/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/simplesquid/saloonphp-oauth/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/simplesquid/saloonphp-oauth/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/simplesquid/saloonphp-oauth/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/simplesquid/saloonphp-oauth.svg?style=flat-square)](https://packagist.org/packages/simplesquid/saloonphp-oauth)

Concurrent-safe OAuth token management for [Saloon v4](https://docs.saloon.dev) connectors in Laravel. Handles automatic token refresh (Authorization Code) and token acquisition (Client Credentials), with distributed locking to prevent race conditions and Eloquent-backed encrypted storage.

## Requirements

- PHP ^8.4
- Laravel ^12.0 or ^13.0
- Saloon ^4.0
- A cache store that supports locking (Redis, Memcached, DynamoDB, or Database)

## Installation

```bash
composer require simplesquid/saloonphp-oauth
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="saloon-oauth-migrations"
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="saloon-oauth-config"
```

## Quick Start

### Authorization Code Flow

Add the `HasAutoRefresh` trait to a connector that uses Saloon's `AuthorizationCodeGrant`. Implement `resolveTokenKey()` to identify which token to use:

```php
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use SimpleSquid\SaloonOAuth\Concerns\HasAutoRefresh;

final class ExactOnlineConnector extends Connector
{
    use AuthorizationCodeGrant;
    use HasAutoRefresh;

    public function __construct(private readonly int $userId) {}

    protected function resolveTokenKey(): string
    {
        return "user:{$this->userId}:exact-online";
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId(config('services.exact.client_id'))
            ->setClientSecret(config('services.exact.client_secret'))
            ->setRedirectUri(config('services.exact.redirect_uri'));
    }

    public function resolveBaseUrl(): string
    {
        return 'https://start.exactonline.nl/api';
    }
}
```

That's it. When you send a request, `defaultAuth()` will automatically load the token from the store, check expiry, and refresh it if needed -- all within a distributed lock.

### Client Credentials Flow

Add the `HasClientCredentialsCache` trait. No methods are required -- the token key defaults to the connector's class name:

```php
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use SimpleSquid\SaloonOAuth\Concerns\HasClientCredentialsCache;

final class InternalApiConnector extends Connector
{
    use ClientCredentialsGrant;
    use HasClientCredentialsCache;

    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId(config('services.internal.client_id'))
            ->setClientSecret(config('services.internal.client_secret'));
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.internal.example.com';
    }
}
```

When no token exists or the current one is expired, a new token is acquired automatically.

### Storing the Initial Token (Authorization Code)

After the OAuth callback, store the token so `HasAutoRefresh` can find it:

```php
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;

public function callback(Request $request, TokenStore $store): RedirectResponse
{
    $connector = new ExactOnlineConnector($request->user()->id);

    $authenticator = $connector->getAccessToken(
        code: $request->query('code'),
        state: $request->query('state'),
        expectedState: session('oauth_state'),
    );

    $store->put("user:{$request->user()->id}:exact-online", $authenticator);

    return redirect()->route('dashboard');
}
```

### Revoking a Token

```php
$store->revoke("user:{$userId}:exact-online");
```

After revocation:

- `get()` throws `TokenRevokedException`.
- `put()` also throws `TokenRevokedException` — revoked keys cannot be overwritten. This prevents a concurrent refresh from silently un-revoking a token. To re-use the same key (e.g. after the user re-authorises), call `$store->forget($key)` first, then `$store->put($key, $newAuthenticator)`.

```php
$store->forget("user:{$userId}:exact-online");
$store->put("user:{$userId}:exact-online", $newAuthenticator);
```

## Failure Semantics

The traits are designed so that a single failing request doesn't cascade:

- **Token refresh HTTP call fails** — `TokenAcquisitionFailedException` is thrown, wrapping the underlying Saloon exception.
- **Token refresh succeeds but the store `put()` fails transiently** (e.g. DB outage) — the exception is reported via Laravel's `report()` helper, and the fresh token is still returned to the caller. The current request succeeds; the next request will try to refresh again.
- **Token refresh succeeds but the key was revoked concurrently** — `TokenRevokedException` is thrown. The persist is rejected at the store level, so a revoked token cannot accidentally resurrect.
- **Lock cannot be acquired within `lock.wait`** — `LockTimeoutException` is thrown.

## Configuration

```php
return [
    // Database table name for token storage.
    'table' => 'oauth_tokens',

    // Eloquent model used by EloquentTokenStore.
    'model' => \SimpleSquid\SaloonOAuth\Models\OAuthToken::class,

    // Distributed lock settings. The cache store must implement LockProvider.
    // null uses the default cache store. "file" does NOT support locking.
    'lock' => [
        'store' => null,   // Cache store name
        'ttl'   => 30,     // Lock auto-release time (seconds)
        'wait'  => 10,     // Max time to wait for the lock (seconds)
    ],

    // Seconds before actual expiry to trigger a proactive refresh.
    'expiry_buffer' => 300,
];
```

## Storage

Tokens are stored by the `EloquentTokenStore` (bound to the `TokenStore` contract by the service provider). It persists to the `oauth_tokens` table via the `OAuthToken` model.

### Schema

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `key` | string, unique | Your application-defined key (e.g. `"user:42:exact-online"`) |
| `access_token` | text | **Encrypted at rest** via Laravel's `encrypted` cast (`APP_KEY` required) |
| `refresh_token` | text, nullable | Encrypted at rest |
| `expires_at` | timestamp, nullable | When the access token expires; `null` = never expires |
| `revoked_at` | timestamp, nullable, indexed | Set by `revoke()`. Non-null means the token is dead |
| `created_at`/`updated_at` | timestamps | Standard Eloquent timestamps |

### Key Conventions

Keys are free-form strings you define per authentication context. Good patterns:

- **Per-user OAuth** (most common): `"user:{$userId}:{$provider}"` — one token per user per provider.
- **Per-tenant OAuth**: `"tenant:{$tenantId}:{$provider}"` — shared across users in a tenant.
- **Client credentials**: defaults to `static::class` (one token per connector class) — usually what you want. Override `resolveTokenKey()` if you need per-instance tokens.

The key must be unique and fit in `varchar(255)`. Use stable identifiers (don't put things like request IDs in it).

### Revoke vs Forget

- **`revoke()`** — soft-delete. Sets `revoked_at` and clears `access_token` / `refresh_token` values. Subsequent `get()` throws `TokenRevokedException`. The row stays in the table as an audit trail. `put()` on a revoked key also throws (see [Failure Semantics](#failure-semantics)).
- **`forget()`** — hard-delete. Removes the row entirely. Use this when you want to re-use the key with a fresh authorisation.

### Swapping the Storage Backend

Two levels of customization:

**Per-connector** — override `resolveTokenStore()` (useful when different connectors need different backends, or in tests):

```php
protected function resolveTokenStore(): TokenStore
{
    return new MyCustomTokenStore;
}
```

**Globally** — rebind the contract in a service provider:

```php
$this->app->bind(TokenStore::class, MyCustomTokenStore::class);
```

Your implementation must satisfy the `TokenStore` contract. Key invariants:

- `get($key)` returns `null` for missing keys and throws `TokenRevokedException` for revoked ones.
- `put($key, $auth)` throws `TokenRevokedException` if the key is revoked (prevents concurrent refresh from un-revoking).
- `revoke($key)` on a missing key is a silent no-op.

### Using a Custom Model

If you need to add relationships, scopes, or extra columns, extend `OAuthToken` and point the config at your class:

```php
// app/Models/UserOAuthToken.php
namespace App\Models;

use SimpleSquid\SaloonOAuth\Models\OAuthToken;

class UserOAuthToken extends OAuthToken
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

```php
// config/saloon-oauth.php
'model' => \App\Models\UserOAuthToken::class,
```

If you add columns, you'll need to publish the migration and customise it, or add a follow-up migration of your own.

### Using a Custom Table

Change `config('saloon-oauth.table')`. The default model reads this value from `getTable()`.

## Customization

Override these protected methods on your connector for per-connector customization:

| Method | Default | Description |
|--------|---------|-------------|
| `resolveTokenKey()` | abstract on `HasAutoRefresh`; `static::class` on `HasClientCredentialsCache` | Unique key for this connector's token |
| `resolveTokenStore()` | `app(TokenStore::class)` | Token persistence backend |
| `resolveTokenLocker()` | `app(TokenLocker::class)` | Distributed lock implementation |
| `resolveExpiryBuffer()` | `config('saloon-oauth.expiry_buffer')` | Seconds before expiry to trigger a proactive refresh |

### Swapping the Locker

`CacheTokenLocker` is the default. It delegates to a Laravel cache store's `LockProvider` (Redis, Memcached, DynamoDB, Database). Override per-connector or rebind the contract:

```php
$this->app->bind(TokenLocker::class, fn () => new CacheTokenLocker(
    $redisStore,
    ttl: 60,   // Longer TTL if your OAuth provider is slow
    wait: 30,  // Longer wait if refresh is rarely contended
));
```

For single-process contexts (tests, artisan commands where concurrency isn't a concern), use `NullLocker` — it just calls the callback without any locking.

### Expiry Buffer

`expiry_buffer` (default 300s) means tokens are considered expired 5 minutes before they actually expire. This gives the refresh enough headroom to complete before the in-flight token dies. Tune it based on:

- How long your refresh typically takes (set the buffer to ~3x that).
- How aggressive you want to be about "warm" tokens vs. unnecessary refreshes.

A token with no `expiresAt` (nullable) is treated as non-expiring — it won't be refreshed until revoked or forgotten.

## Exceptions

| Exception | When |
|-----------|------|
| `TokenRevokedException` | A revoked token is loaded from the store |
| `TokenAcquisitionFailedException` | Token refresh or acquisition fails (wraps the underlying exception) |
| `LockTimeoutException` | Could not acquire the distributed lock within the wait period |
| `InvalidCacheStoreException` | Configured cache store does not support locking |

## Testing

The package ships `NullLocker` and `InMemoryTokenStore` for use in tests. Override the resolver methods in your connector to inject them:

```php
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Support\NullLocker;
use SimpleSquid\SaloonOAuth\Testing\InMemoryTokenStore;

$store = new InMemoryTokenStore;
$locker = new NullLocker;

// Option A: accept dependencies via constructor and wire up the resolvers
final class YourConnector extends Connector
{
    use AuthorizationCodeGrant;
    use HasAutoRefresh;

    public function __construct(
        private readonly TokenStore $store,
        private readonly TokenLocker $locker,
    ) {}

    protected function resolveTokenStore(): TokenStore { return $this->store; }
    protected function resolveTokenLocker(): TokenLocker { return $this->locker; }
    // ...
}

// Option B: rebind the contracts in a test
$this->app->bind(TokenStore::class, fn () => new InMemoryTokenStore);
$this->app->bind(TokenLocker::class, fn () => new NullLocker);
```

Run the package tests:

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Matthew Poulter](https://github.com/mdpoulter)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
