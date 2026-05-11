<?php

declare(strict_types=1);

namespace Hookbox\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class MetricsRange
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(
        CarbonInterface $from,
        CarbonInterface $to,
    ) {
        $this->from = $from->toImmutable();
        $this->to = $to->toImmutable();

        if ($this->from->greaterThan($this->to)) {
            throw new \InvalidArgumentException('Metrics range cannot be inverted.');
        }
    }
}
