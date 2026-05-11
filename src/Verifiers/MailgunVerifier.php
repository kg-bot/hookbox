<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Hookbox\Verifiers\Concerns\DecodesJsonPayload;
use Illuminate\Http\Request;

final class MailgunVerifier implements Verifier
{
    use DecodesJsonPayload;

    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $source->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $signature = $this->signaturePayload($request);

        if ($signature === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed Mailgun signature payload.');
        }

        $expected = hash_hmac('sha256', $signature['timestamp'].$signature['token'], $secret);

        if (! hash_equals($expected, $signature['signature'])) {
            return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
        }

        return new VerificationResult(VerificationStatus::VALID);
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'id');
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, 'event');
    }

    /**
     * @return array{timestamp:string, token:string, signature:string}|null
     */
    private function signaturePayload(Request $request): ?array
    {
        $payload = $this->payload($request);
        $timestamp = data_get($payload, 'signature.timestamp');
        $token = data_get($payload, 'signature.token');
        $signature = data_get($payload, 'signature.signature');

        if (! is_string($timestamp) || $timestamp === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (! is_string($signature) || strlen($signature) !== 64 || ! ctype_xdigit($signature)) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => strtolower($signature),
        ];
    }
}
