<?php

declare(strict_types=1);

namespace Hookbox\Contracts;

use Hookbox\WebhookActionContext;

interface WebhookAction
{
    /**
     * @param  \Closure(WebhookActionContext): mixed  $next
     */
    public function handle(WebhookActionContext $context, \Closure $next): mixed;
}
