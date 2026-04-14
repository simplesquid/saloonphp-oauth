<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Exceptions;

use RuntimeException;
use Throwable;

class TokenAcquisitionFailedException extends RuntimeException
{
    public static function fromException(Throwable $previous): self
    {
        return new self('Failed to acquire an OAuth token: '.$previous->getMessage(), previous: $previous);
    }
}
