<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Exceptions;

use RuntimeException;

class TokenRevokedException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("The OAuth token for [{$key}] has been revoked.");
    }
}
