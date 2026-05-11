<?php

declare(strict_types=1);

namespace Hookbox\Views;

final readonly class AttemptStatusCounters
{
    public function __construct(
        public int $pending,
        public int $succeeded,
        public int $failed,
        public int $skipped,
    ) {}
}
