<?php

declare(strict_types=1);

namespace Hookbox\Views;

final readonly class SourceView
{
    public function __construct(
        public ?string $id,
        public string $slug,
        public string $name,
        public bool $isActive,
    ) {}
}
