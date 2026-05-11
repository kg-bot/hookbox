<?php

declare(strict_types=1);

namespace Hookbox\Events;

use Hookbox\Views\WebhookAttemptView;
use Hookbox\Views\WebhookMessageView;

final readonly class WebhookProcessed
{
    public function __construct(
        public WebhookMessageView $message,
        public WebhookAttemptView $attempt,
    ) {}
}
