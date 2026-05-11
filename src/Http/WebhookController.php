<?php

declare(strict_types=1);

namespace Hookbox\Http;

use Hookbox\Contracts\Verifier;
use Hookbox\Enums\VerificationStatus;
use Hookbox\Events\WebhookReceived;
use Hookbox\Jobs\ProcessWebhookMessage;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookMessageReceipt;
use Hookbox\Models\WebhookSource;
use Hookbox\SourceDefinition;
use Hookbox\SourceRegistry;
use Hookbox\Support\EventViewMapper;
use Hookbox\Support\JsonRedactor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WebhookController
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly Container $container,
        private readonly EventViewMapper $eventViewMapper,
        private readonly JsonRedactor $redactor,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        $definition = $this->sources->find($source);

        if ($definition === null) {
            abort(404);
        }

        $rawBody = $request->getContent();
        $requestMethod = $request->method();
        $requestUrl = $request->fullUrl();
        $requestHeaders = $request->headers->all();
        $requestClientIp = $request->ip();
        $verifier = $this->verifier($definition);
        $verification = $verifier->verify($request, $definition);

        $sourceModel = WebhookSource::query()->where('slug', $definition->slug)->first();

        if ($sourceModel === null) {
            $sourceModel = new WebhookSource;
            $sourceModel->slug = $definition->slug;
            $sourceModel->name = $definition->name;
            $sourceModel->verifier = $definition->verifier;
            $sourceModel->config = $definition->config;
            $sourceModel->is_active = true;
            $sourceModel->save();
        }

        $shouldStoreInvalid = (bool) ($definition->config['store_invalid_signatures'] ?? config('hookbox.store_invalid_signatures', true));

        if ($verification->status === VerificationStatus::INVALID && ! $shouldStoreInvalid) {
            return response()->json([], 401);
        }

        $idempotencyKey = $verifier->idempotencyKey($request, $definition);

        if ($idempotencyKey !== null && WebhookMessage::query()
            ->where('source_id', $sourceModel->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->exists()) {
            return response()->json([], 200, ['X-Hookbox-Idempotent' => 'true']);
        }

        $storedBody = $this->redactor->redact(
            $rawBody,
            $this->redactionPaths($definition),
        );

        $message = new WebhookMessage;
        $message->source_id = $sourceModel->getKey();
        $message->idempotency_key = $idempotencyKey;
        $message->event_type = $verifier->eventType($request, $definition);
        $message->headers = $requestHeaders;
        $message->body = $storedBody;
        $message->body_hash = hash('sha256', $rawBody);
        $message->signature_status = $verification->status->value;
        $message->received_at = Carbon::now();
        $message->client_ip = $requestClientIp;
        $message->redacted_at = $storedBody === $rawBody ? null : Carbon::now();

        $receipt = new WebhookMessageReceipt;
        $receipt->method = $requestMethod;
        $receipt->url = $requestUrl;
        $receipt->headers = $requestHeaders;
        $receipt->body = $rawBody;
        $receipt->client_ip = $requestClientIp;

        DB::transaction(static function () use ($message, $receipt): void {
            $message->save();

            $receipt->message_id = (string) $message->getKey();
            $receipt->save();
        });

        event(new WebhookReceived($this->eventViewMapper->message($message, $sourceModel)));

        if ($verification->status === VerificationStatus::INVALID) {
            return response()->json([], 401);
        }

        $job = new ProcessWebhookMessage((string) $message->getKey());
        $queue = $this->queueConfig($definition);

        if (is_string($queue['connection']) && $queue['connection'] !== '') {
            $job->onConnection($queue['connection']);
        }

        if (is_string($queue['name']) && $queue['name'] !== '') {
            $job->onQueue($queue['name']);
        }

        dispatch($job);

        return response()->json([], 200);
    }

    private function verifier(SourceDefinition $definition): Verifier
    {
        $verifier = $this->container->make($definition->verifier);

        if (! $verifier instanceof Verifier) {
            throw new \InvalidArgumentException(sprintf(
                'Verifier [%s] must implement [%s].',
                $definition->verifier,
                Verifier::class,
            ));
        }

        return $verifier;
    }

    /**
     * @return array{connection:mixed,name:mixed}
     */
    private function queueConfig(SourceDefinition $definition): array
    {
        $sourceQueue = $definition->config['queue'] ?? [];

        return [
            'connection' => $sourceQueue['connection'] ?? config('hookbox.queue.connection'),
            'name' => $sourceQueue['name'] ?? config('hookbox.queue.name'),
        ];
    }

    /**
     * @return list<string>
     */
    private function redactionPaths(SourceDefinition $definition): array
    {
        $paths = $definition->config['redact'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }
}
