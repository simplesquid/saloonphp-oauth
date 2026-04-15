<?php

declare(strict_types=1);

use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\TokenAcquisitionFailedException;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;
use SimpleSquid\SaloonOAuth\Support\NullLocker;
use SimpleSquid\SaloonOAuth\Testing\InMemoryTokenStore;
use SimpleSquid\SaloonOAuth\Tests\Fixtures\TestConnector;

it('returns a valid token without refreshing', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $token = new AccessTokenAuthenticator(
        accessToken: 'valid-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('+1 hour'),
    );

    $store->put('test-key', $token);

    $connector = new TestConnector('test-key', $store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)
        ->toBeInstanceOf(AccessTokenAuthenticator::class)
        ->getAccessToken()->toBe('valid-token');
});

it('refreshes an expired token', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $expired = new AccessTokenAuthenticator(
        accessToken: 'expired-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('-1 hour'),
    );

    $store->put('test-key', $expired);

    $connector = new TestConnector('test-key', $store, $locker);

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $auth = $connector->getAuthenticator();

    expect($auth)
        ->toBeInstanceOf(AccessTokenAuthenticator::class)
        ->getAccessToken()->toBe('new-token');

    $stored = $store->get('test-key');
    expect($stored)->getAccessToken()->toBe('new-token');

    MockClient::destroyGlobal();
});

it('refreshes a token within the expiry buffer', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $almostExpired = new AccessTokenAuthenticator(
        accessToken: 'almost-expired-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('+2 minutes'),
    );

    $store->put('test-key', $almostExpired);

    $connector = new TestConnector('test-key', $store, $locker, expiryBuffer: 300);

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $auth = $connector->getAuthenticator();

    expect($auth)->getAccessToken()->toBe('refreshed-token');

    MockClient::destroyGlobal();
});

it('returns null when no token exists', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $connector = new TestConnector('missing-key', $store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)->toBeNull();
});

it('throws TokenRevokedException for a revoked token', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $token = new AccessTokenAuthenticator(
        accessToken: 'valid-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('+1 hour'),
    );

    $store->put('test-key', $token);
    $store->revoke('test-key');

    $connector = new TestConnector('test-key', $store, $locker);
    $connector->getAuthenticator();
})->throws(TokenRevokedException::class);

it('wraps refresh failures in TokenAcquisitionFailedException', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $expired = new AccessTokenAuthenticator(
        accessToken: 'expired-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('-1 hour'),
    );

    $store->put('test-key', $expired);

    $connector = new TestConnector('test-key', $store, $locker);

    MockClient::global([
        '*' => MockResponse::make(['error' => 'invalid_grant'], 400),
    ]);

    try {
        $connector->getAuthenticator();
        $this->fail('Expected TokenAcquisitionFailedException');
    } catch (TokenAcquisitionFailedException $e) {
        expect($e->getPrevious())->not->toBeNull();
    } finally {
        MockClient::destroyGlobal();
    }
});

it('does not refresh a token without an expiry date', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $token = new AccessTokenAuthenticator(
        accessToken: 'no-expiry-token',
        refreshToken: 'refresh-token',
        expiresAt: null,
    );

    $store->put('test-key', $token);

    $connector = new TestConnector('test-key', $store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)->getAccessToken()->toBe('no-expiry-token');
});

it('uses a fresh token when another process refreshed inside the lock', function (): void {
    $store = new InMemoryTokenStore;

    $expired = new AccessTokenAuthenticator(
        accessToken: 'expired-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('-1 hour'),
    );

    $store->put('test-key', $expired);

    // A locker that simulates another process refreshing the token before the callback runs.
    $locker = new class($store) implements TokenLocker
    {
        public function __construct(private readonly InMemoryTokenStore $store) {}

        public function lock(string $key, Closure $callback): mixed
        {
            // Simulate another process having refreshed the token.
            $this->store->put('test-key', new AccessTokenAuthenticator(
                accessToken: 'already-refreshed',
                refreshToken: 'new-refresh',
                expiresAt: new DateTimeImmutable('+1 hour'),
            ));

            return $callback();
        }
    };

    $connector = new TestConnector('test-key', $store, $locker);
    $auth = $connector->getAuthenticator();

    // Should use the already-refreshed token, not attempt another refresh.
    expect($auth)->getAccessToken()->toBe('already-refreshed');
});

it('propagates TokenRevokedException when a concurrent revoke lands during refresh', function (): void {
    // Store that simulates a concurrent revoke landing during put(): by the time
    // put() is called (after the refresh HTTP call succeeded), the row is already revoked.
    $store = new class($expired = new AccessTokenAuthenticator(accessToken: 'expired-token', refreshToken: 'refresh-token', expiresAt: new DateTimeImmutable('-1 hour'))) implements TokenStore
    {
        public function __construct(private readonly OAuthAuthenticator $initial) {}

        public function get(string $key): ?OAuthAuthenticator
        {
            return $this->initial;
        }

        public function put(string $key, OAuthAuthenticator $authenticator): void
        {
            throw TokenRevokedException::forKey($key);
        }

        public function revoke(string $key): void {}

        public function forget(string $key): void {}
    };

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connector = new TestConnector('test-key', $store, new NullLocker);

    try {
        $connector->getAuthenticator();
    } finally {
        MockClient::destroyGlobal();
    }
})->throws(TokenRevokedException::class);

it('returns the refreshed token and reports the error when persist fails', function (): void {
    $store = new class implements TokenStore
    {
        public ?OAuthAuthenticator $initial = null;

        public int $putAttempts = 0;

        public function get(string $key): ?OAuthAuthenticator
        {
            return $this->initial;
        }

        public function put(string $key, OAuthAuthenticator $authenticator): void
        {
            $this->putAttempts++;
            throw new RuntimeException('simulated DB outage');
        }

        public function revoke(string $key): void {}

        public function forget(string $key): void {}
    };

    $store->initial = new AccessTokenAuthenticator(
        accessToken: 'expired-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('-1 hour'),
    );

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connector = new TestConnector('test-key', $store, new NullLocker);

    $auth = $connector->getAuthenticator();

    // The persist failed, but the current request still gets the fresh token.
    expect($auth)->getAccessToken()->toBe('new-token');
    expect($store->putAttempts)->toBe(1);

    MockClient::destroyGlobal();
});
