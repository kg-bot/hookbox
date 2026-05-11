<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Console\Commands\HookboxInstallUiCommand;
use Hookbox\Console\Commands\HookboxPruneCommand;
use Hookbox\Contracts\VerifierTransport;
use Hookbox\Repositories\MessageRepository;
use Hookbox\Repositories\SourceRepository;
use Hookbox\Support\ShellCommandRunner;
use Hookbox\Verifiers\Support\VerifierHttpClient;
use Illuminate\Support\ServiceProvider;

final class HookboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $packageRoot = dirname(__DIR__);
        $packageConfig = require $packageRoot.'/config/hookbox.php';
        $config = $this->app->make('config');

        $this->mergeConfigFrom($packageRoot.'/config/hookbox.php', 'hookbox');

        $config->set('hookbox.queue', array_merge(
            $packageConfig['queue'],
            $config->get('hookbox.queue', []),
        ));

        $this->app->singleton(HookboxActionRegistrar::class);
        $this->app->bind(VerifierTransport::class, VerifierHttpClient::class);
        $this->app->scoped(SourceRegistry::class);
        $this->app->scoped(WebhookActionRegistry::class);
        $this->app->scoped(WebhookActionRunner::class);
        $this->app->scoped(ReplayService::class);
        $this->app->scoped(MessageRepository::class);
        $this->app->scoped(SourceRepository::class);
        $this->app->singleton(ShellCommandRunner::class);
    }

    public function boot(): void
    {
        $packageRoot = dirname(__DIR__);
        $publishedRoutesPath = base_path('routes/hookbox.php');

        $this->loadMigrationsFrom($packageRoot.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                HookboxInstallUiCommand::class,
                HookboxPruneCommand::class,
            ]);
        }

        $this->publishes([
            $packageRoot.'/config/hookbox.php' => config_path('hookbox.php'),
        ], 'hookbox-config');

        $this->publishes([
            $packageRoot.'/database/migrations' => database_path('migrations'),
        ], 'hookbox-migrations');

        $this->publishes([
            $packageRoot.'/routes/hookbox.php' => $publishedRoutesPath,
        ], 'hookbox-routes');

        $this->loadRoutesFrom(file_exists($publishedRoutesPath)
            ? $publishedRoutesPath
            : $packageRoot.'/routes/hookbox.php');
    }
}
