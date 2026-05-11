<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Actions;

use Hookbox\Contracts\WebhookAction;
use Hookbox\Events\WebhookProcessed;
use Hookbox\Events\WebhookProcessingFailed;
use Hookbox\Jobs\ProcessWebhookMessage;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\Tests\TestCase;
use Hookbox\WebhookActionContext;
use Hookbox\WebhookActionRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ProcessWebhookMessageTest extends TestCase
{
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        ProcessedActionState::reset();
    }

    public function test_it_creates_one_succeeded_attempt_and_dispatches_processed_event_when_actions_match(): void
    {
        Event::fake([WebhookProcessed::class, WebhookProcessingFailed::class]);

        $message = $this->createMessage();

        $this->appInstance()->make(WebhookActionRegistry::class)
            ->handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(RecordAction::class)
            ->through(SecondRecordAction::class);

        $job = $this->appInstance()->make(ProcessWebhookMessage::class, ['messageId' => (string) $message->getKey()]);
        $this->appInstance()->call([$job, 'handle']);

        $attempt = WebhookAttempt::query()->sole();

        $this->assertSame('initial', $attempt->kind);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame(SecondRecordAction::class, $attempt->handler);
        $this->assertSame([(string) $attempt->getKey()], ProcessedActionState::$recordedAttemptIds);
        $this->assertSame([
            ['provider' => 'stripe', 'event' => 'invoice.created', 'replay' => false, 'dry_run' => false],
            ['provider' => 'stripe', 'event' => 'invoice.created', 'replay' => false, 'dry_run' => false],
        ], ProcessedActionState::$contexts);

        Event::assertDispatched(WebhookProcessed::class, function (WebhookProcessed $event) use ($message, $attempt): bool {
            $this->assertSame((string) $message->getKey(), $event->message->id);
            $this->assertSame((string) $attempt->getKey(), $event->attempt->id);
            $this->assertSame('succeeded', $event->attempt->status);
            $this->assertSame(SecondRecordAction::class, $event->attempt->handler);

            return true;
        });

        Event::assertNotDispatched(WebhookProcessingFailed::class);
    }

    public function test_it_marks_attempt_as_skipped_when_no_actions_match(): void
    {
        Event::fake([WebhookProcessed::class, WebhookProcessingFailed::class]);

        $message = $this->createMessage();

        $job = $this->appInstance()->make(ProcessWebhookMessage::class, ['messageId' => (string) $message->getKey()]);
        $this->appInstance()->call([$job, 'handle']);

        $attempt = WebhookAttempt::query()->sole();

        $this->assertSame('initial', $attempt->kind);
        $this->assertSame('skipped', $attempt->status);
        $this->assertSame('unmatched', $attempt->handler);
        $this->assertSame([], ProcessedActionState::$recordedAttemptIds);

        Event::assertDispatched(WebhookProcessed::class, function (WebhookProcessed $event) use ($attempt): bool {
            return $event->attempt->id === (string) $attempt->getKey()
                && $event->attempt->status === 'skipped';
        });

        Event::assertNotDispatched(WebhookProcessingFailed::class);
    }

    public function test_it_allows_actions_to_short_circuit_the_pipeline(): void
    {
        Event::fake([WebhookProcessed::class, WebhookProcessingFailed::class]);

        $message = $this->createMessage();

        $this->appInstance()->make(WebhookActionRegistry::class)
            ->handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(ShortCircuitAction::class)
            ->through(SecondRecordAction::class);

        $job = $this->appInstance()->make(ProcessWebhookMessage::class, ['messageId' => (string) $message->getKey()]);
        $this->appInstance()->call([$job, 'handle']);

        $attempt = WebhookAttempt::query()->sole();

        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame(ShortCircuitAction::class, $attempt->handler);
        $this->assertSame(['short-circuit'], ProcessedActionState::$recordedAttemptIds);

        Event::assertDispatched(WebhookProcessed::class);
        Event::assertNotDispatched(WebhookProcessingFailed::class);
    }

    public function test_it_records_the_first_failure_and_dispatches_failed_event(): void
    {
        Event::fake([WebhookProcessed::class, WebhookProcessingFailed::class]);

        $message = $this->createMessage();

        $this->appInstance()->make(WebhookActionRegistry::class)
            ->handle('stripe')
            ->when(eventType: 'invoice.created')
            ->through(RecordAction::class)
            ->through(FailingAction::class)
            ->through(SecondRecordAction::class);

        $job = $this->appInstance()->make(ProcessWebhookMessage::class, ['messageId' => (string) $message->getKey()]);
        $this->appInstance()->call([$job, 'handle']);

        $attempt = WebhookAttempt::query()->sole();

        $this->assertSame('failed', $attempt->status);
        $this->assertSame(FailingAction::class, $attempt->handler);
        $this->assertSame(\RuntimeException::class, $attempt->error_class);
        $this->assertSame('Boom.', $attempt->error_message);
        $this->assertSame([(string) $attempt->getKey()], ProcessedActionState::$recordedAttemptIds);

        Event::assertDispatched(WebhookProcessingFailed::class, function (WebhookProcessingFailed $event) use ($attempt): bool {
            return $event->attempt->id === (string) $attempt->getKey()
                && $event->attempt->status === 'failed'
                && $event->attempt->handler === FailingAction::class;
        });

        Event::assertNotDispatched(WebhookProcessed::class);
    }

    private function createMessage(): WebhookMessage
    {
        $sourceId = (string) Str::ulid();
        $messageId = (string) Str::ulid();
        $now = Carbon::now();

        DB::table('hookbox_sources')->insert([
            'id' => $sourceId,
            'slug' => 'stripe',
            'name' => 'Stripe',
            'verifier' => RecordAction::class,
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('hookbox_messages')->insert([
            'id' => $messageId,
            'source_id' => $sourceId,
            'idempotency_key' => 'evt_test',
            'event_type' => 'invoice.created',
            'headers' => json_encode(['X-Test' => ['1']], JSON_THROW_ON_ERROR),
            'body' => '{"id":"evt_test","type":"invoice.created"}',
            'body_hash' => hash('sha256', '{"id":"evt_test","type":"invoice.created"}'),
            'signature_status' => 'valid',
            'received_at' => $now,
            'client_ip' => '127.0.0.1',
            'redacted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var WebhookMessage $message */
        $message = WebhookMessage::query()->findOrFail($messageId);

        return $message;
    }
}

final class ProcessedActionState
{
    /**
     * @var array<int, string>
     */
    public static array $recordedAttemptIds = [];

    /**
     * @var array<int, array{provider: string, event: ?string, replay: bool, dry_run: bool}>
     */
    public static array $contexts = [];

    public static function reset(): void
    {
        self::$recordedAttemptIds = [];
        self::$contexts = [];
    }
}

final class RecordAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        $attempt = $context->attempt();

        ProcessedActionState::$recordedAttemptIds[] = $attempt === null ? 'missing' : (string) $attempt->getKey();
        ProcessedActionState::$contexts[] = [
            'provider' => $context->provider(),
            'event' => $context->event(),
            'replay' => $context->isReplay(),
            'dry_run' => $context->isDryRun(),
        ];

        return $next($context);
    }
}

final class SecondRecordAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        ProcessedActionState::$contexts[] = [
            'provider' => $context->provider(),
            'event' => $context->event(),
            'replay' => $context->isReplay(),
            'dry_run' => $context->isDryRun(),
        ];

        return $next($context);
    }
}

final class ShortCircuitAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        ProcessedActionState::$recordedAttemptIds[] = 'short-circuit';

        return $context;
    }
}

final class FailingAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        throw new \RuntimeException('Boom.');
    }
}
