<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Contracts;

use Closure;

interface TokenLocker
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lock(string $key, Closure $callback): mixed;
}
