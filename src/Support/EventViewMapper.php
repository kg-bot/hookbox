<?php

declare(strict_types=1);

namespace Hookbox\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookSource;
use Hookbox\Views\WebhookAttemptView;
use Hookbox\Views\WebhookMessageView;

final class EventViewMapper
{
    public function message(WebhookMessage $message, ?WebhookSource $source): WebhookMessageView
    {
        return new WebhookMessageView(
            id: (string) $message->getKey(),
            sourceSlug: $source?->slug,
            sourceName: $source?->name,
            idempotencyKey: $message->idempotency_key,
            eventType: $message->event_type,
            signatureStatus: $message->signature_status,
            receivedAt: $this->toImmutableOrFail($message->received_at),
            clientIp: $message->client_ip,
            redactedAt: $this->toImmutable($message->redacted_at),
        );
    }

    public function attempt(WebhookAttempt $attempt): WebhookAttemptView
    {
        return new WebhookAttemptView(
            id: (string) $attempt->getKey(),
            messageId: $attempt->message_id,
            kind: $attempt->kind,
            handler: $attempt->handler,
            status: $attempt->status,
            startedAt: $this->toImmutable($attempt->started_at),
            finishedAt: $this->toImmutable($attempt->finished_at),
            durationMs: $attempt->duration_ms,
            errorClass: $attempt->error_class,
            errorMessage: $attempt->error_message,
            triggeredBy: $attempt->triggered_by,
        );
    }

    private function toImmutable(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        return CarbonImmutable::parse($value);
    }

    private function toImmutableOrFail(CarbonInterface|string|null $value): CarbonImmutable
    {
        return $this->toImmutable($value)
            ?? throw new \UnexpectedValueException('Expected a non-null timestamp value.');
    }
}
