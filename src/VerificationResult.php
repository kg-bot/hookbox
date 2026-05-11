<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Enums\VerificationStatus;

final readonly class VerificationResult
{
    public function __construct(
        public VerificationStatus $status,
        public ?string $reason = null,
    ) {}
}
