<?php

declare(strict_types=1);

namespace Hookbox;

use Closure;

final class WebhookActionBuilder
{
    private string $eventType = '*';

    public function __construct(
        /** @var Closure(WebhookActionRegistration): void */
        private readonly Closure $register,
        private readonly string $provider,
    ) {}

    public function when(string $eventType = '*'): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    /**
     * @param  class-string|Closure(WebhookActionContext): bool|null  $condition
     */
    public function through(string $action, string|Closure|null $condition = null): self
    {
        ($this->register)(new WebhookActionRegistration(
            provider: $this->provider,
            eventType: $this->eventType,
            action: $action,
            condition: $condition,
        ));

        return $this;
    }
}
