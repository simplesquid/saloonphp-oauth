<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Support;

use Closure;
use Override;
use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;

final readonly class NullLocker implements TokenLocker
{
    #[Override]
    public function lock(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}
