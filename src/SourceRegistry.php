<?php

declare(strict_types=1);

namespace Hookbox;

use Illuminate\Contracts\Config\Repository;

final class SourceRegistry
{
    /**
     * @var array<string, SourceDefinition>
     */
    private array $definitions;

    public function __construct(Repository $config)
    {
        $this->definitions = [];

        /** @var array<string, array{name?: string, verifier: string, ...<string, mixed>}> $sources */
        $sources = $config->get('hookbox.sources', []);

        foreach ($sources as $slug => $source) {
            $definitionConfig = $source;

            unset($definitionConfig['name'], $definitionConfig['verifier']);

            $this->definitions[$slug] = new SourceDefinition(
                slug: $slug,
                name: $source['name'] ?? $slug,
                verifier: $source['verifier'],
                config: $definitionConfig,
            );
        }
    }

    /**
     * @return array<int, SourceDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $slug): ?SourceDefinition
    {
        return $this->definitions[$slug] ?? null;
    }

    public function register(SourceDefinition $definition): void
    {
        $this->definitions[$definition->slug] = $definition;
    }
}
