<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use SimpleSquid\SaloonOAuth\Support\CacheTokenLocker;

it('executes the callback within a lock and returns the result', function (): void {
    $store = Cache::store()->getStore();

    assert($store instanceof LockProvider);

    $locker = new CacheTokenLocker($store, wait: 10);

    $result = $locker->lock('test-key', fn () => 'locked-result');

    expect($result)->toBe('locked-result');
});
