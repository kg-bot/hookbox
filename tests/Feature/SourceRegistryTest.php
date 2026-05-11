<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature;

use Hookbox\SourceDefinition;
use Hookbox\SourceRegistry;
use Hookbox\Tests\TestCase;

final class SourceRegistryTest extends TestCase
{
    public function test_it_hydrates_sources_from_configuration(): void
    {
        config()->set('hookbox.sources', [
            'stripe' => [
                'name' => 'Stripe',
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
                'secret' => 'whsec_test',
                'tolerance' => 300,
            ],
            'github' => [
                'verifier' => 'Hookbox\\Verifiers\\GitHubVerifier',
                'retention_days' => 30,
            ],
        ]);

        $registry = $this->appInstance()->make(SourceRegistry::class);

        $this->assertEquals([
            new SourceDefinition(
                slug: 'stripe',
                name: 'Stripe',
                verifier: 'Hookbox\\Verifiers\\StripeVerifier',
                config: [
                    'secret' => 'whsec_test',
                    'tolerance' => 300,
                ],
            ),
            new SourceDefinition(
                slug: 'github',
                name: 'github',
                verifier: 'Hookbox\\Verifiers\\GitHubVerifier',
                config: ['retention_days' => 30],
            ),
        ], $registry->all());

        $this->assertEquals(
            new SourceDefinition(
                slug: 'stripe',
                name: 'Stripe',
                verifier: 'Hookbox\\Verifiers\\StripeVerifier',
                config: [
                    'secret' => 'whsec_test',
                    'tolerance' => 300,
                ],
            ),
            $registry->find('stripe'),
        );
    }

    public function test_it_allows_runtime_registration_and_override_for_current_instance(): void
    {
        config()->set('hookbox.sources', [
            'stripe' => [
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
            ],
        ]);

        $registry = $this->appInstance()->make(SourceRegistry::class);

        $registry->register(new SourceDefinition(
            slug: 'stripe',
            name: 'Stripe API',
            verifier: 'App\\Verifiers\\CustomStripeVerifier',
            config: ['mode' => 'live'],
        ));

        $registry->register(new SourceDefinition(
            slug: 'github',
            name: 'GitHub',
            verifier: 'App\\Verifiers\\GitHubVerifier',
            config: ['secret' => 'top-secret'],
        ));

        $this->assertEquals(
            new SourceDefinition(
                slug: 'stripe',
                name: 'Stripe API',
                verifier: 'App\\Verifiers\\CustomStripeVerifier',
                config: ['mode' => 'live'],
            ),
            $registry->find('stripe'),
        );

        $this->assertEquals(
            new SourceDefinition(
                slug: 'github',
                name: 'GitHub',
                verifier: 'App\\Verifiers\\GitHubVerifier',
                config: ['secret' => 'top-secret'],
            ),
            $registry->find('github'),
        );
    }

    public function test_it_returns_null_for_unknown_sources(): void
    {
        config()->set('hookbox.sources', []);

        $registry = $this->appInstance()->make(SourceRegistry::class);

        $this->assertNull($registry->find('missing'));
    }

    public function test_runtime_registrations_do_not_leak_to_a_fresh_container_instance(): void
    {
        config()->set('hookbox.sources', [
            'stripe' => [
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
                'secret' => 'whsec_test',
            ],
        ]);

        $registry = $this->appInstance()->make(SourceRegistry::class);

        $registry->register(new SourceDefinition(
            slug: 'github',
            name: 'GitHub',
            verifier: 'App\\Verifiers\\GitHubVerifier',
            config: ['secret' => 'top-secret'],
        ));

        $freshApp = $this->createApplication();

        $freshApp['config']->set('hookbox.sources', [
            'stripe' => [
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
                'secret' => 'whsec_test',
            ],
        ]);

        $freshRegistry = $freshApp->make(SourceRegistry::class);

        $this->assertNull($freshRegistry->find('github'));
        $this->assertEquals(
            new SourceDefinition(
                slug: 'stripe',
                name: 'stripe',
                verifier: 'Hookbox\\Verifiers\\StripeVerifier',
                config: ['secret' => 'whsec_test'],
            ),
            $freshRegistry->find('stripe'),
        );
    }

    public function test_runtime_registrations_do_not_survive_a_scoped_instance_flush(): void
    {
        config()->set('hookbox.sources', [
            'stripe' => [
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
                'secret' => 'whsec_test',
            ],
        ]);

        $registry = $this->appInstance()->make(SourceRegistry::class);

        $registry->register(new SourceDefinition(
            slug: 'github',
            name: 'GitHub',
            verifier: 'App\\Verifiers\\GitHubVerifier',
            config: ['secret' => 'top-secret'],
        ));

        $this->appInstance()->forgetScopedInstances();

        $refreshedRegistry = $this->appInstance()->make(SourceRegistry::class);

        $this->assertNotSame($registry, $refreshedRegistry);
        $this->assertNull($refreshedRegistry->find('github'));
        $this->assertEquals(
            new SourceDefinition(
                slug: 'stripe',
                name: 'stripe',
                verifier: 'Hookbox\\Verifiers\\StripeVerifier',
                config: ['secret' => 'whsec_test'],
            ),
            $refreshedRegistry->find('stripe'),
        );
    }
}
