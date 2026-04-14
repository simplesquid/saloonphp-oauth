<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Exceptions;

use Illuminate\Contracts\Cache\LockProvider;
use RuntimeException;

class InvalidCacheStoreException extends RuntimeException
{
    public static function lockProviderRequired(): self
    {
        return new self(
            'The cache store configured for [saloon-oauth.lock.store] must implement '
            .LockProvider::class.'. Stores like "file" do not support locking; use "redis", "memcached", "dynamodb", or "database" instead.',
        );
    }
}
