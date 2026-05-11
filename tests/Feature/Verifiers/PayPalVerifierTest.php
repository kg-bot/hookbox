<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Contracts\VerifierTransport;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\PayPalVerifier;
use Hookbox\Verifiers\Support\VerifierTransportException;
use Hookbox\Verifiers\Support\VerifierTransportResponse;
use Illuminate\Http\Request;

final class PayPalVerifierTest extends TestCase
{
    public function test_it_verifies_a_valid_paypal_webhook_and_extracts_metadata(): void
    {
        $fixture = $this->fixture('valid.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $transport = new FakeVerifierTransport([
            new VerifierTransportResponse(200, '{"access_token":"access-token"}'),
            new VerifierTransportResponse(200, '{"verification_status":"SUCCESS"}'),
        ]);
        $verifier = new PayPalVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_marks_a_provider_declared_invalid_signature_as_invalid(): void
    {
        $fixture = $this->fixture('invalid-signature.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $transport = new FakeVerifierTransport([
            new VerifierTransportResponse(200, '{"access_token":"access-token"}'),
            new VerifierTransportResponse(200, '{"verification_status":"FAILURE"}'),
        ]);
        $verifier = new PayPalVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_fails_closed_when_the_provider_request_fails(): void
    {
        $fixture = $this->fixture('provider-failure.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);
        $reason = $fixture['expected']['reason'];

        $this->assertIsString($reason);

        $transport = new FakeVerifierTransport([
            new VerifierTransportException($reason),
        ]);
        $verifier = new PayPalVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
    }

    public function test_it_rejects_malformed_headers_or_missing_configuration(): void
    {
        $fixture = $this->fixture('malformed.json');
        $request = $this->request($fixture['raw_body'], $fixture['headers']);

        $transport = new FakeVerifierTransport([]);
        $verifier = new PayPalVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertCount(0, $transport->sentRequests);
    }

    public function test_it_can_be_resolved_through_the_container_with_the_shared_transport(): void
    {
        $verifier = $this->appInstance()->make(PayPalVerifier::class);

        $this->assertInstanceOf(PayPalVerifier::class, $verifier);
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
     *         idempotency_key: ?string,
     *         sent_requests: list<array<string, mixed>>
     *     }
     * }
     */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/PayPal/'.$name;

        /** @var array{
         *     raw_body: string,
         *     headers: array<string, string>,
         *     source_config: array<string, mixed>,
         *     expected: array{
         *         status: string,
         *         reason: ?string,
         *         event_type: ?string,
         *         idempotency_key: ?string,
         *         sent_requests?: list<array<string, mixed>>
         *     }
         * } $fixture
         */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($fixture['expected']['sent_requests'])) {
            $fixture['expected']['sent_requests'] = [];
        }

        return $fixture;
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

        return Request::create('/webhooks/paypal', 'POST', server: [
            ...$server,
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'paypal',
            name: 'PayPal',
            verifier: PayPalVerifier::class,
            config: $config,
        );
    }
}

final class FakeVerifierTransport implements VerifierTransport
{
    /**
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public array $sentRequests = [];

    /**
     * @param  list<VerifierTransportResponse|VerifierTransportException>  $responses
     */
    public function __construct(
        private readonly array $responses,
    ) {}

    public function send(string $method, string $url, array $options = []): VerifierTransportResponse
    {
        $this->sentRequests[] = [
            'method' => $method,
            'url' => $url,
            'options' => $options,
        ];

        $response = $this->responses[count($this->sentRequests) - 1] ?? null;

        if ($response instanceof VerifierTransportException) {
            throw $response;
        }

        if ($response instanceof VerifierTransportResponse) {
            return $response;
        }

        throw new VerifierTransportException('Unexpected PayPal verifier transport request.');
    }
}
