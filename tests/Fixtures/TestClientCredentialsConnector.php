<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Tests\Fixtures;

use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use SimpleSquid\SaloonOAuth\Concerns\HasClientCredentialsCache;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;

class TestClientCredentialsConnector extends Connector
{
    use ClientCredentialsGrant;
    use HasClientCredentialsCache;

    public function __construct(
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
        return 'test-client-credentials';
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
            ->setClientSecret('test-client-secret');
    }
}
