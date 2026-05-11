<?php

declare(strict_types=1);

namespace Hookbox\Verifiers\Concerns;

use Illuminate\Http\Request;

trait InteractsWithSignatureHeaders
{
    private function standardWebhookMessageId(Request $request): ?string
    {
        $messageId = $request->headers->get('webhook-id');

        if (! is_string($messageId) || $messageId === '' || str_contains($messageId, '.')) {
            return null;
        }

        return $messageId;
    }

    /**
     * @return array{message_id:string, timestamp:int, signatures:list<string>}|null
     */
    private function standardWebhookHeaders(Request $request): ?array
    {
        $messageId = $this->standardWebhookMessageId($request);
        $timestamp = $request->headers->get('webhook-timestamp');
        $signatureHeader = $request->headers->get('webhook-signature');

        if ($messageId === null) {
            return null;
        }

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || $timestamp === '0') {
            return null;
        }

        if (! is_string($signatureHeader) || trim($signatureHeader) === '') {
            return null;
        }

        $signatures = [];

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $candidate) {
            $parts = explode(',', $candidate, 2);

            if (count($parts) !== 2) {
                return null;
            }

            [$version, $signature] = $parts;

            if ($version !== 'v1') {
                continue;
            }

            if ($signature === '' || base64_decode($signature, true) === false) {
                return null;
            }

            $signatures[] = $signature;
        }

        if ($signatures === []) {
            return null;
        }

        return [
            'message_id' => $messageId,
            'timestamp' => (int) $timestamp,
            'signatures' => $signatures,
        ];
    }
}
