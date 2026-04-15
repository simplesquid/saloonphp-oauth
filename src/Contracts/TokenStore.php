<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Contracts;

use Saloon\Contracts\OAuthAuthenticator;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;

interface TokenStore
{
    /** @throws TokenRevokedException */
    public function get(string $key): ?OAuthAuthenticator;

    /** @throws TokenRevokedException when the key exists and is revoked. Call forget() first to re-use a revoked key. */
    public function put(string $key, OAuthAuthenticator $authenticator): void;

    public function revoke(string $key): void;

    public function forget(string $key): void;
}
