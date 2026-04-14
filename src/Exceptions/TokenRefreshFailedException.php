<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Exceptions;

use RuntimeException;
use Throwable;

final class TokenRefreshFailedException extends RuntimeException
{
    public static function fromException(Throwable $previous): self
    {
        return new self('Failed to refresh the OAuth token: '.$previous->getMessage(), previous: $previous);
    }
}
