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
trait HasClientCredentialsCache
{
    use ResolvesOAuthDependencies;

    protected function resolveTokenKey(): string
    {
        return static::class;
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

    private function acquireAndPersist(string $key, TokenStore $store): OAuthAuthenticator
    {
        try {
            $authenticator = $this->getAccessToken(
                requestModifier: function ($request): void {
                    $request->authenticate(new VoidAuthenticator);
                },
            );
        } catch (Throwable $e) {
            throw TokenAcquisitionFailedException::fromException($e);
        }

        if (! $authenticator instanceof OAuthAuthenticator) {
            throw new TokenAcquisitionFailedException('Token acquisition did not return an OAuthAuthenticator.');
        }

        // Persist best-effort. A TokenRevokedException means a concurrent revoke() landed
        // while we were acquiring — propagate it so the current request fails too. Any
        // other persist failure is logged and the current request proceeds; client
        // credentials can always be re-acquired on the next request.
        try {
            $store->put($key, $authenticator);
        } catch (TokenRevokedException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
        }

        return $authenticator;
    }
}
