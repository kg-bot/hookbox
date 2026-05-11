<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Http;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\Events\WebhookReceived;
use Hookbox\Jobs\ProcessWebhookMessage;
use Hookbox\SourceDefinition;
use Hookbox\Tests\TestCase;
use Hookbox\VerificationResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class WebhookControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
    }

    public function test_it_returns_404_for_unknown_source(): void
    {
        $this->postJson('/webhooks/unknown')->assertNotFound();
    }

    public function test_it_returns_401_and_persists_invalid_signature_when_configured(): void
    {
        Queue::fake();

        config()->set('hookbox.store_invalid_signatures', true);
        $this->configureSource([
            'verification_status' => 'invalid',
            'store_invalid_signatures' => true,
        ]);

        $body = '{"id":"evt_invalid","type":"invoice.failed","data":{"object":{"customer_email":"person@example.com"}}}';

        $response = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: $body);

        $response->assertStatus(401);

        $this->assertSame(1, DB::table('hookbox_messages')->count());

        $message = DB::table('hookbox_messages')->first();

        $this->assertNotNull($message);
        $this->assertSame('invalid', $message->signature_status);
        $this->assertSame('evt_invalid', $message->idempotency_key);
        $this->assertSame('invoice.failed', $message->event_type);

        Queue::assertNothingPushed();
    }

    public function test_it_returns_401_and_does_not_store_or_dispatch_invalid_signatures_when_disabled_even_if_the_key_exists(): void
    {
        Queue::fake();

        $sourceId = (string) Str::ulid();
        $receivedAt = Carbon::now();

        DB::table('hookbox_sources')->insert([
            'id' => $sourceId,
            'slug' => 'testing',
            'name' => 'Testing Source',
            'verifier' => FakeWebhookVerifier::class,
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        DB::table('hookbox_messages')->insert([
            'id' => (string) Str::ulid(),
            'source_id' => $sourceId,
            'idempotency_key' => 'evt_invalid_duplicate',
            'event_type' => 'invoice.created',
            'headers' => json_encode([], JSON_THROW_ON_ERROR),
            'body' => '{"seeded":true}',
            'body_hash' => hash('sha256', '{"seeded":true}'),
            'signature_status' => 'valid',
            'received_at' => $receivedAt,
            'client_ip' => null,
            'redacted_at' => null,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        config()->set('hookbox.store_invalid_signatures', false);
        $this->configureSource([
            'verification_status' => 'invalid',
            'store_invalid_signatures' => false,
        ]);

        $body = '{"id":"evt_invalid_duplicate","type":"invoice.failed"}';

        $response = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);

        $response->assertStatus(401);
        $response->assertHeaderMissing('X-Hookbox-Idempotent');

        $this->assertSame(1, DB::table('hookbox_messages')->count());
        Queue::assertNothingPushed();
    }

    public function test_it_returns_200_with_idempotent_header_and_skips_duplicate_insert_and_dispatch(): void
    {
        Queue::fake();

        $this->configureSource();

        $body = '{"id":"evt_duplicate","type":"invoice.created","data":{"object":{"customer_email":"person@example.com"}}}';

        $firstResponse = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);

        $firstResponse->assertOk();

        $secondResponse = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);

        $secondResponse->assertOk();
        $secondResponse->assertHeader('X-Hookbox-Idempotent', 'true');

        $this->assertSame(1, DB::table('hookbox_messages')->count());
        Queue::assertPushed(ProcessWebhookMessage::class, 1);
    }

    public function test_it_hashes_the_raw_body_before_redaction_and_stores_the_redacted_body(): void
    {
        Queue::fake();

        $this->configureSource([
            'redact' => ['$.data.object.customer_email'],
        ]);

        $body = '{"id":"evt_redacted","type":"invoice.created","data":{"object":{"customer_email":"person@example.com","note":"keep"}}}';

        $response = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);

        $response->assertOk();

        $message = DB::table('hookbox_messages')->first();

        $this->assertNotNull($message);
        $this->assertSame(hash('sha256', $body), $message->body_hash);
        $this->assertSame('evt_redacted', $message->idempotency_key);
        $this->assertStringNotContainsString('person@example.com', (string) $message->body);
        $this->assertStringContainsString('"customer_email":"[REDACTED]"', (string) $message->body);
        $this->assertStringContainsString('"note":"keep"', (string) $message->body);
        $this->assertNotNull($message->redacted_at);

        Queue::assertPushed(ProcessWebhookMessage::class, 1);
    }

    public function test_it_stores_the_original_request_receipt_alongside_the_redacted_message(): void
    {
        Event::fake([WebhookReceived::class]);
        Queue::fake();

        $this->configureSource([
            'redact' => ['$.data.object.customer_email'],
        ]);

        $body = '{"id":"evt_receipt","type":"invoice.created","data":{"object":{"customer_email":"person@example.com","note":"keep"}}}';

        $response = $this->call('POST', 'http://localhost/webhooks/testing?attempt=1', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => 'sig_123',
            'HTTP_X_TRACE_ID' => 'trace_123',
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: $body);

        $response->assertOk();

        $message = DB::table('hookbox_messages')->first();
        $receipt = DB::table('hookbox_message_receipts')->first();

        $this->assertNotNull($message);
        $this->assertNotNull($receipt);
        $this->assertSame((string) $message->id, (string) $receipt->message_id);
        $this->assertStringContainsString('"customer_email":"[REDACTED]"', (string) $message->body);
        $this->assertStringNotContainsString('person@example.com', (string) $message->body);
        $this->assertSame($body, $receipt->body);
        $this->assertSame('POST', $receipt->method);
        $this->assertSame('http://localhost/webhooks/testing?attempt=1', $receipt->url);
        $this->assertSame('127.0.0.1', $receipt->client_ip);

        $headers = json_decode((string) $receipt->headers, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['application/json'], $headers['content-type'] ?? null);
        $this->assertSame(['sig_123'], $headers['x-signature'] ?? null);
        $this->assertSame(['trace_123'], $headers['x-trace-id'] ?? null);

        Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) use ($message, $receipt): bool {
            $this->assertSame(1, DB::table('hookbox_messages')->count());
            $this->assertSame(1, DB::table('hookbox_message_receipts')->count());
            $this->assertSame((string) $message->id, $event->message->id);
            $this->assertSame('testing', $event->message->sourceSlug);
            $this->assertSame('Testing Source', $event->message->sourceName);
            $this->assertSame('evt_receipt', $event->message->idempotencyKey);
            $this->assertSame('invoice.created', $event->message->eventType);
            $this->assertSame('valid', $event->message->signatureStatus);
            $this->assertSame('127.0.0.1', $event->message->clientIp);
            $this->assertSame((string) $receipt->message_id, $event->message->id);

            return true;
        });
    }

    public function test_it_stores_and_dispatches_skipped_verification_results(): void
    {
        Queue::fake();

        $this->configureSource([
            'verification_status' => 'skipped',
        ]);

        $body = '{"id":"evt_skipped","type":"invoice.created"}';

        $response = $this->call('POST', '/webhooks/testing', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);

        $response->assertOk();

        $message = DB::table('hookbox_messages')->first();

        $this->assertNotNull($message);
        $this->assertSame('skipped', $message->signature_status);
        $this->assertSame('evt_skipped', $message->idempotency_key);

        Queue::assertPushed(ProcessWebhookMessage::class, 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureSource(array $overrides = []): void
    {
        config()->set('hookbox.sources', [
            'testing' => array_replace([
                'name' => 'Testing Source',
                'verifier' => FakeWebhookVerifier::class,
                'queue' => [
                    'connection' => 'sync',
                    'name' => 'source-webhooks',
                ],
                'store_invalid_signatures' => false,
            ], $overrides),
        ]);
    }
}

final class FakeWebhookVerifier implements Verifier
{
    public function verify(Request $request, SourceDefinition $source): VerificationResult
    {
        $status = VerificationStatus::from((string) ($source->config['verification_status'] ?? VerificationStatus::VALID->value));

        return new VerificationResult(
            $status,
            $status === VerificationStatus::INVALID ? 'Invalid signature.' : null,
        );
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
