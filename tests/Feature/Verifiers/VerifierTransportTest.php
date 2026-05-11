<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Contracts\VerifierTransport;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\Support\VerifierFailurePolicy;
use Hookbox\Verifiers\Support\VerifierHttpClient;
use Hookbox\Verifiers\Support\VerifierTransportException;
use Hookbox\Verifiers\Support\VerifierTransportResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class VerifierTransportTest extends TestCase
{
    public function test_service_provider_registers_the_http_transport_for_verifiers(): void
    {
        $transport = $this->appInstance()->make(VerifierTransport::class);

        $this->assertInstanceOf(VerifierHttpClient::class, $transport);
    }

    public function test_transport_can_send_a_json_request_through_the_laravel_http_client(): void
    {
        Http::fake([
            'https://example.test/verify' => Http::response(['status' => 'ok'], 200),
        ]);

        $transport = $this->appInstance()->make(VerifierTransport::class);
        $response = $transport->send('POST', 'https://example.test/verify', [
            'headers' => [
                'X-Webhook-Provider' => 'paypal',
            ],
            'json' => [
                'event_id' => 'evt_123',
            ],
            'timeout' => 5,
        ]);

        $this->assertInstanceOf(VerifierTransportResponse::class, $response);
        $this->assertSame(200, $response->status);
        $this->assertSame('{"status":"ok"}', $response->body);
        $this->assertSame(['status' => 'ok'], $response->json());

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://example.test/verify'
                && $request->hasHeader('X-Webhook-Provider', 'paypal')
                && $request['event_id'] === 'evt_123';
        });
    }

    public function test_transport_wraps_connection_failures_in_a_domain_exception(): void
    {
        Http::fake(static fn (): never => throw new ConnectionException('Timed out.'));

        $transport = $this->appInstance()->make(VerifierTransport::class);

        $this->expectException(VerifierTransportException::class);
        $this->expectExceptionMessage('Timed out.');

        $transport->send('POST', 'https://example.test/verify');
    }

    public function test_failure_policy_defaults_transport_failures_to_invalid(): void
    {
        $result = VerifierFailurePolicy::forTransportFailure(
            $this->sourceDefinition(),
            new VerifierTransportException('Timed out.'),
        );

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Timed out.', $result->reason);
    }

    public function test_failure_policy_keeps_transport_failures_invalid_even_when_source_config_requests_skipped(): void
    {
        $result = VerifierFailurePolicy::forTransportFailure(
            $this->sourceDefinition([
                'verification_failure_policy' => 'skipped',
            ]),
            new VerifierTransportException('Provider unavailable.'),
        );

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Provider unavailable.', $result->reason);
    }

    public function test_failure_policy_defaults_invalid_configuration_to_invalid(): void
    {
        $result = VerifierFailurePolicy::forTransportFailure(
            $this->sourceDefinition([
                'verification_failure_policy' => 'valid',
            ]),
            new VerifierTransportException('Provider unavailable.'),
        );

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Provider unavailable.', $result->reason);
    }

    public function test_failure_policy_defaults_provider_failures_to_invalid(): void
    {
        $result = VerifierFailurePolicy::forProviderFailure(
            $this->sourceDefinition(),
            'Provider returned HTTP 503.',
        );

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Provider returned HTTP 503.', $result->reason);
    }

    public function test_failure_policy_keeps_provider_failures_invalid_even_when_source_config_requests_skipped(): void
    {
        $result = VerifierFailurePolicy::forProviderFailure(
            $this->sourceDefinition([
                'verification_failure_policy' => 'skipped',
            ]),
            'Provider returned HTTP 503.',
        );

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Provider returned HTTP 503.', $result->reason);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config = []): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'network-verifier',
            name: 'Network Verifier',
            verifier: 'test-verifier',
            config: $config,
        );
    }
}
