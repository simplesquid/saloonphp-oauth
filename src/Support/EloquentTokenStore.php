<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Support;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\TokenRevokedException;
use SimpleSquid\SaloonOAuth\Models\OAuthToken;

final readonly class EloquentTokenStore implements TokenStore
{
    #[Override]
    public function get(string $key): ?OAuthAuthenticator
    {
        $token = $this->query()->where('key', $key)->first();

        if ($token === null) {
            return null;
        }

        if ($token->revoked_at !== null) {
            throw TokenRevokedException::forKey($key);
        }

        return new AccessTokenAuthenticator(
            accessToken: $token->access_token,
            refreshToken: $token->refresh_token,
            expiresAt: $token->expires_at instanceof DateTimeImmutable
                ? $token->expires_at
                : null,
        );
    }

    #[Override]
    public function put(string $key, OAuthAuthenticator $authenticator): void
    {
        $this->query()->updateOrCreate(
            ['key' => $key],
            [
                'access_token' => $authenticator->getAccessToken(),
                'refresh_token' => $authenticator->getRefreshToken(),
                'expires_at' => $authenticator->getExpiresAt(),
                'revoked_at' => null,
            ],
        );
    }

    #[Override]
    public function revoke(string $key): void
    {
        $token = $this->query()->where('key', $key)->whereNull('revoked_at')->first();

        $token?->fill([
            'access_token' => '',
            'refresh_token' => null,
            'revoked_at' => now(),
        ])->save();
    }

    #[Override]
    public function forget(string $key): void
    {
        $this->query()->where('key', $key)->delete();
    }

    /** @return Builder<OAuthToken> */
    private function query(): Builder
    {
        /** @var class-string<OAuthToken> $modelClass */
        $modelClass = config()->string('saloon-oauth.model', OAuthToken::class);

        return $modelClass::query();
    }
}
