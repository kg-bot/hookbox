<?php

declare(strict_types=1);

namespace Hookbox\Jobs;

use Hookbox\Enums\AttemptKind;
use Hookbox\Enums\AttemptStatus;
use Hookbox\Events\WebhookProcessed;
use Hookbox\Events\WebhookProcessingFailed;
use Hookbox\Models\WebhookMessage;
use Hookbox\Models\WebhookSource;
use Hookbox\Support\EventViewMapper;
use Hookbox\WebhookActionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessWebhookMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $messageId) {}

    public function handle(WebhookActionRunner $runner, EventViewMapper $eventViewMapper): void
    {
        $message = WebhookMessage::query()->findOrFail($this->messageId);
        $source = WebhookSource::query()->findOrFail($message->source_id);
        $result = $runner->run($message, $source, AttemptKind::INITIAL);
        $event = $result->attempt->status === AttemptStatus::FAILED->value
            ? new WebhookProcessingFailed(
                $eventViewMapper->message($message, $source),
                $eventViewMapper->attempt($result->attempt),
            )
            : new WebhookProcessed(
                $eventViewMapper->message($message, $source),
                $eventViewMapper->attempt($result->attempt),
            );

        event($event);
    }
}
