<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\SlackVerifier;
use Illuminate\Http\Request;

final class SlackVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_slack_signature_and_extracts_metadata(): void
    {
        $fixture = $this->signedFixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new SlackVerifier;
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

        $verifier = new SlackVerifier;
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

        $verifier = new SlackVerifier;
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

        $verifier = new SlackVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_verifies_a_valid_slack_signature_with_default_tolerance(): void
    {
        $fixture = $this->signedFixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new SlackVerifier;
        $source = $this->sourceDefinition([
            'secret' => $fixture['source_config']['secret'],
        ]);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::VALID, $result->status);
        $this->assertNull($result->reason);
    }

    public function test_it_extracts_metadata_from_a_signed_form_encoded_slack_request(): void
    {
        $fixture = $this->signedFixture('slash-command.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers'], $fixture['content_type']);

        $verifier = new SlackVerifier;
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

        $verifier = new SlackVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
    }

    public function test_it_rejects_an_expired_request_timestamp_using_the_default_tolerance(): void
    {
        $fixture = $this->fixture('expired-timestamp.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $verifier = new SlackVerifier;
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    /**
     * @return array{
     *     raw_body: string,
     *     headers: array<string, string>,
     *     content_type: string,
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
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/Slack/'.$name;

        /** @var array{
         *     raw_body: string,
         *     headers: array<string, string>,
         *     content_type?: string,
         *     source_config: array<string, mixed>,
         *     expected: array{
         *         status: string,
         *         reason: ?string,
         *         event_type: ?string,
         *         idempotency_key: ?string
         *     }
         * } $fixture
         */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($fixture['content_type'])) {
            $fixture['content_type'] = 'application/json';
        }

        return $fixture;
    }

    /**
     * @return array{
     *     raw_body: string,
     *     headers: array<string, string>,
     *     content_type: string,
     *     source_config: array<string, mixed>,
     *     expected: array{
     *         status: string,
     *         reason: ?string,
     *         event_type: ?string,
     *         idempotency_key: ?string
     *     }
     * }
     */
    private function signedFixture(string $name): array
    {
        $fixture = $this->fixture($name);
        $timestamp = (string) time();

        $fixture['headers']['X-Slack-Request-Timestamp'] = $timestamp;
        $fixture['headers']['X-Slack-Signature'] = 'v0='.hash_hmac(
            'sha256',
            'v0:'.$timestamp.':'.$fixture['raw_body'],
            (string) $fixture['source_config']['secret'],
        );

        return $fixture;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $payload, array $headers, string $contentType = 'application/json'): Request
    {
        $server = ['CONTENT_TYPE' => $contentType];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('/webhooks/slack', 'POST', server: [
            ...$server,
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'slack',
            name: 'Slack',
            verifier: SlackVerifier::class,
            config: $config,
        );
    }
}
