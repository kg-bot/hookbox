<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Hookbox\Repositories\MessageFilters;
use Hookbox\Repositories\MessageRepository;
use Hookbox\Repositories\MetricsRange;
use Hookbox\Tests\TestCase;
use Hookbox\Views\AttemptStatusCounters;
use Hookbox\Views\MetricsSummary;
use Hookbox\Views\SignatureStatusCounters;
use Hookbox\Views\WebhookAttemptView;
use Hookbox\Views\WebhookMessageView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MessageRepositoryTest extends TestCase
{
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    public function test_support_types_use_explicit_message_reference_and_immutable_times(): void
    {
        $from = Carbon::parse('2026-05-09 09:00:00');
        $to = Carbon::parse('2026-05-09 11:00:00');
        $receivedAt = Carbon::parse('2026-05-09 10:00:00');
        $redactedAt = Carbon::parse('2026-05-09 10:01:00');
        $startedAt = Carbon::parse('2026-05-09 10:02:00');
        $finishedAt = Carbon::parse('2026-05-09 10:03:00');

        $filters = new MessageFilters(
            receivedFrom: $from,
            receivedTo: $to,
            messageReference: 'msg_123',
        );

        $range = new MetricsRange(from: $from, to: $to);
        $messageView = new WebhookMessageView(
            id: '01JTB2X4X8N8R8TZS8A7X4K6A8',
            sourceSlug: 'alpha',
            sourceName: 'Alpha Source',
            idempotencyKey: 'idem-123',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: $receivedAt,
            clientIp: '127.0.0.1',
            redactedAt: $redactedAt,
        );
        $attemptView = new WebhookAttemptView(
            id: '01JTB2X4X8N8R8TZS8A7X4K6A9',
            messageId: '01JTB2X4X8N8R8TZS8A7X4K6A8',
            kind: 'initial',
            handler: 'App\\WebhookHandler',
            status: 'succeeded',
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationMs: 1000,
            errorClass: null,
            errorMessage: null,
            triggeredBy: null,
        );
        $summary = new MetricsSummary(
            messages: new SignatureStatusCounters(valid: 1, invalid: 2, skipped: 3),
            attempts: new AttemptStatusCounters(pending: 4, succeeded: 5, failed: 6, skipped: 7),
        );

        $this->assertSame('msg_123', $filters->messageReference);
        $this->assertInstanceOf(CarbonImmutable::class, $filters->receivedFrom);
        $this->assertInstanceOf(CarbonImmutable::class, $range->to);
        $this->assertInstanceOf(CarbonImmutable::class, $messageView->receivedAt);
        $this->assertInstanceOf(CarbonImmutable::class, $messageView->redactedAt);
        $this->assertInstanceOf(CarbonImmutable::class, $attemptView->startedAt);
        $this->assertInstanceOf(CarbonImmutable::class, $attemptView->finishedAt);
        $this->assertSame($from->toIso8601String(), $filters->receivedFrom->toIso8601String());
        $this->assertSame($to->toIso8601String(), $range->to->toIso8601String());
        $this->assertSame($receivedAt->toIso8601String(), $messageView->receivedAt->toIso8601String());
        $this->assertSame($redactedAt->toIso8601String(), $messageView->redactedAt->toIso8601String());
        $this->assertSame($startedAt->toIso8601String(), $attemptView->startedAt->toIso8601String());
        $this->assertSame($finishedAt->toIso8601String(), $attemptView->finishedAt->toIso8601String());
        $this->assertSame(1, $summary->messages->valid);
        $this->assertSame(5, $summary->attempts->succeeded);
    }

    public function test_support_types_reject_inverted_time_windows(): void
    {
        $from = Carbon::parse('2026-05-09 11:00:00');
        $to = Carbon::parse('2026-05-09 09:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Received time window cannot be inverted.');

        new MessageFilters(receivedFrom: $from, receivedTo: $to);
    }

    public function test_metrics_range_rejects_inverted_time_windows(): void
    {
        $from = Carbon::parse('2026-05-09 11:00:00');
        $to = Carbon::parse('2026-05-09 09:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metrics range cannot be inverted.');

        new MetricsRange(from: $from, to: $to);
    }

    public function test_paginate_returns_newest_first_message_views_and_applies_supported_filters(): void
    {
        $alphaSourceId = $this->createSource('alpha', 'Alpha Source');
        $betaSourceId = $this->createSource('beta', 'Beta Source');

        $sourceLessMessageId = $this->createMessage(
            sourceId: null,
            idempotencyKey: 'idem-source-less',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 13:00:00'),
        );

        $oldestMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-oldest',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 08:00:00'),
        );

        $sharedIdempotencyAlphaMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-shared-across-sources',
            eventType: 'customer.created',
            signatureStatus: 'skipped',
            receivedAt: Carbon::parse('2026-05-09 07:30:00'),
        );

        $sharedIdempotencyBetaMessageId = $this->createMessage(
            sourceId: $betaSourceId,
            idempotencyKey: 'idem-shared-across-sources',
            eventType: 'customer.created',
            signatureStatus: 'skipped',
            receivedAt: Carbon::parse('2026-05-09 07:00:00'),
        );

        $matchingMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-match',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 10:00:00'),
            clientIp: '127.0.0.10',
            redactedAt: Carbon::parse('2026-05-09 10:01:00'),
        );

        $wrongSourceMessageId = $this->createMessage(
            sourceId: $betaSourceId,
            idempotencyKey: 'idem-wrong-source',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 10:00:00'),
        );

        $wrongStatusMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-wrong-status',
            eventType: 'invoice.created',
            signatureStatus: 'invalid',
            receivedAt: Carbon::parse('2026-05-09 10:00:00'),
        );

        $wrongEventTypeMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-wrong-event',
            eventType: 'invoice.failed',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 10:00:00'),
        );

        $wrongWindowMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'idem-wrong-window',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 11:30:00'),
        );

        $newestOtherMessageId = $this->createMessage(
            sourceId: $betaSourceId,
            idempotencyKey: 'idem-newest',
            eventType: 'invoice.failed',
            signatureStatus: 'invalid',
            receivedAt: Carbon::parse('2026-05-09 12:00:00'),
        );

        $repository = $this->appInstance()->make(MessageRepository::class);

        $allMessages = $repository->paginate(new MessageFilters, 10);
        $allMessageItems = $this->messageItems($allMessages);
        $allMessageIds = $allMessageItems->pluck('id')->all();
        $this->assertInstanceOf(LengthAwarePaginator::class, $allMessages);
        $this->assertSame($sourceLessMessageId, $allMessageIds[0]);
        $this->assertSame($newestOtherMessageId, $allMessageIds[1]);
        $this->assertSame($wrongWindowMessageId, $allMessageIds[2]);
        $this->assertEqualsCanonicalizing([
            $matchingMessageId,
            $wrongSourceMessageId,
            $wrongStatusMessageId,
            $wrongEventTypeMessageId,
        ], array_slice($allMessageIds, 3, 4));
        $this->assertSame($oldestMessageId, $allMessageIds[7]);
        $this->assertSame($sharedIdempotencyAlphaMessageId, $allMessageIds[8]);
        $this->assertSame($sharedIdempotencyBetaMessageId, $allMessageIds[9]);
        $sourceLessMessage = $this->messageById($allMessageItems, $sourceLessMessageId);
        $newestOtherMessage = $this->messageById($allMessageItems, $newestOtherMessageId);
        $matchingMessage = $this->messageById($allMessageItems, $matchingMessageId);
        $wrongSourceMessage = $this->messageById($allMessageItems, $wrongSourceMessageId);
        $wrongStatusMessage = $this->messageById($allMessageItems, $wrongStatusMessageId);
        $wrongEventTypeMessage = $this->messageById($allMessageItems, $wrongEventTypeMessageId);

        $this->assertNull($sourceLessMessage->sourceSlug);
        $this->assertNull($sourceLessMessage->sourceName);
        $this->assertSame('beta', $newestOtherMessage->sourceSlug);
        $this->assertSame('Beta Source', $newestOtherMessage->sourceName);
        $this->assertSame('invalid', $newestOtherMessage->signatureStatus);
        $this->assertSame('invoice.failed', $newestOtherMessage->eventType);
        $this->assertSame('idem-newest', $newestOtherMessage->idempotencyKey);
        $this->assertSame('alpha', $matchingMessage->sourceSlug);
        $this->assertSame('Alpha Source', $matchingMessage->sourceName);
        $this->assertSame('valid', $matchingMessage->signatureStatus);
        $this->assertSame('invoice.created', $matchingMessage->eventType);
        $this->assertSame('idem-match', $matchingMessage->idempotencyKey);
        $this->assertSame('beta', $wrongSourceMessage->sourceSlug);
        $this->assertSame('invalid', $wrongStatusMessage->signatureStatus);
        $this->assertSame('invoice.failed', $wrongEventTypeMessage->eventType);
        $this->assertSame(['127.0.0.10'], $allMessageItems->pluck('clientIp')->filter()->values()->all());
        $this->assertSame([$matchingMessageId], $allMessageItems->filter(static fn (WebhookMessageView $view): bool => $view->redactedAt !== null)->pluck('id')->all());

        $sourceFilteredMessages = $repository->paginate(new MessageFilters(sourceSlug: 'alpha'), 10);
        $sourceFilteredIds = $this->messageItems($sourceFilteredMessages)->pluck('id')->all();

        $this->assertSame($wrongWindowMessageId, $sourceFilteredIds[0]);
        $this->assertEqualsCanonicalizing([
            $matchingMessageId,
            $wrongStatusMessageId,
            $wrongEventTypeMessageId,
        ], array_slice($sourceFilteredIds, 1, 3));
        $this->assertSame($oldestMessageId, $sourceFilteredIds[4]);
        $this->assertSame($sharedIdempotencyAlphaMessageId, $sourceFilteredIds[5]);

        $statusFilteredMessages = $repository->paginate(new MessageFilters(signatureStatus: 'valid'), 10);
        $statusFilteredIds = $this->messageItems($statusFilteredMessages)->pluck('id')->all();

        $this->assertSame($sourceLessMessageId, $statusFilteredIds[0]);
        $this->assertSame($wrongWindowMessageId, $statusFilteredIds[1]);
        $this->assertEqualsCanonicalizing([
            $matchingMessageId,
            $wrongSourceMessageId,
            $wrongEventTypeMessageId,
        ], array_slice($statusFilteredIds, 2, 3));
        $this->assertSame($oldestMessageId, $statusFilteredIds[5]);

        $eventTypeFilteredMessages = $repository->paginate(new MessageFilters(eventType: 'invoice.created'), 10);
        $eventTypeFilteredIds = $this->messageItems($eventTypeFilteredMessages)->pluck('id')->all();

        $this->assertSame($sourceLessMessageId, $eventTypeFilteredIds[0]);
        $this->assertSame($wrongWindowMessageId, $eventTypeFilteredIds[1]);
        $this->assertEqualsCanonicalizing([
            $matchingMessageId,
            $wrongSourceMessageId,
            $wrongStatusMessageId,
        ], array_slice($eventTypeFilteredIds, 2, 3));
        $this->assertSame($oldestMessageId, $eventTypeFilteredIds[5]);

        $timeWindowFilteredMessages = $repository->paginate(new MessageFilters(
            receivedFrom: Carbon::parse('2026-05-09 09:00:00'),
            receivedTo: Carbon::parse('2026-05-09 11:00:00'),
        ), 10);

        $this->assertEqualsCanonicalizing([
            $matchingMessageId,
            $wrongSourceMessageId,
            $wrongStatusMessageId,
            $wrongEventTypeMessageId,
        ], $this->messageItems($timeWindowFilteredMessages)->pluck('id')->all());

        $filteredMessages = $repository->paginate(new MessageFilters(
            sourceSlug: 'alpha',
            signatureStatus: 'valid',
            eventType: 'invoice.created',
            receivedFrom: Carbon::parse('2026-05-09 09:00:00'),
            receivedTo: Carbon::parse('2026-05-09 11:00:00'),
        ), 10);

        $this->assertSame([$matchingMessageId], $this->messageItems($filteredMessages)->pluck('id')->all());

        $byMessageId = $repository->paginate(new MessageFilters(messageReference: $matchingMessageId), 10);

        $this->assertSame([$matchingMessageId], $this->messageItems($byMessageId)->pluck('id')->all());

        $byIdempotencyKey = $repository->paginate(new MessageFilters(messageReference: 'idem-oldest'), 10);

        $this->assertSame([$oldestMessageId], $this->messageItems($byIdempotencyKey)->pluck('id')->all());

        $bySharedIdempotencyKey = $repository->paginate(new MessageFilters(messageReference: 'idem-shared-across-sources'), 10);

        $this->assertSame([
            $sharedIdempotencyAlphaMessageId,
            $sharedIdempotencyBetaMessageId,
        ], $this->messageItems($bySharedIdempotencyKey)->pluck('id')->all());
    }

    public function test_find_returns_a_mapped_message_view_or_null(): void
    {
        $sourceId = $this->createSource('alpha', 'Alpha Source');
        $messageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-find',
            eventType: 'invoice.created',
            signatureStatus: 'skipped',
            receivedAt: Carbon::parse('2026-05-09 13:00:00'),
            clientIp: '192.0.2.1',
            redactedAt: Carbon::parse('2026-05-09 13:05:00'),
        );

        $repository = $this->appInstance()->make(MessageRepository::class);

        $view = $repository->find($messageId);

        $this->assertNotNull($view);
        $this->assertSame($messageId, $view->id);
        $this->assertSame('alpha', $view->sourceSlug);
        $this->assertSame('Alpha Source', $view->sourceName);
        $this->assertSame('idem-find', $view->idempotencyKey);
        $this->assertSame('invoice.created', $view->eventType);
        $this->assertSame('skipped', $view->signatureStatus);
        $this->assertSame('192.0.2.1', $view->clientIp);
        $this->assertNotNull($view->redactedAt);

        $this->assertNull($repository->find((string) Str::ulid()));
    }

    public function test_find_returns_source_less_message_views_without_dropping_them(): void
    {
        $messageId = $this->createMessage(
            sourceId: null,
            idempotencyKey: 'idem-find-source-less',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 13:30:00'),
        );

        $repository = $this->appInstance()->make(MessageRepository::class);

        $view = $repository->find($messageId);

        $this->assertNotNull($view);
        $this->assertSame($messageId, $view->id);
        $this->assertNull($view->sourceSlug);
        $this->assertNull($view->sourceName);
    }

    public function test_attempts_returns_newest_first_attempt_views(): void
    {
        $sourceId = $this->createSource('alpha', 'Alpha Source');
        $messageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-attempts',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 14:00:00'),
        );

        $oldestAttemptId = $this->createAttempt(
            messageId: $messageId,
            kind: 'initial',
            handler: 'FirstHandler',
            status: 'failed',
            startedAt: Carbon::parse('2026-05-09 14:01:00'),
            finishedAt: Carbon::parse('2026-05-09 14:01:05'),
            durationMs: 5000,
            errorClass: 'RuntimeException',
            errorMessage: 'First failure.',
            triggeredBy: null,
        );

        $newestAttemptId = $this->createAttempt(
            messageId: $messageId,
            kind: 'replay',
            handler: 'SecondHandler',
            status: 'succeeded',
            startedAt: Carbon::parse('2026-05-09 14:05:00'),
            finishedAt: Carbon::parse('2026-05-09 14:05:02'),
            durationMs: 2000,
            errorClass: null,
            errorMessage: null,
            triggeredBy: 'task-7',
        );

        $repository = $this->appInstance()->make(MessageRepository::class);

        $attempts = $repository->attempts($messageId);

        $this->assertCount(2, $attempts);
        $this->assertSame([$newestAttemptId, $oldestAttemptId], $attempts->pluck('id')->all());
        $this->assertSame(['replay', 'initial'], $attempts->pluck('kind')->all());
        $this->assertSame(['succeeded', 'failed'], $attempts->pluck('status')->all());
        $this->assertSame(['SecondHandler', 'FirstHandler'], $attempts->pluck('handler')->all());
        $this->assertSame(['task-7', null], $attempts->pluck('triggeredBy')->all());
        $this->assertSame([2000, 5000], $attempts->pluck('durationMs')->all());
        $this->assertSame([null, 'RuntimeException'], $attempts->pluck('errorClass')->all());
        $this->assertSame([null, 'First failure.'], $attempts->pluck('errorMessage')->all());
    }

    public function test_metrics_returns_count_only_totals_for_message_and_attempt_statuses_within_range(): void
    {
        $sourceId = $this->createSource('alpha', 'Alpha Source');

        $includedValidMessageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-metrics-valid',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 15:00:00'),
        );

        $includedInvalidMessageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-metrics-invalid',
            eventType: 'invoice.failed',
            signatureStatus: 'invalid',
            receivedAt: Carbon::parse('2026-05-09 15:10:00'),
        );

        $includedSkippedMessageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-metrics-skipped',
            eventType: 'invoice.updated',
            signatureStatus: 'skipped',
            receivedAt: Carbon::parse('2026-05-09 15:20:00'),
        );

        $excludedMessageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-metrics-outside',
            eventType: 'invoice.deleted',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 16:30:00'),
        );

        $this->createAttempt(
            messageId: $includedValidMessageId,
            kind: 'initial',
            handler: 'IncludedPendingHandler',
            status: 'pending',
            startedAt: Carbon::parse('2026-05-09 15:01:00'),
        );

        $this->createAttempt(
            messageId: $includedInvalidMessageId,
            kind: 'replay',
            handler: 'IncludedSucceededHandler',
            status: 'succeeded',
            startedAt: Carbon::parse('2026-05-09 15:11:00'),
            finishedAt: Carbon::parse('2026-05-09 15:11:02'),
            durationMs: 2000,
        );

        $this->createAttempt(
            messageId: $includedSkippedMessageId,
            kind: 'dry_run',
            handler: 'IncludedFailedHandler',
            status: 'failed',
            startedAt: Carbon::parse('2026-05-09 15:21:00'),
            finishedAt: Carbon::parse('2026-05-09 15:21:05'),
            durationMs: 5000,
            errorClass: 'RuntimeException',
            errorMessage: 'Replay failed.',
        );

        $this->createAttempt(
            messageId: $includedSkippedMessageId,
            kind: 'replay',
            handler: 'IncludedSkippedHandler',
            status: 'skipped',
            startedAt: Carbon::parse('2026-05-09 15:25:00'),
        );

        $lateAttemptOnIncludedMessageId = $this->createAttempt(
            messageId: $includedValidMessageId,
            kind: 'replay',
            handler: 'LateAttemptHandler',
            status: 'succeeded',
            startedAt: Carbon::parse('2026-05-09 16:10:00'),
            finishedAt: Carbon::parse('2026-05-09 16:10:01'),
            durationMs: 1000,
        );

        $earlyMessageLateAttemptMessageId = $this->createMessage(
            sourceId: $sourceId,
            idempotencyKey: 'idem-metrics-early-message-late-attempt',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 14:30:00'),
        );

        $includedByAttemptTimeAttemptId = $this->createAttempt(
            messageId: $earlyMessageLateAttemptMessageId,
            kind: 'replay',
            handler: 'StartedInsideWindowHandler',
            status: 'pending',
            startedAt: Carbon::parse('2026-05-09 15:40:00'),
        );

        $this->createAttempt(
            messageId: $excludedMessageId,
            kind: 'replay',
            handler: 'ExcludedHandler',
            status: 'succeeded',
            startedAt: Carbon::parse('2026-05-09 16:31:00'),
            finishedAt: Carbon::parse('2026-05-09 16:31:01'),
            durationMs: 1000,
        );

        $repository = $this->appInstance()->make(MessageRepository::class);

        $summary = $repository->metrics(new MetricsRange(
            from: Carbon::parse('2026-05-09 15:00:00'),
            to: Carbon::parse('2026-05-09 16:00:00'),
        ));

        $this->assertSame(1, $summary->messages->valid);
        $this->assertSame(1, $summary->messages->invalid);
        $this->assertSame(1, $summary->messages->skipped);
        $this->assertSame(2, $summary->attempts->pending);
        $this->assertSame(1, $summary->attempts->succeeded);
        $this->assertSame(1, $summary->attempts->failed);
        $this->assertSame(1, $summary->attempts->skipped);
        $this->assertNotSame($lateAttemptOnIncludedMessageId, $includedByAttemptTimeAttemptId);
        $this->assertObjectNotHasProperty('items', $summary);
        $this->assertObjectNotHasProperty('rows', $summary->messages);
        $this->assertObjectNotHasProperty('rows', $summary->attempts);
    }

    private function createSource(string $slug, string $name): string
    {
        $sourceId = (string) Str::ulid();
        $timestamp = Carbon::parse('2026-05-09 00:00:00');

        DB::table('hookbox_sources')->insert([
            'id' => $sourceId,
            'slug' => $slug,
            'name' => $name,
            'verifier' => 'Tests\\FakeVerifier',
            'config' => json_encode([
                'signing_secret' => 'secret',
                'nested' => ['token' => 'abc123'],
            ], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $sourceId;
    }

    private function createMessage(
        ?string $sourceId,
        string $idempotencyKey,
        string $eventType,
        string $signatureStatus,
        Carbon $receivedAt,
        ?string $clientIp = null,
        ?Carbon $redactedAt = null,
    ): string {
        $messageId = (string) Str::ulid();

        DB::table('hookbox_messages')->insert([
            'id' => $messageId,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'event_type' => $eventType,
            'headers' => json_encode(['content-type' => ['application/json']], JSON_THROW_ON_ERROR),
            'body' => json_encode(['id' => $idempotencyKey, 'type' => $eventType], JSON_THROW_ON_ERROR),
            'body_hash' => hash('sha256', $idempotencyKey.$eventType),
            'signature_status' => $signatureStatus,
            'received_at' => $receivedAt,
            'client_ip' => $clientIp,
            'redacted_at' => $redactedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        return $messageId;
    }

    private function createAttempt(
        string $messageId,
        string $kind,
        string $handler,
        string $status,
        Carbon $startedAt,
        ?Carbon $finishedAt = null,
        ?int $durationMs = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
        ?string $triggeredBy = null,
    ): string {
        $attemptId = (string) Str::ulid();

        DB::table('hookbox_attempts')->insert([
            'id' => $attemptId,
            'message_id' => $messageId,
            'kind' => $kind,
            'handler' => $handler,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
            'error_trace' => $errorMessage === null ? null : 'stack trace',
            'triggered_by' => $triggeredBy,
            'created_at' => $startedAt,
            'updated_at' => $finishedAt ?? $startedAt,
        ]);

        return $attemptId;
    }

    /**
     * @param  LengthAwarePaginator<int, WebhookMessageView>  $paginator
     * @return Collection<int, WebhookMessageView>
     */
    private function messageItems(LengthAwarePaginator $paginator): Collection
    {
        return collect($paginator->items());
    }

    /**
     * @param  Collection<int, WebhookMessageView>  $messages
     */
    private function messageById(Collection $messages, string $id): WebhookMessageView
    {
        $message = $messages->firstWhere('id', $id);

        $this->assertInstanceOf(WebhookMessageView::class, $message);

        return $message;
    }
}
