<?php

declare(strict_types=1);

namespace Hookbox\Contracts;

use Hookbox\WebhookActionContext;

interface WebhookActionCondition
{
    public function matches(WebhookActionContext $context): bool;
}
