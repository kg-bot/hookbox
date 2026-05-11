<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature;

use Hookbox\HookboxServiceProvider;
use Hookbox\Http\WebhookController;
use Hookbox\Tests\TestCase;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

final class HookboxServiceProviderTest extends TestCase
{
    public function test_it_merges_default_configuration(): void
    {
        $this->assertSame('webhooks', config('hookbox.route_prefix'));
        $this->assertSame([
            'connection' => null,
            'name' => null,
        ], config('hookbox.queue'));
        $this->assertTrue(config('hookbox.store_invalid_signatures'));
        $this->assertSame([], config('hookbox.sources'));
    }

    public function test_it_preserves_nested_queue_defaults_when_app_overrides_queue_name(): void
    {
        config()->set('hookbox', [
            'queue' => [
                'name' => 'incoming-webhooks',
            ],
        ]);

        (new HookboxServiceProvider($this->appInstance()))->register();

        $this->assertSame([
            'connection' => null,
            'name' => 'incoming-webhooks',
        ], config('hookbox.queue'));
        $this->assertSame('webhooks', config('hookbox.route_prefix'));
    }

    public function test_it_registers_publishable_resource_groups(): void
    {
        $packageRoot = dirname(__DIR__, 2);

        $configPaths = ServiceProvider::pathsToPublish(HookboxServiceProvider::class, 'hookbox-config');
        $migrationPaths = ServiceProvider::pathsToPublish(HookboxServiceProvider::class, 'hookbox-migrations');
        $routePaths = ServiceProvider::pathsToPublish(HookboxServiceProvider::class, 'hookbox-routes');

        $this->assertSame(config_path('hookbox.php'), $configPaths[$packageRoot.'/config/hookbox.php'] ?? null);
        $this->assertSame(database_path('migrations'), $migrationPaths[$packageRoot.'/database/migrations'] ?? null);
        $this->assertSame(base_path('routes/hookbox.php'), $routePaths[$packageRoot.'/routes/hookbox.php'] ?? null);
    }

    public function test_it_loads_package_migrations_without_publishing_them(): void
    {
        $packageRoot = dirname(__DIR__, 2);

        /** @var Migrator $migrator */
        $migrator = $this->appInstance()->make('migrator');

        $this->assertContains($packageRoot.'/database/migrations', $migrator->paths());
    }

    public function test_it_registers_the_receiver_route(): void
    {
        /** @var Route|null $route */
        $route = app('router')->getRoutes()->getByName('hookbox.receive');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('webhooks/{source}', $route->uri());
        $this->assertSame(WebhookController::class, $route->getActionName());
    }
}
