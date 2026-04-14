<?php

declare(strict_types=1);

use SimpleSquid\SaloonOAuth\Models\OAuthToken;

return [

    /*
    |--------------------------------------------------------------------------
    | Database Table
    |--------------------------------------------------------------------------
    |
    | The table name used by the OAuthToken model. You may change this if
    | "oauth_tokens" conflicts with an existing table in your application.
    |
    */

    'table' => 'oauth_tokens',

    /*
    |--------------------------------------------------------------------------
    | Token Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used by the EloquentTokenStore. You may swap this
    | for your own model as long as it extends the default OAuthToken model.
    |
    */

    'model' => OAuthToken::class,

    /*
    |--------------------------------------------------------------------------
    | Distributed Lock
    |--------------------------------------------------------------------------
    |
    | Tokens are refreshed inside a distributed lock to prevent concurrent
    | processes from refreshing the same token simultaneously.
    |
    | "store" — the cache store used for locking. Must implement LockProvider.
    |           null uses the default cache store. Stores like "file" do NOT
    |           support locking; use "redis", "memcached", "dynamodb", or
    |           "database" instead.
    | "ttl"  — how long the lock is held before auto-releasing (seconds).
    |           Should be longer than the slowest expected token refresh.
    | "wait" — how long to block waiting to acquire the lock (seconds).
    |
    */

    'lock' => [
        'store' => null,
        'ttl' => 30,
        'wait' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry Buffer
    |--------------------------------------------------------------------------
    |
    | Number of seconds before a token's actual expiry time to treat it as
    | expired and trigger a refresh. A buffer of 300 means tokens are
    | refreshed 5 minutes before they actually expire.
    |
    */

    'expiry_buffer' => 300,

];
