<?php

declare(strict_types=1);

namespace Hookbox;

final readonly class ReplayOptions
{
    /**
     * @param  list<class-string>|null  $actionsFilter
     */
    public function __construct(
        public bool $dryRun = true,
        public ?string $triggeredBy = null,
        public ?array $actionsFilter = null,
        public bool $forceReverify = false,
    ) {}
}
