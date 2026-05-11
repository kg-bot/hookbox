<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Illuminate\Http\Request;

final class ShopifyVerifier implements Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $source->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $signature = $this->signature($request);

        if ($signature === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed X-Shopify-Hmac-SHA256 header.');
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($expected, $signature)) {
            return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
        }

        return new VerificationResult(VerificationStatus::VALID);
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        $webhookId = $request->headers->get('X-Shopify-Webhook-Id');

        return is_string($webhookId) && $webhookId !== '' ? $webhookId : null;
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        $topic = $request->headers->get('X-Shopify-Topic');

        return is_string($topic) && $topic !== '' ? $topic : null;
    }

    private function signature(Request $request): ?string
    {
        $header = $request->headers->get('X-Shopify-Hmac-SHA256');

        if (! is_string($header) || strlen($header) !== 44) {
            return null;
        }

        $decoded = base64_decode($header, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }

        return $header;
    }
}
