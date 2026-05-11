<?php

declare(strict_types=1);

namespace Hookbox;

final readonly class SourceDefinition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $verifier,
        public array $config = [],
    ) {}
}
