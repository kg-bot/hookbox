<?php

declare(strict_types=1);

namespace Hookbox\Views;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class WebhookMessageView
{
    public CarbonImmutable $receivedAt;

    public ?CarbonImmutable $redactedAt;

    public function __construct(
        public string $id,
        public ?string $sourceSlug,
        public ?string $sourceName,
        public ?string $idempotencyKey,
        public ?string $eventType,
        public string $signatureStatus,
        CarbonInterface $receivedAt,
        public ?string $clientIp,
        ?CarbonInterface $redactedAt,
    ) {
        $this->receivedAt = $receivedAt->toImmutable();
        $this->redactedAt = $redactedAt?->toImmutable();
    }
}
