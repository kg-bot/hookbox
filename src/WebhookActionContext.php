<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Enums\AttemptKind;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookSource;

final readonly class WebhookActionContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private WebhookMessage $message,
        private ?WebhookAttempt $attempt,
        private WebhookSource|SourceDefinition $source,
        private array $payload,
        private AttemptKind $kind,
        private ?string $triggeredBy = null,
    ) {}

    public function message(): WebhookMessage
    {
        return $this->message;
    }

    public function attempt(): ?WebhookAttempt
    {
        return $this->attempt;
    }

    public function provider(): string
    {
        return $this->source->slug;
    }

    public function event(): ?string
    {
        return $this->message->event_type;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function headers(): array
    {
        return $this->message->headers;
    }

    /**
     * @return string|array<int|string, mixed>|null
     */
    public function header(string $name): string|array|null
    {
        foreach ($this->message->headers as $header => $value) {
            if (strtolower((string) $header) === strtolower($name)) {
                if (is_string($value) || is_array($value)) {
                    return $value;
                }

                return null;
            }
        }

        return null;
    }

    public function isReplay(): bool
    {
        return $this->kind === AttemptKind::REPLAY || $this->kind === AttemptKind::DRY_RUN;
    }

    public function isDryRun(): bool
    {
        return $this->kind === AttemptKind::DRY_RUN;
    }

    public function triggeredBy(): ?string
    {
        return $this->triggeredBy;
    }
}
