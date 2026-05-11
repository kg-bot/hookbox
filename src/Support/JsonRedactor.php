<?php

declare(strict_types=1);

namespace Hookbox\Support;

use JsonException;

final class JsonRedactor
{
    /**
     * @param  list<string>  $paths
     */
    public function redact(string $json, array $paths): string
    {
        if ($paths === []) {
            return $json;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $json;
        }

        if (! is_array($payload)) {
            return $json;
        }

        foreach ($paths as $path) {
            $segments = $this->segments($path);

            if ($segments === []) {
                continue;
            }

            $this->redactPath($payload, $segments);
        }

        try {
            return (string) json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $json;
        }
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        $normalized = preg_replace('/^\$\./', '', $path);

        if (! is_string($normalized) || $normalized === '') {
            return [];
        }

        $segments = [];

        foreach (explode('.', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_ends_with($segment, '[*]')) {
                $prefix = substr($segment, 0, -3);

                if ($prefix !== '') {
                    $segments[] = $prefix;
                }

                $segments[] = '[*]';

                continue;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $segments
     */
    private function redactPath(array &$payload, array $segments): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segment === '[*]') {
            foreach ($payload as &$item) {
                if (! is_array($item)) {
                    continue;
                }

                if ($segments === []) {
                    $item = '[REDACTED]';

                    continue;
                }

                $this->redactPath($item, $segments);
            }

            return;
        }

        if (! array_key_exists($segment, $payload)) {
            return;
        }

        if ($segments === []) {
            $payload[$segment] = '[REDACTED]';

            return;
        }

        if (! is_array($payload[$segment])) {
            return;
        }

        $this->redactPath($payload[$segment], $segments);
    }
}
