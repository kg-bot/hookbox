<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Enums\AttemptKind;
use Hookbox\Enums\AttemptStatus;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookSource;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Carbon;
use Throwable;

final class WebhookActionRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly WebhookActionRegistry $registry,
    ) {}

    public function run(
        WebhookMessage $message,
        WebhookSource $source,
        AttemptKind $kind,
        ?string $triggeredBy = null,
    ): ActionExecutionResult {
        $attempt = new WebhookAttempt;
        $attempt->message_id = (string) $message->getKey();
        $attempt->kind = $kind->value;
        $attempt->handler = 'unmatched';
        $attempt->status = AttemptStatus::PENDING->value;
        $attempt->triggered_by = $triggeredBy;
        $attempt->started_at = Carbon::now();
        $attempt->save();

        $startedAt = microtime(true);

        try {
            $registrations = $this->registry->for(
                source: new SourceDefinition(
                    slug: $source->slug,
                    name: $source->name,
                    verifier: $source->verifier,
                    config: $source->config,
                ),
                eventType: $message->event_type,
                replay: $kind === AttemptKind::REPLAY || $kind === AttemptKind::DRY_RUN,
                dryRun: $kind === AttemptKind::DRY_RUN,
                triggeredBy: $triggeredBy,
            );

            if ($registrations === []) {
                $attempt->status = AttemptStatus::SKIPPED->value;

                return $this->finish($attempt, $startedAt, false);
            }

            $payload = json_decode($message->body, true);

            $context = new WebhookActionContext(
                message: $message,
                attempt: $attempt,
                source: $source,
                payload: is_array($payload) ? $payload : [],
                kind: $kind,
                triggeredBy: $triggeredBy,
            );

            $pipes = array_map(function (WebhookActionRegistration $registration) use ($attempt): object {
                return new class($this->container, $registration, $attempt)
                {
                    public function __construct(
                        private readonly Container $container,
                        private readonly WebhookActionRegistration $registration,
                        private readonly WebhookAttempt $attempt,
                    ) {}

                    public function handle(WebhookActionContext $context, \Closure $next): mixed
                    {
                        $this->attempt->handler = $this->registration->action;
                        $this->attempt->save();

                        $action = $this->container->make($this->registration->action);

                        return $action->handle($context, $next);
                    }
                };
            }, $registrations);

            $this->container->make(Pipeline::class)
                ->send($context)
                ->through($pipes)
                ->thenReturn();

            $attempt->status = AttemptStatus::SUCCEEDED->value;

            return $this->finish($attempt, $startedAt, true);
        } catch (Throwable $throwable) {
            $attempt->status = AttemptStatus::FAILED->value;
            $attempt->error_class = $throwable::class;
            $attempt->error_message = $throwable->getMessage();
            $attempt->error_trace = $throwable->getTraceAsString();

            return $this->finish($attempt, $startedAt, true);
        }
    }

    private function finish(WebhookAttempt $attempt, float $startedAt, bool $matched): ActionExecutionResult
    {
        $attempt->finished_at = Carbon::now();
        $attempt->duration_ms = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $attempt->save();

        return new ActionExecutionResult(
            attempt: $attempt->fresh() ?? $attempt,
            matched: $matched,
        );
    }
}
