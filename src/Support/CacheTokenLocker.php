<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException as CacheLockTimeoutException;
use Override;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Exceptions\LockTimeoutException;

final readonly class CacheTokenLocker implements TokenLocker
{
    public function __construct(
        private LockProvider $store,
        private int $wait,
    ) {}

    #[Override]
    public function lock(string $key, Closure $callback): mixed
    {
        $lock = $this->store->lock("saloon-oauth:{$key}", $this->wait);

        try {
            return $lock->block($this->wait, $callback);
        } catch (CacheLockTimeoutException) {
            throw LockTimeoutException::forKey($key);
        }
    }
}
