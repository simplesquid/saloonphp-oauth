<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $key
 * @property string $access_token
 * @property string|null $refresh_token
 * @property DateTimeImmutable|null $expires_at
 * @property DateTimeImmutable|null $revoked_at
 */
final class OAuthToken extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'access_token',
        'refresh_token',
        'expires_at',
        'revoked_at',
    ];

    #[Override]
    public function getTable(): string
    {
        return config()->string('saloon-oauth.table', 'oauth_tokens');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
