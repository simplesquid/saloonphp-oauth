<?php

declare(strict_types=1);

use Saloon\Contracts\Authenticator;
use Saloon\Http\PendingRequest;
use SimpleSquid\SaloonOAuth\Auth\VoidAuthenticator;

it('implements the Authenticator contract', function (): void {
    expect(new VoidAuthenticator)
        ->toBeInstanceOf(Authenticator::class);
});

it('does nothing when set on a pending request', function (): void {
    $pendingRequest = Mockery::mock(PendingRequest::class);

    $authenticator = new VoidAuthenticator;
    $authenticator->set($pendingRequest);

    // No exception, no interaction — just a no-op.
    expect(true)->toBeTrue();
});
