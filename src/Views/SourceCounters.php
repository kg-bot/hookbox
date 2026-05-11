<?php

declare(strict_types=1);

namespace Hookbox\Views;

final readonly class SourceCounters
{
    public function __construct(
        public int $messages,
        public int $attempts,
    ) {}
}
