<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Illuminate\Http\Request;

final class GitHubVerifier implements Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $source->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $signature = $this->signature($request);

        if ($signature === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed X-Hub-Signature-256 header.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
        }

        return new VerificationResult(VerificationStatus::VALID);
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        $delivery = $request->headers->get('X-GitHub-Delivery');

        return is_string($delivery) && $delivery !== '' ? $delivery : null;
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        $event = $request->headers->get('X-GitHub-Event');

        return is_string($event) && $event !== '' ? $event : null;
    }

    private function signature(Request $request): ?string
    {
        $header = $request->headers->get('X-Hub-Signature-256');

        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return null;
        }

        $signature = substr($header, 7);

        return strlen($signature) === 64 && ctype_xdigit($signature) ? strtolower($signature) : null;
    }
}
