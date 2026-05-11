<?php

declare(strict_types=1);

namespace Hookbox\Verifiers\Concerns;

use Illuminate\Http\Request;
use JsonException;

trait DecodesJsonPayload
{
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

    private function stringFromPayloadPath(Request $request, ?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $value = data_get($this->payload($request), $path);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
