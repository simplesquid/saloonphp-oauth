# Saloon OAuth Auto-Refresh

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simplesquid/saloonphp-oauth.svg?style=flat-square)](https://packagist.org/packages/simplesquid/saloonphp-oauth)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/simplesquid/saloonphp-oauth/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/simplesquid/saloonphp-oauth/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/simplesquid/saloonphp-oauth/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/simplesquid/saloonphp-oauth/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/simplesquid/saloonphp-oauth.svg?style=flat-square)](https://packagist.org/packages/simplesquid/saloonphp-oauth)

Automatic, concurrent-safe OAuth token management for [Saloon v4](https://docs.saloon.dev) connectors in Laravel. Handles refresh for the Authorization Code grant, caching for the Client Credentials grant, and uses a distributed lock so two processes don't refresh the same token at the same time.

## Requirements

- PHP ^8.4
- Laravel ^12.0 or ^13.0
- Saloon ^4.0
- A cache store that supports locking (Redis, Memcached, DynamoDB, or Database). The `file` driver does not support locking.

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

## Usage

### Authorization Code Flow

Add the `HasAutoRefresh` trait to a connector using Saloon's `AuthorizationCodeGrant`. The only method you need to implement is `resolveTokenKey()`:

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

When you send a request, `defaultAuth()` loads the token from the store, checks expiry, and refreshes it if needed — all within a distributed lock.

### Client Credentials Flow

Add the `HasClientCredentialsCache` trait. No extra methods are required — the token key defaults to the connector's class name, so one token is cached per connector class:

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

### Storing the Initial Token

This step is only needed for the Authorization Code flow — client credentials tokens are acquired automatically on first use.

After the OAuth callback, persist the token so `HasAutoRefresh` can find it on subsequent requests:

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

See [Revoke vs Forget](#revoke-vs-forget) for the difference between soft-revocation and hard-deletion.

## Configuration

```php
return [
    // Database table name for token storage.
    'table' => 'oauth_tokens',

    // Eloquent model used by EloquentTokenStore.
    'model' => \SimpleSquid\SaloonOAuth\Models\OAuthToken::class,

    // Distributed lock settings. The cache store must implement LockProvider.
    'lock' => [
        'store' => null,   // Cache store name (null = default). "file" does NOT support locking.
        'ttl'   => 30,     // How long the lock is held before auto-releasing (seconds).
        'wait'  => 10,     // How long to wait to acquire the lock (seconds).
    ],

    // Seconds before actual expiry to trigger a proactive refresh.
    'expiry_buffer' => 300,
];
```

## Customization

Each connector resolves four protected methods. Override any of them to change behaviour for that connector:

| Method | Default | Purpose |
|---|---|---|
| `resolveTokenKey()` | abstract on `HasAutoRefresh`; `static::class` on `HasClientCredentialsCache` | Unique key for this connector's token |
| `resolveTokenStore()` | `app(TokenStore::class)` | Token persistence backend |
| `resolveTokenLocker()` | `app(TokenLocker::class)` | Distributed lock implementation |
| `resolveExpiryBuffer()` | `config('saloon-oauth.expiry_buffer')` | Seconds before expiry to trigger a proactive refresh |

For application-wide changes, rebind the contracts in a service provider instead:

```php
$this->app->bind(TokenStore::class, MyCustomTokenStore::class);
$this->app->bind(TokenLocker::class, MyCustomTokenLocker::class);
```

### Token Store

The default `EloquentTokenStore` persists tokens to the `oauth_tokens` table via the `OAuthToken` model. Any implementation of the `TokenStore` contract can replace it.

**Custom model.** To add relationships, scopes, or extra columns, extend `OAuthToken` and point the config at your class:

```php
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

If you add columns, follow up with your own migration — don't edit the published one.

**Custom table.** Change `config('saloon-oauth.table')`. The default model reads this value from `getTable()`.

**Custom backend.** Implement the `TokenStore` contract. Required invariants:

- `get($key)` returns `null` for missing keys, throws `TokenRevokedException` for revoked ones.
- `put($key, $auth)` throws `TokenRevokedException` if the key exists and is revoked (this is what prevents a concurrent refresh from un-revoking a token).
- `revoke($key)` on a missing key is a silent no-op.

### Token Locker

`CacheTokenLocker` delegates to a Laravel cache store's `LockProvider`. The two timeouts control different things:

- **`ttl`** — how long the lock itself is held before auto-releasing. Set this longer than your slowest expected token refresh (including network timeouts). If it's too short, a slow refresh can expire the lock and let another process in.
- **`wait`** — how long a competing request blocks waiting for the lock. Set this longer than your typical refresh, but shorter than your HTTP timeout. If it's exceeded, `LockTimeoutException` is thrown.

Override per-connector, or rebind globally:

```php
$this->app->bind(TokenLocker::class, fn () => new CacheTokenLocker(
    $redisLockProvider,
    ttl: 60,
    wait: 30,
));
```

For single-process contexts where locking is unnecessary (tests, one-off artisan commands), use `NullLocker` — it just calls the callback.

### Expiry Buffer

Tokens are treated as expired `expiry_buffer` seconds before their actual expiry. The default of 300 seconds gives the refresh enough headroom to complete before the in-flight token dies. Shorter values mean tokens are used closer to their real lifetime; longer values refresh more eagerly.

A token with a `null` `expiresAt` is treated as non-expiring — it won't be refreshed until revoked or forgotten.

## Storage Details

### Schema

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `key` | string, unique | Your application-defined key (e.g. `"user:42:exact-online"`) |
| `access_token` | text | Encrypted at rest via Laravel's `encrypted` cast (`APP_KEY` required) |
| `refresh_token` | text, nullable | Encrypted at rest |
| `expires_at` | timestamp, nullable | When the access token expires; `null` means never |
| `revoked_at` | timestamp, nullable, indexed | Set by `revoke()`; non-null means the token is dead |
| `created_at` / `updated_at` | timestamps | Standard Eloquent timestamps |

### Key Conventions

Keys are free-form strings you choose per authentication context. Keep them stable — don't embed things like request IDs. Common patterns:

- **Per-user OAuth** (most common): `"user:{$userId}:{$provider}"`.
- **Per-tenant OAuth**: `"tenant:{$tenantId}:{$provider}"`.
- **Client credentials**: defaults to `static::class` — one token per connector class. Override `resolveTokenKey()` only if you need per-instance tokens.

Keys must fit in `varchar(255)`.

### Revoke vs Forget

- **`revoke($key)`** — soft-delete. Sets `revoked_at` and clears the `access_token` / `refresh_token` columns. `get()` and `put()` both throw `TokenRevokedException` afterwards. The row stays in the table as an audit trail.
- **`forget($key)`** — hard-delete. Removes the row entirely. Use this before re-authorising with the same key:

```php
$store->forget("user:{$userId}:exact-online");
$store->put("user:{$userId}:exact-online", $newAuthenticator);
```

## Failure Semantics

The traits surface failures through custom exceptions and degrade gracefully where they can:

| Exception | When |
|---|---|
| `TokenRevokedException` | A revoked token is loaded — or a concurrent refresh tries to persist over a key that was revoked mid-refresh |
| `TokenAcquisitionFailedException` | Refresh or acquisition failed; wraps the underlying Saloon exception |
| `LockTimeoutException` | Couldn't acquire the lock within `lock.wait` |
| `InvalidCacheStoreException` | Configured cache store doesn't implement `LockProvider` |

**Transient persist failures are non-fatal.** If the refresh HTTP call succeeds but `$store->put()` fails (DB outage, etc.), the exception is sent through Laravel's `report()` helper and the fresh token is still returned to the caller. The current request succeeds; the next request will retry the refresh.

**Concurrent revokes are respected.** If a revoke lands while a refresh is in flight, `put()` refuses to overwrite the revoked row and throws `TokenRevokedException`. The current request fails — matching the intent of the revocation — and the token stays dead.

## Testing

The package ships two test doubles:

- `SimpleSquid\SaloonOAuth\Support\NullLocker` — no-op locker for single-process tests.
- `SimpleSquid\SaloonOAuth\Testing\InMemoryTokenStore` — array-backed store that mirrors the `EloquentTokenStore` semantics (including the revoked-key protection).

Either inject them into your connector via the resolver overrides, or rebind the contracts in your test's service container.

Run the package's own tests with:

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
