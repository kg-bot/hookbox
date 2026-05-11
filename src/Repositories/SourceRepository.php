<?php

declare(strict_types=1);

namespace Hookbox\Repositories;

use Hookbox\SourceDefinition;
use Hookbox\SourceRegistry;
use Hookbox\Views\SourceCounters;
use Hookbox\Views\SourceView;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class SourceRepository
{
    public function __construct(
        private readonly SourceRegistry $sourceRegistry,
    ) {}

    /**
     * @return Collection<int, SourceView>
     */
    public function all(): Collection
    {
        /** @var Collection<string, SourceView> $sources */
        $sources = collect($this->sourceRegistry->all())
            ->mapWithKeys(fn (SourceDefinition $definition): array => [
                $definition->slug => $this->mapDefinition($definition),
            ]);

        DB::table('hookbox_sources')
            ->select(['id', 'slug', 'name', 'is_active'])
            ->orderBy('slug')
            ->get()
            ->each(function (stdClass $row) use ($sources): void {
                $sources->put((string) $row->slug, $this->mergeSource(
                    existing: $sources->get((string) $row->slug),
                    row: $row,
                ));
            });

        return $sources
            ->sortBy(fn (SourceView $source): string => $source->slug)
            ->values();
    }

    public function find(string $slug): ?SourceView
    {
        $definition = $this->sourceRegistry->find($slug);
        $row = DB::table('hookbox_sources')
            ->select(['id', 'slug', 'name', 'is_active'])
            ->where('slug', $slug)
            ->first();

        if ($row instanceof stdClass) {
            return $this->mergeSource(
                existing: $definition instanceof SourceDefinition ? $this->mapDefinition($definition) : null,
                row: $row,
            );
        }

        return $definition instanceof SourceDefinition
            ? $this->mapDefinition($definition)
            : null;
    }

    public function counters(string $slug, MetricsRange $range): SourceCounters
    {
        $messages = DB::table('hookbox_messages')
            ->join('hookbox_sources', 'hookbox_sources.id', '=', 'hookbox_messages.source_id')
            ->where('hookbox_sources.slug', $slug)
            ->whereBetween('hookbox_messages.received_at', [$range->from, $range->to])
            ->count();

        $attempts = DB::table('hookbox_attempts')
            ->join('hookbox_messages', 'hookbox_messages.id', '=', 'hookbox_attempts.message_id')
            ->join('hookbox_sources', 'hookbox_sources.id', '=', 'hookbox_messages.source_id')
            ->where('hookbox_sources.slug', $slug)
            ->whereBetween('hookbox_attempts.started_at', [$range->from, $range->to])
            ->count();

        return new SourceCounters(
            messages: $messages,
            attempts: $attempts,
        );
    }

    private function mapDefinition(SourceDefinition $definition): SourceView
    {
        return new SourceView(
            id: null,
            slug: $definition->slug,
            name: $definition->name,
            isActive: true,
        );
    }

    private function mergeSource(?SourceView $existing, stdClass $row): SourceView
    {
        return new SourceView(
            id: isset($row->id) ? (string) $row->id : null,
            slug: $existing->slug ?? (string) $row->slug,
            name: $existing->name ?? (string) $row->name,
            isActive: (bool) $row->is_active,
        );
    }
}
