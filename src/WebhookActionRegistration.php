<?php

declare(strict_types=1);

namespace Hookbox;

use Closure;
use Hookbox\Contracts\WebhookActionCondition;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final readonly class WebhookActionRegistration
{
    /**
     * @param  class-string|Closure(WebhookActionContext): bool|null  $condition
     */
    public function __construct(
        public string $provider,
        public string $eventType,
        public string $action,
        public string|Closure|null $condition = null,
    ) {}

    public function matches(WebhookActionContext $context, Container $container): bool
    {
        if ($this->condition === null) {
            return true;
        }

        if (is_string($this->condition)) {
            $condition = $container->make($this->condition);

            if (! $condition instanceof WebhookActionCondition) {
                throw new InvalidArgumentException(sprintf(
                    'Webhook action condition [%s] must implement [%s].',
                    $this->condition,
                    WebhookActionCondition::class,
                ));
            }

            return $condition->matches($context);
        }

        return (bool) ($this->condition)($context);
    }
}
