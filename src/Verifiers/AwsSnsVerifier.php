<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Contracts\VerifierTransport;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Hookbox\Verifiers\Support\VerifierFailurePolicy;
use Hookbox\Verifiers\Support\VerifierTransportException;
use Illuminate\Http\Request;
use JsonException;
use OpenSSLAsymmetricKey;

final class AwsSnsVerifier implements Verifier
{
    public function __construct(
        private readonly VerifierTransport $transport,
    ) {}

    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $payload = $this->payload($request);

        if ($payload === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Malformed SNS JSON payload.');
        }

        $topicArn = $this->stringConfig($source, 'topic_arn');

        if ($topicArn === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing required SNS topic_arn configuration.');
        }

        if (($payload['TopicArn'] ?? null) !== $topicArn) {
            return new VerificationResult(VerificationStatus::INVALID, 'SNS TopicArn does not match the configured topic_arn.');
        }

        $signatureVersion = $payload['SignatureVersion'] ?? null;
        $algorithm = match ($signatureVersion) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };

        if ($algorithm === null) {
            return new VerificationResult(
                VerificationStatus::INVALID,
                sprintf('Unsupported SNS SignatureVersion [%s].', is_scalar($signatureVersion) ? (string) $signatureVersion : ''),
            );
        }

        $certificateUrl = $payload['SigningCertURL'] ?? null;

        if (! is_string($certificateUrl) || ! $this->isValidCertificateUrl($certificateUrl)) {
            return new VerificationResult(VerificationStatus::INVALID, 'Invalid SNS SigningCertURL.');
        }

        $signature = base64_decode((string) ($payload['Signature'] ?? ''), true);

        if ($signature === false || $signature === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'SNS Signature is missing or malformed.');
        }

        $canonicalString = $this->canonicalString($payload);

        if ($canonicalString === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'SNS payload is missing required signature fields.');
        }

        try {
            $response = $this->transport->send('GET', $certificateUrl);
        } catch (VerifierTransportException $exception) {
            return VerifierFailurePolicy::forTransportFailure($source, $exception);
        }

        if ($response->status < 200 || $response->status >= 300) {
            return VerifierFailurePolicy::forProviderFailure(
                $source,
                sprintf('SNS certificate request failed with HTTP %d.', $response->status),
            );
        }

        $publicKey = $this->publicKeyFromCertificate($response->body);

        if ($publicKey === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'SNS signing certificate is invalid.');
        }

        $verified = openssl_verify($canonicalString, $signature, $publicKey, $algorithm);

        if ($verified === 1) {
            return new VerificationResult(VerificationStatus::VALID);
        }

        if ($verified === 0) {
            return new VerificationResult(VerificationStatus::INVALID, 'SNS signature verification failed.');
        }

        return new VerificationResult(VerificationStatus::INVALID, 'SNS signature verification could not be completed.');
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        $payload = $this->payload($request);

        return is_array($payload) ? $this->stringField($payload, 'MessageId') : null;
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        $payload = $this->payload($request);

        return is_array($payload) ? $this->stringField($payload, 'Type') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function stringConfig(SourceDefinition $source, string $key): ?string
    {
        $value = $source->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isValidCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if (isset($parts['port'])) {
            return false;
        }

        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (! is_string($host) || ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(?:\.cn)?$/', strtolower($host))) {
            return false;
        }

        if (! is_string($path)) {
            return false;
        }

        return str_starts_with($path, '/SimpleNotificationService-')
            && str_ends_with($path, '.pem');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalString(array $payload): ?string
    {
        $type = $this->stringField($payload, 'Type');

        $fields = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        $lines = [];

        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;

            if ($field === 'Subject' && ! is_string($value)) {
                continue;
            }

            if (! is_string($value) || $value === '') {
                return null;
            }

            $lines[] = $field;
            $lines[] = $value;
        }

        return implode("\n", $lines);
    }

    private function publicKeyFromCertificate(string $certificateBody): ?OpenSSLAsymmetricKey
    {
        $certificate = openssl_x509_read($certificateBody);

        if ($certificate === false) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if (! $publicKey instanceof OpenSSLAsymmetricKey) {
            return null;
        }

        return $publicKey;
    }
}
