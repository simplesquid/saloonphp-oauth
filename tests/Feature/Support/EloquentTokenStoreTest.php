<?php

declare(strict_types=1);

use Saloon\Http\Auth\AccessTokenAuthenticator;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;
use SimpleSquid\SaloonOAuth\Models\OAuthToken;
use SimpleSquid\SaloonOAuth\Support\EloquentTokenStore;

it('returns null for a missing key', function (): void {
    $store = new EloquentTokenStore;

    expect($store->get('nonexistent'))->toBeNull();
});

it('stores and retrieves a token', function (): void {
    $store = new EloquentTokenStore;

    $authenticator = new AccessTokenAuthenticator(
        accessToken: 'access-123',
        refreshToken: 'refresh-456',
        expiresAt: new DateTimeImmutable('2025-06-01 12:00:00'),
    );

    $store->put('test-key', $authenticator);

    $result = $store->get('test-key');

    expect($result)
        ->toBeInstanceOf(AccessTokenAuthenticator::class)
        ->getAccessToken()->toBe('access-123')
        ->and($result->getRefreshToken())->toBe('refresh-456')
        ->and($result->getExpiresAt())->toBeInstanceOf(DateTimeImmutable::class);
});

it('updates an existing token', function (): void {
    $store = new EloquentTokenStore;

    $store->put('test-key', new AccessTokenAuthenticator('first-token'));
    $store->put('test-key', new AccessTokenAuthenticator('second-token'));

    expect($store->get('test-key'))->getAccessToken()->toBe('second-token');
    expect(OAuthToken::count())->toBe(1);
});

it('revokes a token', function (): void {
    $store = new EloquentTokenStore;

    $store->put('test-key', new AccessTokenAuthenticator('token'));
    $store->revoke('test-key');

    $store->get('test-key');
})->throws(TokenRevokedException::class);

it('clears revoked state when putting a new token', function (): void {
    $store = new EloquentTokenStore;

    $store->put('test-key', new AccessTokenAuthenticator('old-token'));
    $store->revoke('test-key');
    $store->put('test-key', new AccessTokenAuthenticator('new-token'));

    expect($store->get('test-key'))->getAccessToken()->toBe('new-token');
});

it('forgets a token', function (): void {
    $store = new EloquentTokenStore;

    $store->put('test-key', new AccessTokenAuthenticator('token'));
    $store->forget('test-key');

    expect($store->get('test-key'))->toBeNull();
});

it('handles revoking a nonexistent key gracefully', function (): void {
    $store = new EloquentTokenStore;

    $store->revoke('nonexistent');

    expect($store->get('nonexistent'))->toBeNull();
});

it('handles forgetting a nonexistent key gracefully', function (): void {
    $store = new EloquentTokenStore;

    $store->forget('nonexistent');

    expect(true)->toBeTrue();
});
