<?php

declare(strict_types=1);

namespace Hookbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string $verifier
 * @property array<string, mixed> $config
 * @property bool $is_active
 */
final class WebhookSource extends Model
{
    use HasUlids;

    protected $table = 'hookbox_sources';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'config' => 'array',
        'is_active' => 'bool',
    ];
}
