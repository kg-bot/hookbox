<?php

declare(strict_types=1);

namespace Hookbox\Events;

use Hookbox\Views\WebhookMessageView;

final readonly class WebhookReceived
{
    public function __construct(public WebhookMessageView $message) {}
}
