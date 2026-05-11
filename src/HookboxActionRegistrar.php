<?php

declare(strict_types=1);

namespace Hookbox;

final class HookboxActionRegistrar
{
    /**
     * @var list<WebhookActionRegistration>
     */
    private array $registrations = [];

    public function handle(string $provider): WebhookActionBuilder
    {
        return new WebhookActionBuilder(
            register: function (WebhookActionRegistration $registration): void {
                $this->register($registration);
            },
            provider: $provider,
        );
    }

    public function register(WebhookActionRegistration $registration): void
    {
        $this->registrations[] = $registration;
    }

    /**
     * @return list<WebhookActionRegistration>
     */
    public function registrations(): array
    {
        return $this->registrations;
    }

    public function flush(): void
    {
        $this->registrations = [];
    }
}
