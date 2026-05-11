<?php

declare(strict_types=1);

namespace Hookbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @internal
 *
 * @property string $id
 * @property string $message_id
 * @property string $kind
 * @property string $handler
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $error_class
 * @property string|null $error_message
 * @property string|null $error_trace
 * @property string|null $triggered_by
 */
final class WebhookAttempt extends Model
{
    use HasUlids;

    protected $table = 'hookbox_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'int',
    ];
}
