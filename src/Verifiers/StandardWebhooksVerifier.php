<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Hookbox\Verifiers\Concerns\DecodesJsonPayload;
use Hookbox\Verifiers\Concerns\InteractsWithSignatureHeaders;
use Illuminate\Http\Request;

final class StandardWebhooksVerifier implements Verifier
{
    use DecodesJsonPayload;
    use InteractsWithSignatureHeaders;

    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $this->decodedSecret($source->config['secret'] ?? null);

        if ($secret === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $headers = $this->standardWebhookHeaders($request);

        if ($headers === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed Standard Webhooks headers.');
        }

        if (abs(time() - $headers['timestamp']) > $this->tolerance($source)) {
            return new VerificationResult(VerificationStatus::INVALID, 'Timestamp outside tolerance.');
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $headers['message_id'].'.'.$headers['timestamp'].'.'.$request->getContent(),
            $secret,
            true,
        ));

        foreach ($headers['signatures'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return new VerificationResult(VerificationStatus::VALID);
            }
        }

        return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, $source->config['idempotency_key_path'] ?? null)
            ?? $this->standardWebhookMessageId($request);
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        return $this->stringFromPayloadPath($request, $source->config['event_type_path'] ?? null)
            ?? $this->stringFromPayloadPath($request, 'type');
    }

    private function decodedSecret(mixed $secret): ?string
    {
        if (! is_string($secret) || $secret === '') {
            return null;
        }

        if (str_starts_with($secret, 'whsec_')) {
            $secret = substr($secret, 6);
        }

        if ($secret === '') {
            return null;
        }

        $decoded = base64_decode($secret, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function tolerance(SourceDefinition $source): int
    {
        $tolerance = $source->config['tolerance'] ?? 300;

        if (is_string($tolerance) && ctype_digit($tolerance)) {
            return (int) $tolerance;
        }

        return is_int($tolerance) ? $tolerance : 300;
    }
}
