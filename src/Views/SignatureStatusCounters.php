<?php

declare(strict_types=1);

namespace Hookbox\Views;

final readonly class SignatureStatusCounters
{
    public function __construct(
        public int $valid,
        public int $invalid,
        public int $skipped,
    ) {}
}
