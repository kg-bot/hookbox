<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Verifiers;

use Hookbox\Contracts\VerifierTransport;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\Verifiers\AwsSnsVerifier;
use Hookbox\Verifiers\Support\VerifierTransportException;
use Hookbox\Verifiers\Support\VerifierTransportResponse;
use Illuminate\Http\Request;

final class AwsSnsVerifierTest extends TestCase
{
    private const PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCoBf4rg3sUENrN
ioqGXGIKkzMXw60Fb3P6l/1upVefrzhWdAW9NdNZKflLnY3YT4VEn+HewLbcbqVO
V7BxoZdxy946z04TLpNB/ZVytlFSgVHeAeKYN3f+abIobWpQ24hGKiMiqTLrSJbB
B9Ur4amKoExU3dVHbNnUDe4U/cZgCUWqPItI0fSr0B+8wKXMW3UKfiYmAIexUaiL
hqLKiQ2i8QGNHg0MW0KWzX8+LxjAOs2wVvEKabPbQB3Bw7F2xFwTOC/Vtz6MpCtI
5Sn32EWdT36XoGbvSlsZLJfxehXd4mq5giD7Lni9PLqktHlqmO7ujbUEltlK7DVD
yabYpE2LAgMBAAECggEAANPGuerJIwLSO6DVqG5cAov8Ub73jcdMCDfSBPFwylXP
2TJztMgcZPFSoOStsMWeH7BfKabulOHr6RmAF48hcmtRNMjrLCer4e9LBRLmDkSa
D+sXyoK7Zy1DYJ+T88H2RyIopMsLIngWW0JGnRNcr6oKYNXsGZCXofZPmHAyFyxH
zLjlyaTNfrj049N3ey3j0imhFdQzxwPMVgrpOxWATsHLteQDULPhbSqF9uRmCULV
apBEVJ9bSrRUlH0wEm5WGPHawnytFPU7f4WLjmbPToSd75NycVMACkCHD8COApQH
IeIAsfmIHmhSFrf5tY42l9Rp6PiEXMKIX5WL7z3oQQKBgQDerUyDaXIFRs7GYh7k
xs1KMpL5ZTIwMd0nf1Qm8BGdLSv2g9WTJzRoXJP8kuSG14DM1h6nq8S+7ZnT7MAE
oQNhSTuMSpVThstynQmsymv8eruLGurQRMQFd99oagsXJuxFlo59JucQuaT63Emw
i/bwmnOTZv0zJfVZpPc9MN2J3wKBgQDBKu4KFnGLRn+Kc7y/+vJmCppRJmVl3v+Y
+cDP9Nxq90ZCSxbbtSDujdW0Uqw2rMv6dGN6YhDs8i5C1bo9AVSo2NW7mmPYzQCO
0Ry/3UPKMoGbiE5u6eiwq23+pVudBb3HlmowipQGvW0KmDvmVSx0PqzZpCMrengT
EJq6fABJ1QKBgCNy0ShmY+llIUvBmQtwfoPeUzlym6/CcGN2SK4+L3+nDkWbLSfU
6OnoOwLNW6X/rphtScoFdTez2XY8TUEvZLtbDijCQs1eOwsO5thkDRbPbwWxDkqD
d/Uq5RzZLNTNtHVLh3ly9PvboeDxqqV5UqFw5Q9FKO+4jjtsIJPUMKBfAoGAYTXO
h0sWJwX6Z12pTl/mns2VLWOKQcMAlCaUDtNmHXqFZBVP0o+LnCHKuy2jtvwsxsTN
zygM5oFWIJJYYB0MUtCUdw3SU6ePMVAxDKk4VUgni3MELbMPQ+FxwGXM/e+GuyuK
ExWaOu4XMu67rkWM0o88A2cjv9ypEscXZuPCbWUCgYEAiMZ8ibpeu8mQx5WFcX7G
n9Lc4MOgQEbAL9UwES4w9dsJ0B8DXj0ByiLxY60JcMNNXN8BZzEX7uwCUzKjfHOn
MH3/F/GsuSuhRS+foeuvDAtX3ThoYWSwjVU3iYdV9rVdHVEpWxWzZZoEvjV2D1VD
KA48FVXMhy6Lbl5mtqjOGNA=
-----END PRIVATE KEY-----
PEM;

    private const CERTIFICATE = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDLTCCAhWgAwIBAgIUbbjIkqXfea/cThWWubDSTpUUKJwwDQYJKoZIhvcNAQEL
BQAwJjEkMCIGA1UEAwwbc25zLnVzLWVhc3QtMS5hbWF6b25hd3MuY29tMB4XDTI2
MDUwOTIxNTM1MloXDTM2MDUwNjIxNTM1MlowJjEkMCIGA1UEAwwbc25zLnVzLWVh
c3QtMS5hbWF6b25hd3MuY29tMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKC
AQEAqAX+K4N7FBDazYqKhlxiCpMzF8OtBW9z+pf9bqVXn684VnQFvTXTWSn5S52N
2E+FRJ/h3sC23G6lTlewcaGXccveOs9OEy6TQf2VcrZRUoFR3gHimDd3/mmyKG1q
UNuIRiojIqky60iWwQfVK+GpiqBMVN3VR2zZ1A3uFP3GYAlFqjyLSNH0q9AfvMCl
zFt1Cn4mJgCHsVGoi4aiyokNovEBjR4NDFtCls1/Pi8YwDrNsFbxCmmz20AdwcOx
dsRcEzgv1bc+jKQrSOUp99hFnU9+l6Bm70pbGSyX8XoV3eJquYIg+y54vTy6pLR5
apju7o21BJbZSuw1Q8mm2KRNiwIDAQABo1MwUTAdBgNVHQ4EFgQUmt3f7bzUvawe
Asgio0HOa+nstL8wHwYDVR0jBBgwFoAUmt3f7bzUvaweAsgio0HOa+nstL8wDwYD
VR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAQEAljkkrrpzKSZhzrLQ7x7h
xxQls4bKWNS+A1qu2WzBnj1xq8Nj3SV2i1CzdEaHZCk6tOxpxcCmaKQkNjWYB9bY
qsvQdQAYad5PDsA7yHuAXHzad+zTiy0tGa2SHxE0zKP/jhAEBXzpaUJ9/tj3dpLV
0QDiGdzVfXagtH1nRNXFCkrAsdDnBwYEmJeM1P5PYwESFlY6pnwwPaLLAWyaFKyq
sQdBmfnaZlbd8Hh9X562nhfGFBxazMohzrZa99Qfk1DH7jIDQi4NTTKuZ/LF4vid
RzPnDZGB+TO5RYcbq1BdmaaYrB1HtyBceTj2d84nmyorLXCfbvX1je5kBQQsTmPJ
Ig==
-----END CERTIFICATE-----
PEM;

    public function test_it_verifies_a_valid_notification_and_extracts_metadata(): void
    {
        $fixture = $this->signedFixture('notification-valid.json');
        $request = $this->request($fixture['raw_body']);

        $transport = new FakeAwsSnsVerifierTransport([
            new VerifierTransportResponse(200, $fixture['transport']['certificate_body']),
        ]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_verifies_a_valid_subscription_confirmation(): void
    {
        $fixture = $this->signedFixture('subscription-confirmation.json');
        $request = $this->request($fixture['raw_body']);

        $transport = new FakeAwsSnsVerifierTransport([
            new VerifierTransportResponse(200, $fixture['transport']['certificate_body']),
        ]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_rejects_malformed_json(): void
    {
        $request = $this->request('{not-json');

        $transport = new FakeAwsSnsVerifierTransport([]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition([
            'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:orders',
        ]);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Malformed SNS JSON payload.', $result->reason);
        $this->assertNull($verifier->eventType($request, $source));
        $this->assertNull($verifier->idempotencyKey($request, $source));
        $this->assertCount(0, $transport->sentRequests);
    }

    public function test_it_rejects_a_topic_arn_mismatch_before_any_network_call(): void
    {
        $fixture = $this->fixture('notification-invalid-topic.json');
        $request = $this->request($fixture['raw_body']);

        $transport = new FakeAwsSnsVerifierTransport([]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_uses_the_shared_failure_policy_for_certificate_fetch_transport_failures(): void
    {
        $fixture = $this->fixture('cert-fetch-failure.json');
        $request = $this->request($fixture['raw_body']);
        $reason = $fixture['expected']['reason'];

        $this->assertIsString($reason);

        $transport = new FakeAwsSnsVerifierTransport([
            new VerifierTransportException($reason),
        ]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_uses_the_shared_failure_policy_for_non_success_certificate_fetch_responses(): void
    {
        $fixture = $this->fixture('cert-fetch-provider-failure.json');
        $request = $this->request($fixture['raw_body']);

        $transport = new FakeAwsSnsVerifierTransport([
            new VerifierTransportResponse(503, 'provider unavailable'),
        ]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::from($fixture['expected']['status']), $result->status);
        $this->assertSame($fixture['expected']['reason'], $result->reason);
        $this->assertSame($fixture['expected']['event_type'], $verifier->eventType($request, $source));
        $this->assertSame($fixture['expected']['idempotency_key'], $verifier->idempotencyKey($request, $source));
        $this->assertSame($fixture['expected']['sent_requests'], $transport->sentRequests);
    }

    public function test_it_rejects_an_invalid_certificate_url_without_fetching(): void
    {
        $fixture = $this->fixture('notification-valid.json');
        $payload = json_decode($fixture['raw_body'], true, 512, JSON_THROW_ON_ERROR);
        $payload['SigningCertURL'] = 'http://sns.us-east-1.amazonaws.com/SimpleNotificationService-test-cert.pem?bad=1';
        $request = $this->request(json_encode($payload, JSON_THROW_ON_ERROR));

        $transport = new FakeAwsSnsVerifierTransport([]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Invalid SNS SigningCertURL.', $result->reason);
        $this->assertCount(0, $transport->sentRequests);
    }

    public function test_it_rejects_a_certificate_url_with_an_explicit_port_without_fetching(): void
    {
        $fixture = $this->fixture('notification-valid.json');
        $payload = json_decode($fixture['raw_body'], true, 512, JSON_THROW_ON_ERROR);
        $payload['SigningCertURL'] = 'https://sns.us-east-1.amazonaws.com:444/SimpleNotificationService-test-cert.pem';
        $request = $this->request(json_encode($payload, JSON_THROW_ON_ERROR));

        $transport = new FakeAwsSnsVerifierTransport([]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Invalid SNS SigningCertURL.', $result->reason);
        $this->assertCount(0, $transport->sentRequests);
    }

    public function test_it_rejects_an_unsupported_signature_version(): void
    {
        $fixture = $this->fixture('notification-valid.json');
        $payload = json_decode($fixture['raw_body'], true, 512, JSON_THROW_ON_ERROR);
        $payload['SignatureVersion'] = '9';
        $request = $this->request(json_encode($payload, JSON_THROW_ON_ERROR));

        $transport = new FakeAwsSnsVerifierTransport([]);
        $verifier = new AwsSnsVerifier($transport);
        $source = $this->sourceDefinition($fixture['source_config']);

        $result = $verifier->verify($request, $source);

        $this->assertSame(VerificationStatus::INVALID, $result->status);
        $this->assertSame('Unsupported SNS SignatureVersion [9].', $result->reason);
        $this->assertCount(0, $transport->sentRequests);
    }

    public function test_it_can_be_resolved_through_the_container_with_the_shared_transport(): void
    {
        $verifier = $this->appInstance()->make(AwsSnsVerifier::class);

        $this->assertInstanceOf(AwsSnsVerifier::class, $verifier);
    }

    /**
     * @return array{
     *     raw_body: string,
     *     source_config: array<string, mixed>,
     *     transport?: array{certificate_body: string},
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
        $path = dirname(__DIR__, 2).'/Fixtures/Verifiers/AwsSns/'.$name;

        /** @var array{
         *     raw_body: string,
         *     source_config: array<string, mixed>,
         *     transport?: array{certificate_body: string},
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
     * @return array{
     *     raw_body: string,
     *     source_config: array<string, mixed>,
     *     transport: array{certificate_body: string},
     *     expected: array{
     *         status: string,
     *         reason: ?string,
     *         event_type: ?string,
     *         idempotency_key: ?string,
     *         sent_requests: list<array<string, mixed>>
     *     }
     * }
     */
    private function signedFixture(string $name): array
    {
        $fixture = $this->fixture($name);
        $payload = json_decode($fixture['raw_body'], true, 512, JSON_THROW_ON_ERROR);
        $canonical = $this->canonicalString($payload);
        $algorithm = ($payload['SignatureVersion'] ?? null) === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;

        $signature = '';
        openssl_sign($canonical, $signature, self::PRIVATE_KEY, $algorithm);
        $payload['Signature'] = base64_encode($signature);
        $fixture['raw_body'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $fixture['transport']['certificate_body'] = self::CERTIFICATE;

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalString(array $payload): string
    {
        $pairs = [];

        if (($payload['Type'] ?? null) === 'Notification') {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        } else {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        }

        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;

            if ($field === 'Subject' && ! is_string($value)) {
                continue;
            }

            if (! is_string($value)) {
                throw new \RuntimeException(sprintf('Missing canonical field [%s] in test fixture.', $field));
            }

            $pairs[] = $field;
            $pairs[] = $value;
        }

        return implode("\n", $pairs);
    }

    private function request(string $payload): Request
    {
        return Request::create('/webhooks/aws-sns', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceDefinition(array $config): SourceDefinition
    {
        return new SourceDefinition(
            slug: 'aws-sns',
            name: 'AWS SNS',
            verifier: AwsSnsVerifier::class,
            config: $config,
        );
    }
}

final class FakeAwsSnsVerifierTransport implements VerifierTransport
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

        throw new VerifierTransportException('Unexpected AWS SNS verifier transport request.');
    }
}
