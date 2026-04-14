<?php

declare(strict_types=1);

use SimpleSquid\SaloonOAuth\Contracts\TokenLocker;
use SimpleSquid\SaloonOAuth\Support\NullLocker;

it('executes the callback and returns its result', function (): void {
    $locker = new NullLocker;

    $result = $locker->lock('test-key', fn () => 'hello');

    expect($result)->toBe('hello');
});

it('implements the TokenLocker contract', function (): void {
    expect(new NullLocker)
        ->toBeInstanceOf(TokenLocker::class);
});
