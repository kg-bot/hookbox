<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\StandardWebhooksVerifier;
use Illuminate\Http\Request;

final class StandardWebhooksVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_standard_webhooks_signature_and_extracts_metadata(): void
    {
        $fixture = $this->fixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StandardWebhooksVerifier;
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

        $verifier = new StandardWebhooksVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_malformed_signature_headers(): void
    {
        $fixture = $this->fixture('malformed.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StandardWebhooksVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_timestamp_outside_tolerance(): void
    {
        $fixture = $this->fixture('valid.json');
        $fixture['source_config']['tolerance'] = 300;
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StandardWebhooksVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Timestamp outside tolerance.', $result->reason);
    }

    public function test_it_does_not_use_a_malformed_webhook_id_as_the_idempotency_key_fallback(): void
    {
        $fixture = $this->fixture('valid.json');
        unset($fixture['source_config']['idempotency_key_path']);
        $fixture['headers']['webhook-id'] = 'bad.id';
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StandardWebhooksVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $this->assertNull($verifier->idempotencyKey($request, $source));
    }

    public function test_it_uses_a_valid_webhook_id_as_the_idempotency_key_fallback_even_when_other_headers_are_invalid(): void
    {
        $fixture = $this->fixture('valid.json');
        unset($fixture['source_config']['idempotency_key_path']);
        $fixture['headers']['webhook-timestamp'] = 'not-a-timestamp';
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new StandardWebhooksVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $this->assertSame('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W', $verifier->idempotencyKey($request, $source));
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
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/StandardWebhooks/'.$name;

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

        return Request::create('/webhooks/standard', 'POST', server: [
            ...$server,
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'standard-webhooks',
            name: 'Standard Webhooks',
            verifier: StandardWebhooksVerifier::class,
            config: $config,
        );
    }
}
