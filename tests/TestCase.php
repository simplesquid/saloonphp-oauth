<?php

declare(strict_types=1);

namespace SimpleSquid\SaloonOAuth\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;
use SimpleSquid\SaloonOAuth\SaloonOAuthServiceProvider;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            SaloonOAuthServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_oauth_tokens_table.php.stub';
        $migration->up();
    }
}
