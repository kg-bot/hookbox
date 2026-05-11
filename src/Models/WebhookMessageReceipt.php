<?php

declare(strict_types=1);

namespace Hookbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * @property string $message_id
 * @property string $method
 * @property string $url
 * @property array<string, mixed> $headers
 * @property string $body
 * @property string|null $client_ip
 */
final class WebhookMessageReceipt extends Model
{
    protected $table = 'hookbox_message_receipts';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'headers' => 'array',
    ];
}
