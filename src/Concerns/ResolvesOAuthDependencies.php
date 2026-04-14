<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Concerns;

use DateTimeImmutable;
use Saloon\Contracts\OAuthAuthenticator;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;

/** @phpstan-ignore trait.unused */
trait ResolvesOAuthDependencies
{
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

    private function isExpired(OAuthAuthenticator $authenticator): bool
    {
        $expiresAt = $authenticator->getExpiresAt();

        if ($expiresAt === null) {
            return false;
        }

        $buffer = $this->resolveExpiryBuffer();

        return $expiresAt->getTimestamp() - $buffer <= (new DateTimeImmutable)->getTimestamp();
    }
}
