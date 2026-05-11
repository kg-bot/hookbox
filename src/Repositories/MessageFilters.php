<?php

declare(strict_types=1);

namespace Hookbox\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class MessageFilters
{
    public ?CarbonImmutable $receivedFrom;

    public ?CarbonImmutable $receivedTo;

    public function __construct(
        public ?string $sourceSlug = null,
        public ?string $signatureStatus = null,
        public ?string $eventType = null,
        ?CarbonInterface $receivedFrom = null,
        ?CarbonInterface $receivedTo = null,
        public ?string $messageReference = null,
    ) {
        $this->receivedFrom = $receivedFrom?->toImmutable();
        $this->receivedTo = $receivedTo?->toImmutable();

        if ($this->receivedFrom !== null && $this->receivedTo !== null && $this->receivedFrom->greaterThan($this->receivedTo)) {
            throw new \InvalidArgumentException('Received time window cannot be inverted.');
        }
    }
}
