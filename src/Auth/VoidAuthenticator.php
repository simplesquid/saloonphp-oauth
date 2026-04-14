<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Auth;

use Override;
use Saloon\Contracts\Authenticator;
use Saloon\Http\PendingRequest;

final readonly class VoidAuthenticator implements Authenticator
{
    #[Override]
    public function set(PendingRequest $pendingRequest): void
    {
        // Intentionally empty — used to prevent inheriting the connector's auth on refresh requests.
    }
}
