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

After revocation, any attempt to use the token throws `TokenRevokedException`. To remove the record entirely, use `$store->forget($key)`.

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

## Customization

Override these protected methods in your connector to customize behavior:

| Method | Default | Description |
|--------|---------|-------------|
| `resolveTokenKey()` | (abstract / `static::class`) | Unique key for this connector's token |
| `resolveTokenStore()` | `app(TokenStore::class)` | Token persistence backend |
| `resolveTokenLocker()` | `app(TokenLocker::class)` | Distributed lock implementation |
| `resolveExpiryBuffer()` | `config('saloon-oauth.expiry_buffer')` | Seconds before expiry to refresh |

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
