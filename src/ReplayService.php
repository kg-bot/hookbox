<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\AttemptKind;
use Hookbox\Enums\VerificationStatus;
use Hookbox\Events\WebhookReplayed;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookMessageReceipt;
use Hookbox\Models\WebhookSource;
use Hookbox\Support\EventViewMapper;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReplayService
{
    public function __construct(
        private readonly Container $container,
        private readonly WebhookActionRegistry $registry,
        private readonly WebhookActionRunner $runner,
        private readonly EventViewMapper $eventViewMapper,
    ) {}

    public function replay(WebhookMessage|string $messageOrId, ReplayOptions $options): WebhookAttempt
    {
        $message = $messageOrId instanceof WebhookMessage
            ? $messageOrId
            : WebhookMessage::query()->findOrFail($messageOrId);

        $source = WebhookSource::query()->find($message->source_id);

        if ($source === null) {
            throw new RuntimeException('Replay source is missing.');
        }

        try {
            if ($options->forceReverify) {
                $this->reverify($message);
            }

            $attempt = $this->runReplayActions($message, $source, $options);
        } catch (Throwable $throwable) {
            $attempt = new WebhookAttempt;
            $attempt->message_id = (string) $message->getKey();
            $attempt->kind = $options->dryRun ? AttemptKind::DRY_RUN->value : AttemptKind::REPLAY->value;
            $attempt->handler = 'reverify';
            $attempt->triggered_by = $options->triggeredBy;
            $attempt->status = 'failed';
            $attempt->error_class = $throwable::class;
            $attempt->error_message = $throwable->getMessage();
            $attempt->error_trace = $throwable->getTraceAsString();
            $attempt->started_at = now();
            $attempt->finished_at = now();
            $attempt->duration_ms = 0;
            $attempt->save();
            $attempt = $attempt->fresh() ?? $attempt;
        }

        event(new WebhookReplayed(
            $this->eventViewMapper->message($message, $source),
            $this->eventViewMapper->attempt($attempt),
        ));

        return $attempt;
    }

    private function runReplayActions(WebhookMessage $message, WebhookSource $source, ReplayOptions $options): WebhookAttempt
    {
        if ($options->actionsFilter === null) {
            return $this->runner->run(
                message: $message,
                source: $source,
                kind: $options->dryRun ? AttemptKind::DRY_RUN : AttemptKind::REPLAY,
                triggeredBy: $options->triggeredBy,
            )->attempt;
        }

        $matchedRegistrations = $this->registry->for(
            source: new SourceDefinition(
                slug: $source->slug,
                name: $source->name,
                verifier: $source->verifier,
                config: $source->config,
            ),
            eventType: $message->event_type,
            replay: true,
            dryRun: $options->dryRun,
            triggeredBy: $options->triggeredBy,
        );

        $filteredRegistrations = [];

        foreach ($matchedRegistrations as $registration) {
            if (! in_array($registration->action, $options->actionsFilter, true)) {
                continue;
            }

            $filteredRegistrations[] = $registration;
        }

        $filteredRegistry = $this->container->make(WebhookActionRegistry::class);
        $filteredRegistry->replace($filteredRegistrations);

        $runner = new WebhookActionRunner($this->container, $filteredRegistry);

        return $runner->run(
            message: $message,
            source: $source,
            kind: $options->dryRun ? AttemptKind::DRY_RUN : AttemptKind::REPLAY,
            triggeredBy: $options->triggeredBy,
        )->attempt;
    }

    private function reverify(WebhookMessage $message): void
    {
        $source = WebhookSource::query()->findOrFail($message->source_id);
        $receipt = WebhookMessageReceipt::query()
            ->whereKey((string) $message->getKey())
            ->first();

        if ($receipt === null) {
            throw new RuntimeException('Replay receipt is missing; cannot safely re-verify replay.');
        }

        $definition = new SourceDefinition(
            slug: $source->slug,
            name: $source->name,
            verifier: $source->verifier,
            config: $source->config,
        );

        $verifier = $this->container->make($definition->verifier);

        if (! $verifier instanceof Verifier) {
            throw new InvalidArgumentException(sprintf(
                'Verifier [%s] must implement [%s].',
                $definition->verifier,
                Verifier::class,
            ));
        }

        $request = Request::create($receipt->url, $receipt->method, server: [
            'CONTENT_TYPE' => $this->headerValue($receipt->headers, 'content-type') ?? 'application/json',
            'REMOTE_ADDR' => $receipt->client_ip,
        ], content: $receipt->body);

        foreach ($receipt->headers as $name => $values) {
            $normalizedValues = [];

            if (is_array($values)) {
                foreach ($values as $value) {
                    if (! is_scalar($value)) {
                        continue;
                    }

                    $normalizedValues[] = (string) $value;
                }
            } elseif (is_scalar($values)) {
                $normalizedValues[] = (string) $values;
            }

            if ($normalizedValues === [] || $normalizedValues === ['']) {
                continue;
            }

            $request->headers->set((string) $name, $normalizedValues);
        }

        $verification = $verifier->verify($request, $definition);

        if ($verification->status === VerificationStatus::SKIPPED) {
            throw new RuntimeException('Replay re-verification was skipped; failing closed for audit safety.');
        }

        if ($verification->status !== VerificationStatus::VALID) {
            throw new RuntimeException($verification->reason ?? 'Replay re-verification failed.');
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerValue(array $headers, string $headerName): ?string
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== strtolower($headerName)) {
                continue;
            }

            if (is_array($values)) {
                $value = $values[0] ?? null;

                return is_scalar($value) ? (string) $value : null;
            }

            return is_scalar($values) ? (string) $values : null;
        }

        return null;
    }
}
