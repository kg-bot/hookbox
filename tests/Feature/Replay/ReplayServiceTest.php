<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Replay;

use Hookbox\Contracts\Verifier;
use Hookbox\Contracts\WebhookAction;
use Hookbox\Enums\VerificationStatus;
use Hookbox\Events\WebhookReplayed;
use Hookbox\Models\WebhookAttempt;
use Hookbox\Models\WebhookMessage;
use Hookbox\ReplayOptions;
use Hookbox\ReplayService;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\VerificationResult;
use Hookbox\WebhookActionContext;
use Hookbox\WebhookActionRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ReplayServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        ReplaySideEffectState::reset();
        ReplayVerifierState::reset();
    }

    public function test_default_replay_is_dry_run_and_does_not_perform_live_side_effects(): void
    {
        $message = $this->createMessage();

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay($message, new ReplayOptions);

        $this->assertInstanceOf(WebhookAttempt::class, $attempt);
        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame('dry_run', $attempt->kind);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame(DryRunAwareReplayAction::class, $attempt->handler);
        $this->assertSame([], ReplaySideEffectState::$liveEffects);
        $this->assertSame([
            [
                'attempt_id' => (string) $attempt->getKey(),
                'provider' => 'testing',
                'event' => 'invoice.created',
                'is_replay' => true,
                'is_dry_run' => true,
                'triggered_by' => null,
            ],
        ], ReplaySideEffectState::$handledContexts);
    }

    public function test_live_replay_creates_a_new_attempt_row_and_allows_side_effects(): void
    {
        Event::fake([WebhookReplayed::class]);

        $message = $this->createMessage();

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message->getKey(),
            new ReplayOptions(dryRun: false, triggeredBy: 'task-6'),
        );

        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame('replay', $attempt->kind);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('task-6', $attempt->triggered_by);
        $this->assertSame([(string) $attempt->getKey()], ReplaySideEffectState::$liveEffects);
        $this->assertSame([
            [
                'attempt_id' => (string) $attempt->getKey(),
                'provider' => 'testing',
                'event' => 'invoice.created',
                'is_replay' => true,
                'is_dry_run' => false,
                'triggered_by' => 'task-6',
            ],
        ], ReplaySideEffectState::$handledContexts);

        Event::assertDispatched(WebhookReplayed::class, function (WebhookReplayed $event) use ($attempt, $message): bool {
            $savedAttempt = DB::table('hookbox_attempts')->where('id', $attempt->id)->first();

            $this->assertNotNull($savedAttempt);
            $this->assertSame((string) $message->getKey(), $event->message->id);
            $this->assertSame('testing', $event->message->sourceSlug);
            $this->assertSame((string) $attempt->id, $event->attempt->id);
            $this->assertSame((string) $attempt->message_id, $event->attempt->messageId);
            $this->assertSame('replay', $event->attempt->kind);
            $this->assertSame(DryRunAwareReplayAction::class, $event->attempt->handler);
            $this->assertSame('succeeded', $event->attempt->status);
            $this->assertSame('task-6', $event->attempt->triggeredBy);
            $this->assertNotNull($event->attempt->finishedAt);
            $this->assertSame((string) $savedAttempt->status, $event->attempt->status);
            $this->assertSame((string) $savedAttempt->handler, $event->attempt->handler);

            return true;
        });
    }

    public function test_actions_filter_restricts_which_actions_run_after_registry_matching(): void
    {
        $message = $this->createMessage();

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(FirstReplayAction::class)
            ->through(SecondReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, actionsFilter: [SecondReplayAction::class]),
        );

        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame(SecondReplayAction::class, $attempt->handler);
        $this->assertSame([], ReplaySideEffectState::$firstActionEffects);
        $this->assertSame([(string) $attempt->getKey()], ReplaySideEffectState::$secondActionEffects);
    }

    public function test_replay_is_skipped_when_no_actions_match(): void
    {
        Event::fake([WebhookReplayed::class]);

        $message = $this->createMessage();

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false),
        );

        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame('replay', $attempt->kind);
        $this->assertSame('skipped', $attempt->status);
        $this->assertSame('unmatched', $attempt->handler);
        $this->assertSame([], ReplaySideEffectState::$liveEffects);
        $this->assertSame([], ReplaySideEffectState::$handledContexts);

        Event::assertDispatched(WebhookReplayed::class, function (WebhookReplayed $event) use ($attempt): bool {
            return $event->attempt->id === (string) $attempt->getKey()
                && $event->attempt->status === 'skipped'
                && $event->attempt->handler === 'unmatched';
        });
    }

    public function test_force_reverify_rechecks_the_stored_body_and_records_failure_when_invalid(): void
    {
        $message = $this->createMessage(
            body: '{"id":"evt_bad_signature","type":"invoice.failed","signature":"bad"}',
            sourceConfig: [
                'expected_signature' => 'good',
            ],
        );

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.failed')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, forceReverify: true),
        );

        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('reverify', $attempt->handler);
        $this->assertSame('Signature mismatch.', $attempt->error_message);
        $this->assertSame([$message->body], ReplayVerifierState::$verifiedBodies);
        $this->assertSame([], ReplaySideEffectState::$liveEffects);
        $this->assertSame([], ReplaySideEffectState::$handledContexts);
    }

    public function test_force_reverify_restores_receipt_headers_into_the_reconstructed_request(): void
    {
        $message = $this->createMessage(
            body: '{"id":"evt_header_redacted","type":"invoice.created","signature":"bad"}',
            receiptBody: '{"id":"evt_header_receipt","type":"invoice.created","signature":"bad"}',
            receiptHeaders: [
                'X-Test' => ['1'],
                'X-Signature' => ['good'],
            ],
            sourceConfig: [
                'expected_signature' => 'good',
                'signature_header' => 'X-Signature',
            ],
        );

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, forceReverify: true),
        );

        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame(['good'], ReplayVerifierState::$verifiedSignatureHeaders);
        $this->assertSame([(string) $attempt->getKey()], ReplaySideEffectState::$liveEffects);
        $this->assertCount(1, ReplaySideEffectState::$handledContexts);
    }

    public function test_force_reverify_uses_the_stored_receipt_body_and_url_instead_of_the_message_body(): void
    {
        $message = $this->createMessage(
            body: '{"id":"evt_redacted","type":"invoice.created","signature":"bad"}',
            receiptBody: '{"id":"evt_receipt","type":"invoice.created","signature":"good"}',
            receiptUrl: 'http://localhost/webhooks/testing?receipt=expected',
            sourceConfig: [
                'expected_signature' => 'good',
            ],
        );

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, forceReverify: true),
        );

        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame(['{"id":"evt_receipt","type":"invoice.created","signature":"good"}'], ReplayVerifierState::$verifiedBodies);
        $this->assertSame(['http://localhost/webhooks/testing?receipt=expected'], ReplayVerifierState::$verifiedUrls);
        $this->assertSame([(string) $attempt->getKey()], ReplaySideEffectState::$liveEffects);
        $this->assertCount(1, ReplaySideEffectState::$handledContexts);
    }

    public function test_force_reverify_fails_when_the_receipt_is_missing_and_does_not_run_actions(): void
    {
        $message = $this->createMessage(storeReceipt: false);

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, forceReverify: true),
        );

        $this->assertSame('failed', $attempt->status);
        $this->assertSame('Replay receipt is missing; cannot safely re-verify replay.', $attempt->error_message);
        $this->assertSame([], ReplayVerifierState::$verifiedBodies);
        $this->assertSame([], ReplayVerifierState::$verifiedUrls);
        $this->assertSame([], ReplaySideEffectState::$liveEffects);
        $this->assertSame([], ReplaySideEffectState::$handledContexts);
    }

    public function test_force_reverify_fails_closed_when_reverification_is_skipped_and_does_not_run_actions(): void
    {
        $message = $this->createMessage(
            receiptUrl: 'http://localhost/webhooks/testing?audit=skip',
        );

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, forceReverify: true),
        );

        $this->assertSame('failed', $attempt->status);
        $this->assertSame('Replay re-verification was skipped; failing closed for audit safety.', $attempt->error_message);
        $this->assertSame(['{"id":"evt_test","type":"invoice.created","signature":"good"}'], ReplayVerifierState::$verifiedBodies);
        $this->assertSame(['http://localhost/webhooks/testing?audit=skip'], ReplayVerifierState::$verifiedUrls);
        $this->assertSame([], ReplaySideEffectState::$liveEffects);
        $this->assertSame([], ReplaySideEffectState::$handledContexts);
    }

    public function test_replay_attributes_failures_to_the_active_action(): void
    {
        Event::fake([WebhookReplayed::class]);

        $message = $this->createMessage();

        $this->registry()
            ->handle('testing')
            ->when(eventType: 'invoice.created')
            ->through(DryRunAwareReplayAction::class)
            ->through(FailingReplayAction::class);

        $attempt = $this->appInstance()->make(ReplayService::class)->replay(
            $message,
            new ReplayOptions(dryRun: false, triggeredBy: 'task-7'),
        );

        $this->assertSame(1, WebhookAttempt::query()->count());
        $this->assertSame('failed', $attempt->status);
        $this->assertSame(FailingReplayAction::class, $attempt->handler);
        $this->assertSame(\RuntimeException::class, $attempt->error_class);
        $this->assertSame('Replay boom.', $attempt->error_message);
        $this->assertSame([(string) $attempt->getKey()], ReplaySideEffectState::$liveEffects);
        $this->assertCount(1, ReplaySideEffectState::$handledContexts);

        Event::assertDispatched(WebhookReplayed::class, function (WebhookReplayed $event) use ($attempt): bool {
            return $event->attempt->id === (string) $attempt->getKey()
                && $event->attempt->status === 'failed'
                && $event->attempt->handler === FailingReplayAction::class;
        });
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     * @param  array<string, array<int, scalar>|scalar>  $receiptHeaders
     */
    private function createMessage(
        string $body = '{"id":"evt_test","type":"invoice.created","signature":"good"}',
        array $sourceConfig = [],
        bool $storeReceipt = true,
        ?string $receiptBody = null,
        string $receiptUrl = 'http://localhost/webhooks/testing',
        array $receiptHeaders = ['X-Test' => ['1']],
    ): WebhookMessage {
        $sourceId = (string) Str::ulid();
        $messageId = (string) Str::ulid();
        $now = Carbon::now();

        DB::table('hookbox_sources')->insert([
            'id' => $sourceId,
            'slug' => 'testing',
            'name' => 'Testing Source',
            'verifier' => ReplayVerifier::class,
            'config' => json_encode($sourceConfig, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('hookbox_messages')->insert([
            'id' => $messageId,
            'source_id' => $sourceId,
            'idempotency_key' => 'evt_test',
            'event_type' => 'invoice.created',
            'headers' => json_encode(['X-Test' => '1'], JSON_THROW_ON_ERROR),
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'signature_status' => 'valid',
            'received_at' => $now,
            'client_ip' => '127.0.0.1',
            'redacted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($storeReceipt) {
            DB::table('hookbox_message_receipts')->insert([
                'message_id' => $messageId,
                'method' => 'POST',
                'url' => $receiptUrl,
                'headers' => json_encode($receiptHeaders, JSON_THROW_ON_ERROR),
                'body' => $receiptBody ?? $body,
                'client_ip' => '127.0.0.1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @var WebhookMessage $message */
        $message = WebhookMessage::query()->findOrFail($messageId);

        return $message;
    }

    private function registry(): WebhookActionRegistry
    {
        return $this->appInstance()->make(WebhookActionRegistry::class);
    }
}

final class ReplaySideEffectState
{
    /**
     * @var array<int, string>
     */
    public static array $liveEffects = [];

    /**
     * @var array<int, array{attempt_id: string, provider: string, event: ?string, is_replay: bool, is_dry_run: bool, triggered_by: ?string}>
     */
    public static array $handledContexts = [];

    /**
     * @var array<int, string>
     */
    public static array $firstActionEffects = [];

    /**
     * @var array<int, string>
     */
    public static array $secondActionEffects = [];

    public static function reset(): void
    {
        self::$liveEffects = [];
        self::$handledContexts = [];
        self::$firstActionEffects = [];
        self::$secondActionEffects = [];
    }
}

final class ReplayVerifierState
{
    /**
     * @var array<int, string>
     */
    public static array $verifiedBodies = [];

    /**
     * @var array<int, string>
     */
    public static array $verifiedUrls = [];

    /**
     * @var array<int, string|null>
     */
    public static array $verifiedSignatureHeaders = [];

    public static function reset(): void
    {
        self::$verifiedBodies = [];
        self::$verifiedUrls = [];
        self::$verifiedSignatureHeaders = [];
    }
}

final class ReplayVerifier implements Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        ReplayVerifierState::$verifiedBodies[] = $request->getContent();
        ReplayVerifierState::$verifiedUrls[] = $request->fullUrl();
        $signatureHeader = $source->config['signature_header'] ?? null;
        ReplayVerifierState::$verifiedSignatureHeaders[] = is_string($signatureHeader)
            ? $request->headers->get($signatureHeader)
            : null;

        if ($request->query('audit') === 'skip') {
            return new VerificationResult(VerificationStatus::SKIPPED, 'Verifier supplied skip reason.');
        }

        $payload = json_decode($request->getContent(), true);
        $signature = is_string($signatureHeader)
            ? $request->headers->get($signatureHeader)
            : (is_array($payload) ? ($payload['signature'] ?? null) : null);
        $expected = $source->config['expected_signature'] ?? 'good';

        if ($signature !== $expected) {
            return new VerificationResult(VerificationStatus::INVALID, 'Signature mismatch.');
        }

        return new VerificationResult(VerificationStatus::VALID);
    }

    public function idempotencyKey(Request $request, SourceDefinition $source): ?string
    {
        $payload = json_decode($request->getContent(), true);
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function eventType(Request $request, SourceDefinition $source): ?string
    {
        $payload = json_decode($request->getContent(), true);
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}

final class DryRunAwareReplayAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        $attempt = $context->attempt() ?? throw new \RuntimeException('Attempt is required.');

        ReplaySideEffectState::$handledContexts[] = [
            'attempt_id' => (string) $attempt->getKey(),
            'provider' => $context->provider(),
            'event' => $context->event(),
            'is_replay' => $context->isReplay(),
            'is_dry_run' => $context->isDryRun(),
            'triggered_by' => $context->triggeredBy(),
        ];

        if (! $context->isDryRun()) {
            ReplaySideEffectState::$liveEffects[] = (string) $attempt->getKey();
        }

        return $next($context);
    }
}

final class FirstReplayAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        $attempt = $context->attempt() ?? throw new \RuntimeException('Attempt is required.');
        ReplaySideEffectState::$firstActionEffects[] = (string) $attempt->getKey();

        return $next($context);
    }
}

final class SecondReplayAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        $attempt = $context->attempt() ?? throw new \RuntimeException('Attempt is required.');
        ReplaySideEffectState::$secondActionEffects[] = (string) $attempt->getKey();

        return $next($context);
    }
}

final class FailingReplayAction implements WebhookAction
{
    public function handle(WebhookActionContext $context, \Closure $next): mixed
    {
        throw new \RuntimeException('Replay boom.');
    }
}
