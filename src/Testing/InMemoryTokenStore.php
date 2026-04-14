<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Testing;

use Override;
use Saloon\Contracts\OAuthAuthenticator;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;

final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, OAuthAuthenticator> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $revoked = [];

    #[Override]
    public function get(string $key): ?OAuthAuthenticator
    {
        if (isset($this->revoked[$key])) {
            throw TokenRevokedException::forKey($key);
        }

        return $this->tokens[$key] ?? null;
    }

    #[Override]
    public function put(string $key, OAuthAuthenticator $authenticator): void
    {
        $this->tokens[$key] = $authenticator;
        unset($this->revoked[$key]);
    }

    #[Override]
    public function revoke(string $key): void
    {
        if (isset($this->tokens[$key])) {
            $this->revoked[$key] = true;
        }
    }

    #[Override]
    public function forget(string $key): void
    {
        unset($this->tokens[$key], $this->revoked[$key]);
    }
}
