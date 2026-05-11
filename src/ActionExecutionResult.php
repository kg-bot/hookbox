<?php

declare(strict_types=1);

namespace Hookbox;

use Hookbox\Models\WebhookAttempt;

final readonly class ActionExecutionResult
{
    public function __construct(
        public WebhookAttempt $attempt,
        public bool $matched,
    ) {}
}
