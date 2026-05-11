<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Hookbox\Verifiers\Concerns\DecodesJsonPayload;
use Illuminate\Http\Request;

final class SlackVerifier implements Verifier
{
    use DecodesJsonPayload;

    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $source->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $headers = $this->signatureHeaders($request);

        if ($headers === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed Slack signature headers.');
        }

        if (abs(time() - $headers['timestamp']) > $this->tolerance($source)) {
            return new VerificationResult(VerificationStatus::INVALID, 'Timestamp outside tolerance.');
        }

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$headers['timestamp'].':'.$request->getContent(), $secret);

        if (! hash_equals($expected, $headers['signature'])) {
            return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
        }

        return new VerificationResult(VerificationStatus::VALID);
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'event_id')
            ?? $this->stringFromFormPayload($request, 'trigger_id');
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'event.type')
            ?? $this->stringFromPayloadPath($request, 'type')
            ?? $this->stringFromFormPayload($request, 'command')
            ?? $this->stringFromFormPayload($request, 'type');
    }

    /**
     * @return array{timestamp:int, signature:string}|null
     */
    private function signatureHeaders(Request $request): ?array
    {
        $timestamp = $request->headers->get('X-Slack-Request-Timestamp');
        $signature = $request->headers->get('X-Slack-Signature');

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || $timestamp === '0') {
            return null;
        }

        if (! is_string($signature) || ! str_starts_with($signature, 'v0=')) {
            return null;
        }

        $digest = substr($signature, 3);

        if (strlen($digest) !== 64 || ! ctype_xdigit($digest)) {
            return null;
        }

        return [
            'timestamp' => (int) $timestamp,
            'signature' => 'v0='.strtolower($digest),
        ];
    }

    private function tolerance(SourceDefinition $source): int
    {
        $tolerance = $source->config['tolerance'] ?? 300;

        if (is_string($tolerance) && ctype_digit($tolerance)) {
            return (int) $tolerance;
        }

        return is_int($tolerance) ? $tolerance : 300;
    }

    private function stringFromFormPayload(Request $request, string $key): ?string
    {
        parse_str($request->getContent(), $payload);

        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
