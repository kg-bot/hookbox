<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\ShopifyVerifier;
use Illuminate\Http\Request;

final class ShopifyVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_shopify_signature_and_extracts_metadata(): void
    {
        $fixture = $this->fixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new ShopifyVerifier;
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

        $verifier = new ShopifyVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_missing_or_malformed_signature_header(): void
    {
        $fixture = $this->fixture('malformed.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new ShopifyVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_missing_signature_header(): void
    {
        $fixture = $this->fixture('missing-header.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new ShopifyVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_truncated_signature_as_malformed_input(): void
    {
        $fixture = $this->fixture('truncated.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new ShopifyVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
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
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/Shopify/'.$name;

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

        return Request::create('/webhooks/shopify', 'POST', server: [
            ...$server,
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'shopify',
            name: 'Shopify',
            verifier: ShopifyVerifier::class,
            config: $config,
        );
    }
}
