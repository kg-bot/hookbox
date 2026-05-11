<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Enums\AttemptKind;
use Hookbox\Models\WebhookMessage;
use Illuminate\Contracts\Container\Container;

final class WebhookActionRegistry
{
    /**
     * @var list<WebhookActionRegistration>
     */
    private array $registrations = [];

    public function __construct(
        private readonly Container $container,
        HookboxActionRegistrar $registrar,
    ) {
        $this->registrations = $registrar->registrations();
    }

    public function handle(string $provider): WebhookActionBuilder
    {
        return new WebhookActionBuilder(
            register: function (WebhookActionRegistration $registration): void {
                $this->register($registration);
            },
            provider: $provider,
        );
    }

    public function register(WebhookActionRegistration $registration): void
    {
        $this->registrations[] = $registration;
    }

    /**
     * @param  list<WebhookActionRegistration>  $registrations
     */
    public function replace(array $registrations): void
    {
        $this->registrations = $registrations;
    }

    /**
     * @return list<WebhookActionRegistration>
     */
    public function for(SourceDefinition $source, ?string $eventType, bool $replay, bool $dryRun, ?string $triggeredBy): array
    {
        $message = new WebhookMessage;
        $message->source_id = null;
        $message->event_type = $eventType;
        $message->headers = [];
        $message->body = '{}';
        $message->body_hash = hash('sha256', '{}');
        $message->signature_status = 'valid';

        $context = new WebhookActionContext(
            message: $message,
            attempt: null,
            source: $source,
            payload: [],
            kind: $dryRun ? AttemptKind::DRY_RUN : ($replay ? AttemptKind::REPLAY : AttemptKind::INITIAL),
            triggeredBy: $triggeredBy,
        );

        foreach ([
            [$source->slug, $eventType ?? '*'],
            [$source->slug, '*'],
            ['*', $eventType ?? '*'],
            ['*', '*'],
        ] as [$provider, $event]) {
            $matches = array_values(array_filter(
                $this->registrations,
                fn (WebhookActionRegistration $registration): bool => $registration->provider === $provider
                    && $registration->eventType === $event
                    && $registration->matches($context, $this->container),
            ));

            if ($matches !== []) {
                return $matches;
            }
        }

        return [];
    }
}
