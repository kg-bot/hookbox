<?php

declare(strict_types=1);

namespace Hookbox\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hookbox\Enums\AttemptStatus;
use Hookbox\Enums\VerificationStatus;
use Hookbox\Views\AttemptStatusCounters;
use Hookbox\Views\MetricsSummary;
use Hookbox\Views\SignatureStatusCounters;
use Hookbox\Views\WebhookAttemptView;
use Hookbox\Views\WebhookMessageView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class MessageRepository
{
    /**
     * @return LengthAwarePaginator<int, WebhookMessageView>
     */
    public function paginate(MessageFilters $filters, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->messageQuery($filters)
            ->orderByDesc('hookbox_messages.received_at')
            ->paginate($perPage);

        $paginator->setCollection($paginator->getCollection()->map(
            fn (stdClass $row): WebhookMessageView => $this->mapMessage($row),
        ));

        return $paginator;
    }

    public function find(string $id): ?WebhookMessageView
    {
        $row = $this->messageQuery(new MessageFilters)
            ->where('hookbox_messages.id', $id)
            ->first();

        return $row instanceof stdClass ? $this->mapMessage($row) : null;
    }

    /**
     * @return Collection<int, WebhookAttemptView>
     */
    public function attempts(string $messageId): Collection
    {
        return DB::table('hookbox_attempts')
            ->select([
                'id',
                'message_id',
                'kind',
                'handler',
                'status',
                'started_at',
                'finished_at',
                'duration_ms',
                'error_class',
                'error_message',
                'triggered_by',
            ])
            ->where('message_id', $messageId)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (stdClass $row): WebhookAttemptView => $this->mapAttempt($row));
    }

    public function metrics(MetricsRange $range): MetricsSummary
    {
        return new MetricsSummary(
            messages: $this->mapMessageCounts($this->messageMetrics($range)),
            attempts: $this->mapAttemptCounts($this->attemptMetrics($range)),
        );
    }

    private function messageQuery(MessageFilters $filters): Builder
    {
        $query = DB::table('hookbox_messages')
            ->leftJoin('hookbox_sources', 'hookbox_sources.id', '=', 'hookbox_messages.source_id')
            ->select([
                'hookbox_messages.id',
                'hookbox_messages.idempotency_key',
                'hookbox_messages.event_type',
                'hookbox_messages.signature_status',
                'hookbox_messages.received_at',
                'hookbox_messages.client_ip',
                'hookbox_messages.redacted_at',
                'hookbox_sources.slug as source_slug',
                'hookbox_sources.name as source_name',
            ]);

        if ($filters->sourceSlug !== null) {
            $query->where('hookbox_sources.slug', $filters->sourceSlug);
        }

        if ($filters->signatureStatus !== null) {
            $query->where('hookbox_messages.signature_status', $filters->signatureStatus);
        }

        if ($filters->eventType !== null) {
            $query->where('hookbox_messages.event_type', $filters->eventType);
        }

        if ($filters->receivedFrom !== null) {
            $query->where('hookbox_messages.received_at', '>=', $filters->receivedFrom);
        }

        if ($filters->receivedTo !== null) {
            $query->where('hookbox_messages.received_at', '<=', $filters->receivedTo);
        }

        if ($filters->messageReference !== null) {
            $query->where(function (Builder $query) use ($filters): void {
                $query
                    ->where('hookbox_messages.id', $filters->messageReference)
                    ->orWhere('hookbox_messages.idempotency_key', $filters->messageReference);
            });
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function messageMetrics(MetricsRange $range): array
    {
        /** @var Collection<int, stdClass> $rows */
        $rows = DB::table('hookbox_messages')
            ->select('signature_status', DB::raw('count(*) as aggregate'))
            ->whereBetween('received_at', [$range->from, $range->to])
            ->groupBy('signature_status')
            ->get();

        return $this->countsByKey($rows, 'signature_status');
    }

    /**
     * @return array<string, int>
     */
    private function attemptMetrics(MetricsRange $range): array
    {
        /** @var Collection<int, stdClass> $rows */
        $rows = DB::table('hookbox_attempts')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->whereBetween('started_at', [$range->from, $range->to])
            ->groupBy('status')
            ->get();

        return $this->countsByKey($rows, 'status');
    }

    private function mapMessage(stdClass $row): WebhookMessageView
    {
        return new WebhookMessageView(
            id: $row->id,
            sourceSlug: $this->nullableString($row->source_slug ?? null),
            sourceName: $this->nullableString($row->source_name ?? null),
            idempotencyKey: $this->nullableString($row->idempotency_key ?? null),
            eventType: $this->nullableString($row->event_type ?? null),
            signatureStatus: (string) $row->signature_status,
            receivedAt: $this->toImmutableOrFail($row->received_at),
            clientIp: $this->nullableString($row->client_ip ?? null),
            redactedAt: $this->toImmutable($row->redacted_at ?? null),
        );
    }

    private function mapAttempt(stdClass $row): WebhookAttemptView
    {
        return new WebhookAttemptView(
            id: $row->id,
            messageId: $row->message_id,
            kind: $row->kind,
            handler: $row->handler,
            status: $row->status,
            startedAt: $this->toImmutable($row->started_at ?? null),
            finishedAt: $this->toImmutable($row->finished_at ?? null),
            durationMs: isset($row->duration_ms) ? (int) $row->duration_ms : null,
            errorClass: $this->nullableString($row->error_class ?? null),
            errorMessage: $this->nullableString($row->error_message ?? null),
            triggeredBy: $this->nullableString($row->triggered_by ?? null),
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function mapMessageCounts(array $counts): SignatureStatusCounters
    {
        return new SignatureStatusCounters(
            valid: $counts[VerificationStatus::VALID->value] ?? 0,
            invalid: $counts[VerificationStatus::INVALID->value] ?? 0,
            skipped: $counts[VerificationStatus::SKIPPED->value] ?? 0,
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function mapAttemptCounts(array $counts): AttemptStatusCounters
    {
        return new AttemptStatusCounters(
            pending: $counts[AttemptStatus::PENDING->value] ?? 0,
            succeeded: $counts[AttemptStatus::SUCCEEDED->value] ?? 0,
            failed: $counts[AttemptStatus::FAILED->value] ?? 0,
            skipped: $counts[AttemptStatus::SKIPPED->value] ?? 0,
        );
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, int>
     */
    private function countsByKey(Collection $rows, string $keyColumn): array
    {
        return $rows
            ->filter(static fn (stdClass $row): bool => isset($row->{$keyColumn}))
            ->mapWithKeys(static fn (stdClass $row): array => [
                (string) $row->{$keyColumn} => (int) $row->aggregate,
            ])
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        return CarbonImmutable::parse((string) $value);
    }

    private function toImmutableOrFail(mixed $value): CarbonImmutable
    {
        return $this->toImmutable($value)
            ?? throw new \UnexpectedValueException('Expected a non-null timestamp value.');
    }
}
