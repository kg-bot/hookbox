<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Actions;

use Hookbox\Contracts\WebhookActionCondition;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\WebhookActionContext;
use Hookbox\WebhookActionRegistry;

final class WebhookActionRegistryTest extends TestCase
{
    public function test_it_prefers_provider_and_event_specific_matches_before_wildcards(): void
    {
        $registry = $this->appInstance()->make(WebhookActionRegistry::class);
        $source = new SourceDefinition('stripe', 'Stripe', FakeVerifier::class);

        $registry->handle('*')->when(eventType: '*')->through(GlobalAction::class);
        $registry->handle('*')->when(eventType: 'invoice.created')->through(GlobalEventAction::class);
        $registry->handle('stripe')->when(eventType: '*')->through(ProviderAction::class);
        $registry->handle('stripe')->when(eventType: 'invoice.created')->through(ExactAction::class);

        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([ExactAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }

    public function test_it_falls_back_in_documented_wildcard_order(): void
    {
        $registry = $this->appInstance()->make(WebhookActionRegistry::class);
        $source = new SourceDefinition('stripe', 'Stripe', FakeVerifier::class);

        $registry->handle('*')->when(eventType: '*')->through(GlobalAction::class);
        $registry->handle('*')->when(eventType: 'invoice.created')->through(GlobalEventAction::class);
        $registry->handle('stripe')->when(eventType: '*')->through(ProviderAction::class);

        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([ProviderAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));

        $resolved = $registry->for(source: $source, eventType: 'invoice.failed', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([ProviderAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));

        $source = new SourceDefinition('github', 'GitHub', FakeVerifier::class);
        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([GlobalEventAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));

        $resolved = $registry->for(source: $source, eventType: 'deployment.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([GlobalAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }

    public function test_through_appends_actions_and_preserves_duplicates(): void
    {
        $registry = $this->appInstance()->make(WebhookActionRegistry::class);
        $source = new SourceDefinition('stripe', 'Stripe', FakeVerifier::class);

        $registry->handle('stripe')->when(eventType: 'invoice.created')
            ->through(FirstAction::class)
            ->through(SecondAction::class)
            ->through(FirstAction::class);

        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([
            FirstAction::class,
            SecondAction::class,
            FirstAction::class,
        ], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }

    public function test_it_filters_out_actions_whose_conditions_do_not_match(): void
    {
        $registry = $this->appInstance()->make(WebhookActionRegistry::class);
        $source = new SourceDefinition('stripe', 'Stripe', FakeVerifier::class);

        $registry->handle('stripe')->when(eventType: 'invoice.created')
            ->through(ExactAction::class)
            ->through(ReplayOnlyAction::class, ReplayOnlyCondition::class)
            ->through(TriggeredByAction::class, static fn ($context): bool => $context->triggeredBy() === 'console');

        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: false, dryRun: false, triggeredBy: null);

        $this->assertSame([ExactAction::class], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));

        $resolved = $registry->for(source: $source, eventType: 'invoice.created', replay: true, dryRun: true, triggeredBy: 'console');

        $this->assertSame([
            ExactAction::class,
            ReplayOnlyAction::class,
            TriggeredByAction::class,
        ], array_map(
            static fn ($registration): string => $registration->action,
            $resolved,
        ));
    }
}

final class FakeVerifier {}

final class ExactAction {}

final class ProviderAction {}

final class GlobalEventAction {}

final class GlobalAction {}

final class FirstAction {}

final class SecondAction {}

final class ReplayOnlyAction {}

final class TriggeredByAction {}

final class ReplayOnlyCondition implements WebhookActionCondition
{
    public function matches(WebhookActionContext $context): bool
    {
        return $context->isReplay();
    }
}
