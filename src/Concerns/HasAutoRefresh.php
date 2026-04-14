<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Concerns;

use DateTimeImmutable;
use Saloon\Contracts\Authenticator;
use Saloon\Contracts\OAuthAuthenticator;
use SimpleSquid\SaloonOAuth\Auth\VoidAuthenticator;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRefreshFailedException;
use Throwable;

/** @phpstan-ignore trait.unused */
trait HasAutoRefresh
{
    abstract protected function resolveTokenKey(): string;

    protected function resolveTokenStore(): TokenStore
    {
        return app(TokenStore::class);
    }

    protected function resolveTokenLocker(): TokenLocker
    {
        return app(TokenLocker::class);
    }

    protected function resolveExpiryBuffer(): int
    {
        return config()->integer('saloon-oauth.expiry_buffer', 300);
    }

    protected function defaultAuth(): ?Authenticator
    {
        $key = $this->resolveTokenKey();
        $store = $this->resolveTokenStore();

        $authenticator = $store->get($key);

        if ($authenticator === null) {
            return null;
        }

        if (! $this->isExpired($authenticator)) {
            return $authenticator;
        }

        return $this->resolveTokenLocker()->lock($key, function () use ($key, $store, $authenticator): OAuthAuthenticator {
            // TOCTOU: re-read inside the lock — another process may have already refreshed.
            $fresh = $store->get($key);

            if ($fresh !== null && ! $this->isExpired($fresh)) {
                return $fresh;
            }

            $current = $fresh ?? $authenticator;

            return $this->refreshAndPersist($key, $current, $store);
        });
    }

    private function isExpired(OAuthAuthenticator $authenticator): bool
    {
        $expiresAt = $authenticator->getExpiresAt();

        if ($expiresAt === null) {
            return false;
        }

        $buffer = $this->resolveExpiryBuffer();

        return $expiresAt->getTimestamp() - $buffer <= (new DateTimeImmutable)->getTimestamp();
    }

    private function refreshAndPersist(string $key, OAuthAuthenticator $authenticator, TokenStore $store): OAuthAuthenticator
    {
        try {
            /** @var OAuthAuthenticator $refreshed */
            $refreshed = $this->refreshAccessToken(
                refreshToken: $authenticator,
                requestModifier: function ($request): void {
                    $request->authenticate(new VoidAuthenticator);
                },
            );
        } catch (Throwable $e) {
            throw TokenRefreshFailedException::fromException($e);
        }

        $store->put($key, $refreshed);

        return $refreshed;
    }
}
