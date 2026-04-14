<?php

declare(strict_types=1);

use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Exceptions\TokenAcquisitionFailedException;
use SimpleSquid\SaloonOAuth\Support\NullLocker;
use SimpleSquid\SaloonOAuth\Testing\InMemoryTokenStore;
use SimpleSquid\SaloonOAuth\Tests\Fixtures\TestClientCredentialsConnector;

it('returns a valid cached token without re-acquiring', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $token = new AccessTokenAuthenticator(
        accessToken: 'cached-token',
        expiresAt: new DateTimeImmutable('+1 hour'),
    );

    $store->put('test-client-credentials', $token);

    $connector = new TestClientCredentialsConnector($store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)
        ->toBeInstanceOf(AccessTokenAuthenticator::class)
        ->getAccessToken()->toBe('cached-token');
});

it('acquires a new token when none exists', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $connector = new TestClientCredentialsConnector($store, $locker);

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'new-client-token',
            'expires_in' => 3600,
        ]),
    ]);

    $auth = $connector->getAuthenticator();

    expect($auth)
        ->toBeInstanceOf(AccessTokenAuthenticator::class)
        ->getAccessToken()->toBe('new-client-token');

    $stored = $store->get('test-client-credentials');
    expect($stored)->getAccessToken()->toBe('new-client-token');

    MockClient::destroyGlobal();
});

it('acquires a new token when the cached one is expired', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $expired = new AccessTokenAuthenticator(
        accessToken: 'expired-token',
        expiresAt: new DateTimeImmutable('-1 hour'),
    );

    $store->put('test-client-credentials', $expired);

    $connector = new TestClientCredentialsConnector($store, $locker);

    MockClient::global([
        '*' => MockResponse::make([
            'access_token' => 'fresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $auth = $connector->getAuthenticator();

    expect($auth)->getAccessToken()->toBe('fresh-token');

    MockClient::destroyGlobal();
});

it('wraps acquisition failures in TokenAcquisitionFailedException', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $connector = new TestClientCredentialsConnector($store, $locker);

    MockClient::global([
        '*' => MockResponse::make(['error' => 'invalid_client'], 401),
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

it('does not re-acquire a token without an expiry date', function (): void {
    $store = new InMemoryTokenStore;
    $locker = new NullLocker;

    $token = new AccessTokenAuthenticator(
        accessToken: 'no-expiry-token',
        expiresAt: null,
    );

    $store->put('test-client-credentials', $token);

    $connector = new TestClientCredentialsConnector($store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)->getAccessToken()->toBe('no-expiry-token');
});

it('uses a fresh token when another process acquired inside the lock', function (): void {
    $store = new InMemoryTokenStore;

    // A locker that simulates another process acquiring a token before the callback runs.
    $locker = new class($store) implements TokenLocker
    {
        public function __construct(private readonly InMemoryTokenStore $store) {}

        public function lock(string $key, Closure $callback): mixed
        {
            $this->store->put('test-client-credentials', new AccessTokenAuthenticator(
                accessToken: 'already-acquired',
                expiresAt: new DateTimeImmutable('+1 hour'),
            ));

            return $callback();
        }
    };

    $connector = new TestClientCredentialsConnector($store, $locker);
    $auth = $connector->getAuthenticator();

    expect($auth)->getAccessToken()->toBe('already-acquired');
});
