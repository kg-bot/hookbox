<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Actions;

use Hookbox\Facades\Hookbox;
use Hookbox\HookboxActionRegistrar;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\WebhookActionRegistry;

final class HookboxActionRegistrarTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->app !== null && $this->app->bound(HookboxActionRegistrar::class)) {
            $this->app->make(HookboxActionRegistrar::class)->flush();
        }

        parent::tearDown();
    }

    public function test_facade_registration_hydrates_runtime_registry(): void
    {
        Hookbox::handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(FacadeRegisteredAction::class);

        $resolved = $this->appInstance()->make(WebhookActionRegistry::class)->for(
            source: new SourceDefinition('stripe', 'Stripe', FacadeRegisteredAction::class),
            eventType: 'invoice.created',
            replay: false,
            dryRun: false,
            triggeredBy: null,
        );

        $this->assertSame([FacadeRegisteredAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }

    public function test_public_registrations_survive_scoped_registry_flushes(): void
    {
        Hookbox::handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(FacadeRegisteredAction::class);

        $firstRegistry = $this->appInstance()->make(WebhookActionRegistry::class);

        $this->appInstance()->forgetScopedInstances();

        $secondRegistry = $this->appInstance()->make(WebhookActionRegistry::class);

        $this->assertNotSame($firstRegistry, $secondRegistry);

        $resolved = $secondRegistry->for(
            source: new SourceDefinition('stripe', 'Stripe', FacadeRegisteredAction::class),
            eventType: 'invoice.created',
            replay: false,
            dryRun: false,
            triggeredBy: null,
        );

        $this->assertSame([FacadeRegisteredAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }

    public function test_runtime_registry_mutations_still_do_not_survive_scoped_flushes(): void
    {
        $registry = $this->appInstance()->make(WebhookActionRegistry::class);
        $registry->handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(RuntimeOnlyAction::class);

        $this->appInstance()->forgetScopedInstances();

        $refreshedRegistry = $this->appInstance()->make(WebhookActionRegistry::class);
        $resolved = $refreshedRegistry->for(
            source: new SourceDefinition('stripe', 'Stripe', RuntimeOnlyAction::class),
            eventType: 'invoice.created',
            replay: false,
            dryRun: false,
            triggeredBy: null,
        );

        $this->assertSame([], $resolved);
    }

    public function test_registrar_can_be_used_directly_without_the_facade(): void
    {
        $this->appInstance()->make(HookboxActionRegistrar::class)
            ->handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(RegistrarRegisteredAction::class);

        $resolved = $this->appInstance()->make(WebhookActionRegistry::class)->for(
            source: new SourceDefinition('stripe', 'Stripe', RegistrarRegisteredAction::class),
            eventType: 'invoice.created',
            replay: false,
            dryRun: false,
            triggeredBy: null,
        );

        $this->assertSame([RegistrarRegisteredAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }
}

final class FacadeRegisteredAction {}

final class RegistrarRegisteredAction {}

final class RuntimeOnlyAction {}
