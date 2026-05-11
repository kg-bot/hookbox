<?php

declare(strict_types=1);

namespace Hookbox\Verifiers;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\SourceDefinition;
use Hookbox\VerificationResult;
use Illuminate\Http\Request;
use JsonException;

final class StripeVerifier implements Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $secret = $source->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing signing secret.');
        }

        $signature = $this->parseSignatureHeader($request->headers->get('Stripe-Signature'));

        if ($signature === null) {
            return new VerificationResult(VerificationStatus::INVALID, 'Missing or malformed Stripe-Signature header.');
        }

        $tolerance = $source->config['tolerance'] ?? 300;

        if (is_string($tolerance) && ctype_digit($tolerance)) {
            $tolerance = (int) $tolerance;
        }

        if (! is_int($tolerance)) {
            $tolerance = 300;
        }

        if (abs(time() - $signature['timestamp']) > $tolerance) {
            return new VerificationResult(VerificationStatus::INVALID, 'Timestamp outside tolerance.');
        }

        $expected = hash_hmac('sha256', $signature['timestamp'].'.'.$request->getContent(), $secret);

        foreach ($signature['signatures'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return new VerificationResult(VerificationStatus::VALID);
            }
        }

        return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        $payload = $this->payload($request);
        $id = $payload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        $payload = $this->payload($request);
        $type = $payload['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * @return array{timestamp:int, signatures:list<string>}|null
     */
    private function parseSignatureHeader(?string $header): ?array
    {
        if (! is_string($header) || $header === '') {
            return null;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $segment) {
            $parts = explode('=', trim($segment), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'signatures' => $signatures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
