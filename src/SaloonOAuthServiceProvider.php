<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Override;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Contracts\TokenStore;
use SimpleSquid\SaloonOAuth\Exceptions\InvalidCacheStoreException;
use SimpleSquid\SaloonOAuth\Support\CacheTokenLocker;
use SimpleSquid\SaloonOAuth\Support\EloquentTokenStore;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SaloonOAuthServiceProvider extends PackageServiceProvider
{
    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('saloon-oauth')
            ->hasConfigFile()
            ->hasMigration('create_oauth_tokens_table');
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->app->bind(TokenStore::class, EloquentTokenStore::class);

        $this->app->bind(TokenLocker::class, function (): CacheTokenLocker {
            $store = Cache::store(config()->string('saloon-oauth.lock.store'))->getStore();

            if (! $store instanceof LockProvider) {
                throw InvalidCacheStoreException::lockProviderRequired();
            }

            return new CacheTokenLocker(
                $store,
                config()->integer('saloon-oauth.lock.ttl', 30),
                config()->integer('saloon-oauth.lock.wait', 10),
            );
        });
    }
}
