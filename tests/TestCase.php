<?php

declare(strict_types=1);

namespace Hookbox\Tests;

use Hookbox\HookboxServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return class_exists(HookboxServiceProvider::class)
            ? [HookboxServiceProvider::class]
            : [];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', $_SERVER['DB_CONNECTION'] ?? 'sqlite');

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $_SERVER['DB_DATABASE'] ?? ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => $_SERVER['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_SERVER['DB_PORT'] ?? 3306),
            'database' => $_SERVER['DB_DATABASE'] ?? 'hookbox',
            'username' => $_SERVER['DB_USERNAME'] ?? 'root',
            'password' => $_SERVER['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
        ]);

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => $_SERVER['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_SERVER['DB_PORT'] ?? 5432),
            'database' => $_SERVER['DB_DATABASE'] ?? 'hookbox',
            'username' => $_SERVER['DB_USERNAME'] ?? 'postgres',
            'password' => $_SERVER['DB_PASSWORD'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function runPackageMigrations(): void
    {
        Schema::dropAllTables();

        $result = $this->artisan('migrate', [
            '--database' => DB::getDefaultConnection(),
            '--path' => dirname(__DIR__).'/database/migrations',
            '--realpath' => true,
        ]);

        if ($result instanceof PendingCommand) {
            $result->assertExitCode(0);

            return;
        }

        $this->assertSame(0, $result);
    }

    protected function appInstance(): Application
    {
        return $this->app ?? throw new \RuntimeException('Application has not been booted.');
    }
}
