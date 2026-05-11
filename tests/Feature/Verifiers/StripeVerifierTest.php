<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\StripeVerifier;
use Illuminate\Http\Request;

final class StripeVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_stripe_signature_and_extracts_metadata(): void
    {
        $fixture = $this->fixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StripeVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $fixture = $this->fixture('tampered.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StripeVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_an_expired_signature_timestamp(): void
    {
        $fixture = $this->fixture('expired-timestamp.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StripeVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_accepts_numeric_string_tolerance_values(): void
    {
        $fixture = $this->fixture('valid.json');
        $fixture['source_config']['tolerance'] = '999999999';
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StripeVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::VALID, $result->status);
        $this->assertNull($result->reason);
    }

    /**
     * @return array{
     *     raw_body: string,
     *     headers: array<string, string>,
     *     source_config: array<string, mixed>,
     *     expected: array{
     *         status: string,
     *         reason: ?string,
     *         event_type: ?string,
     *         idempotency_key: ?string
     *     }
     * }
     */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/Stripe/'.$name;

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $payload, array $headers): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('/webhooks/stripe', 'POST', server: [
            ...$server,
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'stripe',
            name: 'Stripe',
            verifier: StripeVerifier::class,
            config: $config,
        );
    }
}
