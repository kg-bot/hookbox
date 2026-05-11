<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Repositories;

use Hookbox\Repositories\MetricsRange;
use Hookbox\Repositories\SourceRepository;
use Hookbox\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SourceRepositoryTest extends TestCase
{
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    public function test_all_and_find_return_source_views_without_leaking_raw_config(): void
    {
        config()->set('hookbox.sources', [
            'configured-only' => [
                'name' => 'Configured Only Source',
                'verifier' => 'Tests\\ConfiguredOnlyVerifier',
                'secret' => 'configured-only-secret',
            ],
        ]);

        $this->appInstance()->forgetScopedInstances();

        $alphaSourceId = $this->createSource('alpha', 'Alpha Source', true, [
            'signing_secret' => 'alpha-secret',
            'endpoint' => 'https://alpha.example.test',
        ]);

        $betaSourceId = $this->createSource('beta', 'Beta Source', false, [
            'signing_secret' => 'beta-secret',
            'endpoint' => 'https://beta.example.test',
        ]);

        $repository = $this->appInstance()->make(SourceRepository::class);

        $sources = $repository->all();

        $this->assertCount(3, $sources);
        $this->assertSame([$alphaSourceId, $betaSourceId, null], $sources->pluck('id')->all());
        $this->assertSame(['alpha', 'beta', 'configured-only'], $sources->pluck('slug')->all());
        $this->assertSame(['Alpha Source', 'Beta Source', 'Configured Only Source'], $sources->pluck('name')->all());
        $this->assertSame([true, false, true], $sources->pluck('isActive')->all());

        foreach ($sources as $source) {
            $this->assertObjectNotHasProperty('config', $source);
            $this->assertObjectNotHasProperty('verifier', $source);
        }

        $found = $repository->find('beta');

        $this->assertNotNull($found);
        $this->assertSame($betaSourceId, $found->id);
        $this->assertSame('beta', $found->slug);
        $this->assertSame('Beta Source', $found->name);
        $this->assertFalse($found->isActive);
        $this->assertObjectNotHasProperty('config', $found);
        $this->assertObjectNotHasProperty('verifier', $found);

        $configuredOnly = $repository->find('configured-only');

        $this->assertNotNull($configuredOnly);
        $this->assertNull($configuredOnly->id);
        $this->assertSame('configured-only', $configuredOnly->slug);
        $this->assertSame('Configured Only Source', $configuredOnly->name);
        $this->assertTrue($configuredOnly->isActive);
        $this->assertObjectNotHasProperty('config', $configuredOnly);
        $this->assertObjectNotHasProperty('verifier', $configuredOnly);

        $this->assertNull($repository->find('missing-source'));
    }

    public function test_counters_return_count_only_message_and_attempt_totals_scoped_to_one_source(): void
    {
        $alphaSourceId = $this->createSource('alpha', 'Alpha Source', true, [
            'signing_secret' => 'alpha-secret',
        ]);
        $betaSourceId = $this->createSource('beta', 'Beta Source', true, [
            'signing_secret' => 'beta-secret',
        ]);

        $alphaIncludedMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'alpha-included',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 10:00:00'),
        );

        $alphaIncludedSecondMessageId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'alpha-second',
            eventType: 'invoice.failed',
            signatureStatus: 'invalid',
            receivedAt: Carbon::parse('2026-05-09 10:30:00'),
        );

        $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'alpha-outside',
            eventType: 'invoice.updated',
            signatureStatus: 'skipped',
            receivedAt: Carbon::parse('2026-05-09 13:00:00'),
        );

        $betaIncludedMessageId = $this->createMessage(
            sourceId: $betaSourceId,
            idempotencyKey: 'beta-included',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 10:15:00'),
        );

        $this->createAttempt(
            messageId: $alphaIncludedMessageId,
            kind: 'initial',
            handler: 'AlphaSucceededHandler',
            status: 'succeeded',
            startedAt: Carbon::parse('2026-05-09 10:05:00'),
        );

        $this->createAttempt(
            messageId: $alphaIncludedSecondMessageId,
            kind: 'replay',
            handler: 'AlphaFailedHandler',
            status: 'failed',
            startedAt: Carbon::parse('2026-05-09 10:35:00'),
        );

        $lateAttemptOnIncludedMessageId = $this->createAttempt(
            messageId: $alphaIncludedMessageId,
            kind: 'replay',
            handler: 'AlphaLateAttemptHandler',
            status: 'pending',
            startedAt: Carbon::parse('2026-05-09 12:15:00'),
        );

        $alphaOutsideMessageId = DB::table('hookbox_messages')
            ->where('idempotency_key', '=', 'alpha-outside')
            ->value('id');

        $this->assertIsString($alphaOutsideMessageId);

        $this->createAttempt(
            messageId: $alphaOutsideMessageId,
            kind: 'replay',
            handler: 'AlphaOutsideHandler',
            status: 'pending',
            startedAt: Carbon::parse('2026-05-09 13:05:00'),
        );

        $alphaEarlyMessageLateAttemptId = $this->createMessage(
            sourceId: $alphaSourceId,
            idempotencyKey: 'alpha-early-message-late-attempt',
            eventType: 'invoice.created',
            signatureStatus: 'valid',
            receivedAt: Carbon::parse('2026-05-09 09:45:00'),
        );

        $includedByAttemptTimeAttemptId = $this->createAttempt(
            messageId: $alphaEarlyMessageLateAttemptId,
            kind: 'replay',
            handler: 'AlphaStartedInsideWindowHandler',
            status: 'skipped',
            startedAt: Carbon::parse('2026-05-09 10:40:00'),
        );

        $this->createAttempt(
            messageId: $betaIncludedMessageId,
            kind: 'replay',
            handler: 'BetaSkippedHandler',
            status: 'skipped',
            startedAt: Carbon::parse('2026-05-09 10:20:00'),
        );

        $repository = $this->appInstance()->make(SourceRepository::class);

        $counters = $repository->counters('alpha', new MetricsRange(
            from: Carbon::parse('2026-05-09 10:00:00'),
            to: Carbon::parse('2026-05-09 11:00:00'),
        ));

        $this->assertSame(2, $counters->messages);
        $this->assertSame(3, $counters->attempts);
        $this->assertNotSame($lateAttemptOnIncludedMessageId, $includedByAttemptTimeAttemptId);
        $this->assertObjectNotHasProperty('messageIds', $counters);
        $this->assertObjectNotHasProperty('attemptIds', $counters);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createSource(string $slug, string $name, bool $isActive, array $config): string
    {
        $sourceId = (string) Str::ulid();
        $timestamp = Carbon::parse('2026-05-09 00:00:00');

        DB::table('hookbox_sources')->insert([
            'id' => $sourceId,
            'slug' => $slug,
            'name' => $name,
            'verifier' => 'Tests\\FakeVerifier',
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'is_active' => $isActive,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $sourceId;
    }

    private function createMessage(
        string $sourceId,
        string $idempotencyKey,
        string $eventType,
        string $signatureStatus,
        Carbon $receivedAt,
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
            'client_ip' => null,
            'redacted_at' => null,
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
    ): string {
        $attemptId = (string) Str::ulid();

        DB::table('hookbox_attempts')->insert([
            'id' => $attemptId,
            'message_id' => $messageId,
            'kind' => $kind,
            'handler' => $handler,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_ms' => null,
            'error_class' => null,
            'error_message' => null,
            'error_trace' => null,
            'triggered_by' => null,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        return $attemptId;
    }
}
