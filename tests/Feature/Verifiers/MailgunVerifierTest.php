<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\MailgunVerifier;
use Illuminate\Http\Request;

final class MailgunVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_mailgun_signature_and_extracts_metadata(): void
    {
        $fixture = $this->fixture('valid.json');
        $request = $this->request($fixture['raw_body']);

        $verifier = new MailgunVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_tampered_signature(): void
    {
        $fixture = $this->fixture('tampered.json');
        $request = $this->request($fixture['raw_body']);

        $verifier = new MailgunVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_a_missing_or_malformed_signature_payload(): void
    {
        $fixture = $this->fixture('malformed.json');
        $request = $this->request($fixture['raw_body']);

        $verifier = new MailgunVerifier;
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
        $request = $this->request($fixture['raw_body']);

        $verifier = new MailgunVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
    }

    /**
     * @return array{
     *     raw_body: string,
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
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/Mailgun/'.$name;

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function request(string $payload): Request
    {
        return Request::create('/webhooks/mailgun', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'mailgun',
            name: 'Mailgun',
            verifier: MailgunVerifier::class,
            config: $config,
        );
    }
}
