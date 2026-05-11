<?php

declare(strict_types=1);

namespace Hookbox\Views;

final readonly class MetricsSummary
{
    public function __construct(
        public SignatureStatusCounters $messages,
        public AttemptStatusCounters $attempts,
    ) {}
}
