<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Exceptions;

use RuntimeException;

final class LockTimeoutException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Could not acquire lock for OAuth token [{$key}].");
    }
}
