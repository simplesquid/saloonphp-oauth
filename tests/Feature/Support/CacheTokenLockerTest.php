<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use SimpleSquid\SaloonOAuth\Exceptions\LockTimeoutException;
use SimpleSquid\SaloonOAuth\Support\CacheTokenLocker;

it('executes the callback within a lock and returns the result', function (): void {
    $store = Cache::store()->getStore();
    assert($store instanceof LockProvider);

    $locker = new CacheTokenLocker($store, ttl: 30, wait: 10);

    $result = $locker->lock('test-key', fn () => 'locked-result');

    expect($result)->toBe('locked-result');
});

it('throws LockTimeoutException when the lock cannot be acquired', function (): void {
    $store = Cache::store()->getStore();
    assert($store instanceof LockProvider);

    // Acquire the lock externally so the locker cannot get it.
    $externalLock = $store->lock('saloon-oauth:contested-key', 30);
    $externalLock->acquire();

    $locker = new CacheTokenLocker($store, ttl: 30, wait: 1);

    try {
        $locker->lock('contested-key', fn () => 'should-not-reach');
    } finally {
        $externalLock->release();
    }
})->throws(LockTimeoutException::class);
