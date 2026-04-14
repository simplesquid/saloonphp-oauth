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
trait HasClientCredentialsCache
{
    protected function resolveTokenKey(): string
    {
        return static::class;
    }

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

        if ($authenticator !== null && ! $this->isExpired($authenticator)) {
            return $authenticator;
        }

        return $this->resolveTokenLocker()->lock($key, function () use ($key, $store): OAuthAuthenticator {
            // TOCTOU: re-read inside the lock — another process may have already acquired a token.
            $fresh = $store->get($key);

            if ($fresh !== null && ! $this->isExpired($fresh)) {
                return $fresh;
            }

            return $this->acquireAndPersist($key, $store);
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

    private function acquireAndPersist(string $key, TokenStore $store): OAuthAuthenticator
    {
        try {
            /** @var OAuthAuthenticator $authenticator */
            $authenticator = $this->getAccessToken(
                requestModifier: function ($request): void {
                    $request->authenticate(new VoidAuthenticator);
                },
            );
        } catch (Throwable $e) {
            throw TokenRefreshFailedException::fromException($e);
        }

        $store->put($key, $authenticator);

        return $authenticator;
    }
}
