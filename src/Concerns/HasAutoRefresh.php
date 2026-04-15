<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Concerns;

use Saloon\Contracts\Authenticator;
use Saloon\Contracts\OAuthAuthenticator;
use SimpleSquid\SaloonOAuth\Auth\VoidAuthenticator;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\TokenAcquisitionFailedException;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;
use Throwable;

/** @phpstan-ignore trait.unused */
trait HasAutoRefresh
{
    use ResolvesOAuthDependencies;

    abstract protected function resolveTokenKey(): string;

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

    private function refreshAndPersist(string $key, OAuthAuthenticator $authenticator, TokenStore $store): OAuthAuthenticator
    {
        try {
            $refreshed = $this->refreshAccessToken(
                refreshToken: $authenticator,
                requestModifier: function ($request): void {
                    $request->authenticate(new VoidAuthenticator);
                },
            );
        } catch (Throwable $e) {
            throw TokenAcquisitionFailedException::fromException($e);
        }

        if (! $refreshed instanceof OAuthAuthenticator) {
            throw new TokenAcquisitionFailedException('Token refresh did not return an OAuthAuthenticator.');
        }

        // Persist best-effort. A TokenRevokedException means a concurrent revoke() landed
        // while we were refreshing — propagate it so the current request fails too.
        // Any other persist failure (DB outage, etc.) is logged; the current request still
        // succeeds with the fresh token. The next request will retry the refresh, which
        // will fail if the provider rotated the refresh token — nothing we can do there.
        try {
            $store->put($key, $refreshed);
        } catch (TokenRevokedException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
        }

        return $refreshed;
    }
}
