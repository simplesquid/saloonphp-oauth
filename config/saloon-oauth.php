<?php

declare(strict_types=1);
use SimpleSquid\SaloonOAuth\Models\OAuthToken;

return [
    'table' => 'oauth_tokens',
    'model' => OAuthToken::class,
    'lock' => [
        'store' => null,
        'wait' => 10,
    ],
    'expiry_buffer' => 300,
];
