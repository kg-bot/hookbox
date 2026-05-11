<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Console;

use Hookbox\Models\WebhookMessage;
use Hookbox\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

final class HookboxPruneCommandTest extends TestCase
{
    public function test_it_registers_and_prunes_messages_by_source_retention(): void
    {
        $this->seedPrunableMessages();

        $this->assertArrayHasKey('hookbox:prune', $this->appInstance()->make(Kernel::class)->all());

        $this->assertCommandSucceeded('hookbox:prune');

        $this->assertPruneOutcome();
    }

    public function test_it_supports_laravel_model_prune_without_self_referencing_delete_sql(): void
    {
        $this->seedPrunableMessages();

        $deleteSql = strtolower(DB::connection()->getQueryGrammar()->compileDelete((new WebhookMessage)->prunable()->getQuery()));

        $this->assertStringNotContainsString(' in (select ', $deleteSql);
        $this->assertStringNotContainsString(' from `hookbox_messages`) ', $deleteSql);

        $this->assertCommandSucceeded('model:prune', [
            '--model' => [WebhookMessage::class],
        ]);

        $this->assertPruneOutcome();
    }

    public function test_pgsql_prune_sql_guards_malformed_retention_values(): void
    {
        $originalDefaultConnection = $this->appInstance()['config']->get('database.default');

        $this->appInstance()['config']->set('database.default', 'pgsql');
        DB::purge('pgsql');

        try {
            $deleteSql = strtolower(DB::connection('pgsql')->getQueryGrammar()->compileDelete((new WebhookMessage)->prunable()->getQuery()));
        } finally {
            $this->appInstance()['config']->set('database.default', $originalDefaultConnection);
            DB::purge('pgsql');
        }

        $this->assertStringContainsString("case when coalesce(hookbox_sources.config->>'retention_days', '') ~ '^[1-9][0-9]*$'", $deleteSql);
        $this->assertStringContainsString("then (hookbox_sources.config->>'retention_days')::integer else 30 end", $deleteSql);
        $this->assertSame(1, substr_count($deleteSql, '::integer'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedPrunableMessages(): void
    {
        $this->runPackageMigrations();

        Carbon::setTestNow('2026-05-09 12:00:00');

        DB::table('hookbox_sources')->insert([
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr1',
                'slug' => 'stripe',
                'name' => 'Stripe',
                'verifier' => 'Hookbox\\Verifiers\\StripeVerifier',
                'config' => json_encode(['retention_days' => 30], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr2',
                'slug' => 'github',
                'name' => 'GitHub',
                'verifier' => 'Hookbox\\Verifiers\\GitHubVerifier',
                'config' => json_encode([], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr8',
                'slug' => 'slack',
                'name' => 'Slack',
                'verifier' => 'Hookbox\\Verifiers\\SlackVerifier',
                'config' => json_encode(['retention_days' => 'abc'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra0',
                'slug' => 'linear',
                'name' => 'Linear',
                'verifier' => 'Hookbox\\Verifiers\\LinearVerifier',
                'config' => json_encode(['retention_days' => '7'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra2',
                'slug' => 'asana',
                'name' => 'Asana',
                'verifier' => 'Hookbox\\Verifiers\\AsanaVerifier',
                'config' => json_encode(['retention_days' => '12abc'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra4',
                'slug' => 'trello',
                'name' => 'Trello',
                'verifier' => 'Hookbox\\Verifiers\\TrelloVerifier',
                'config' => json_encode(['retention_days' => true], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => '2026-05-09 12:00:00',
                'updated_at' => '2026-05-09 12:00:00',
            ],
        ]);

        DB::table('hookbox_messages')->insert([
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr3',
                'source_id' => '01jtm3c8g62byrrq7w21z4fgr1',
                'idempotency_key' => 'expired',
                'event_type' => 'invoice.paid',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('a', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-08 11:59:59',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-08 11:59:59',
                'updated_at' => '2026-04-08 11:59:59',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr4',
                'source_id' => '01jtm3c8g62byrrq7w21z4fgr1',
                'idempotency_key' => 'fresh',
                'event_type' => 'invoice.created',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('b', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-09 12:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-09 12:00:00',
                'updated_at' => '2026-04-09 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr5',
                'source_id' => '01jtm3c8g62byrrq7w21z4fgr2',
                'idempotency_key' => 'no-retention-old',
                'event_type' => 'push',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('c', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-01-01 00:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr6',
                'source_id' => null,
                'idempotency_key' => 'no-source-old',
                'event_type' => 'orphaned',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('d', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-01-01 00:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fgr9',
                'source_id' => '01jtm3c8g62byrrq7w21z4fgr8',
                'idempotency_key' => 'malformed-retention-defaults',
                'event_type' => 'message.posted',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('e', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-29 12:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-29 12:00:00',
                'updated_at' => '2026-04-29 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra1',
                'source_id' => '01jtm3c8g62byrrq7w21z4fra0',
                'idempotency_key' => 'one-digit-retention',
                'event_type' => 'issue.created',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('f', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-29 12:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-29 12:00:00',
                'updated_at' => '2026-04-29 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra3',
                'source_id' => '01jtm3c8g62byrrq7w21z4fra2',
                'idempotency_key' => 'digit-prefixed-malformed-retention',
                'event_type' => 'task.created',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('g', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-19 12:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-19 12:00:00',
                'updated_at' => '2026-04-19 12:00:00',
            ],
            [
                'id' => '01jtm3c8g62byrrq7w21z4fra5',
                'source_id' => '01jtm3c8g62byrrq7w21z4fra4',
                'idempotency_key' => 'boolean-retention-defaults',
                'event_type' => 'card.created',
                'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{}',
                'body_hash' => str_repeat('h', 64),
                'signature_status' => 'valid',
                'received_at' => '2026-04-29 12:00:00',
                'client_ip' => '127.0.0.1',
                'redacted_at' => null,
                'created_at' => '2026-04-29 12:00:00',
                'updated_at' => '2026-04-29 12:00:00',
            ],
        ]);

        DB::table('hookbox_attempts')->insert([
            'id' => '01jtm3c8g62byrrq7w21z4fgr7',
            'message_id' => '01jtm3c8g62byrrq7w21z4fgr3',
            'kind' => 'initial',
            'handler' => 'App\\Handlers\\WebhookHandler',
            'status' => 'failed',
            'started_at' => '2026-04-08 12:00:00',
            'finished_at' => '2026-04-08 12:00:01',
            'duration_ms' => 1000,
            'error_class' => 'RuntimeException',
            'error_message' => 'Failed.',
            'error_trace' => 'trace',
            'triggered_by' => 'system',
            'created_at' => '2026-04-08 12:00:00',
            'updated_at' => '2026-04-08 12:00:01',
        ]);

        DB::table('hookbox_message_receipts')->insert([
            'message_id' => '01jtm3c8g62byrrq7w21z4fgr3',
            'method' => 'POST',
            'url' => 'https://example.test/webhook',
            'headers' => json_encode(['content-type' => ['application/json']], JSON_THROW_ON_ERROR),
            'body' => '{"ok":true}',
            'client_ip' => '127.0.0.1',
            'created_at' => '2026-04-08 12:00:00',
            'updated_at' => '2026-04-08 12:00:00',
        ]);
    }

    private function assertPruneOutcome(): void
    {
        $this->assertFalse(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fgr3')->exists());
        $this->assertTrue(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fgr4')->exists());
        $this->assertFalse(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fgr5')->exists());
        $this->assertTrue(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fgr6')->exists());
        $this->assertTrue(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fgr9')->exists());
        $this->assertFalse(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fra1')->exists());
        $this->assertTrue(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fra3')->exists());
        $this->assertTrue(WebhookMessage::query()->whereKey('01jtm3c8g62byrrq7w21z4fra5')->exists());
        $this->assertDatabaseMissing('hookbox_attempts', ['message_id' => '01jtm3c8g62byrrq7w21z4fgr3']);
        $this->assertDatabaseMissing('hookbox_message_receipts', ['message_id' => '01jtm3c8g62byrrq7w21z4fgr3']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertCommandSucceeded(string $command, array $parameters = []): void
    {
        $result = $this->artisan($command, $parameters);

        if ($result instanceof PendingCommand) {
            $result->assertExitCode(0);

            return;
        }

        $this->assertSame(0, $result);
    }
}
