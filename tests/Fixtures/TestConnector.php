<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Tests\Fixtures;

use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use SimpleSquid\SaloonOAuth\Concerns\HasAutoRefresh;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;

class TestConnector extends Connector
{
    use AuthorizationCodeGrant;
    use HasAutoRefresh;

    public function __construct(
        private readonly string $tokenKey,
        private readonly TokenStore $store,
        private readonly TokenLocker $locker,
        private readonly int $expiryBuffer = 300,
    ) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.test';
    }

    protected function resolveTokenKey(): string
    {
        return $this->tokenKey;
    }

    protected function resolveTokenStore(): TokenStore
    {
        return $this->store;
    }

    protected function resolveTokenLocker(): TokenLocker
    {
        return $this->locker;
    }

    protected function resolveExpiryBuffer(): int
    {
        return $this->expiryBuffer;
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId('test-client-id')
            ->setClientSecret('test-client-secret')
            ->setRedirectUri('https://example.test/callback');
    }
}
