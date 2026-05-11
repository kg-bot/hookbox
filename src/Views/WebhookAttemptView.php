<?php

declare(strict_types=1);

namespace Hookbox\Views;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class WebhookAttemptView
{
    public ?CarbonImmutable $startedAt;

    public ?CarbonImmutable $finishedAt;

    public function __construct(
        public string $id,
        public string $messageId,
        public string $kind,
        public string $handler,
        public string $status,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $finishedAt,
        public ?int $durationMs,
        public ?string $errorClass,
        public ?string $errorMessage,
        public ?string $triggeredBy,
    ) {
        $this->startedAt = $startedAt?->toImmutable();
        $this->finishedAt = $finishedAt?->toImmutable();
    }
}
