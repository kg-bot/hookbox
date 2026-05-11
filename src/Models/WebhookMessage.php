<?php

declare(strict_types=1);

namespace Hookbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @internal
 *
 * @property string $id
 * @property string|null $source_id
 * @property string|null $idempotency_key
 * @property string|null $event_type
 * @property array<string, mixed> $headers
 * @property string $body
 * @property string $body_hash
 * @property string $signature_status
 * @property Carbon|null $received_at
 * @property string|null $client_ip
 * @property Carbon|null $redacted_at
 */
final class WebhookMessage extends Model
{
    use HasUlids;
    use MassPrunable;

    protected $table = 'hookbox_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'headers' => 'array',
        'received_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    /**
     * @return Builder<WebhookMessage>
     */
    public function prunable(): Builder
    {
        $connection = $this->getConnection();
        $driver = $connection->getDriverName();
        $messageTable = $this->getTable();
        [$retentionDaysExpression, $comparisonExpression] = match ($driver) {
            'sqlite' => [
                "case when json_type(hookbox_sources.config, '$.retention_days') in ('integer', 'text') and json_extract(hookbox_sources.config, '$.retention_days') not glob '*[^0-9]*' and cast(json_extract(hookbox_sources.config, '$.retention_days') as integer) > 0 then cast(json_extract(hookbox_sources.config, '$.retention_days') as integer) else 30 end",
                "%s.received_at < datetime(?, '-' || (%s) || ' days')",
            ],
            'mysql' => [
                "case when json_unquote(json_extract(hookbox_sources.config, '$.retention_days')) regexp '^[1-9][0-9]*$' then cast(json_unquote(json_extract(hookbox_sources.config, '$.retention_days')) as signed) else 30 end",
                '%s.received_at < timestampadd(day, -(%s), ?)',
            ],
            'pgsql' => [
                "case when coalesce(hookbox_sources.config->>'retention_days', '') ~ '^[1-9][0-9]*$' then (hookbox_sources.config->>'retention_days')::integer else 30 end",
                "%s.received_at < (?::timestamp - ((%s) * interval '1 day'))",
            ],
            default => throw new \RuntimeException(sprintf('Unsupported database driver [%s].', $driver)),
        };

        return self::query()
            ->whereNotNull($this->qualifyColumn('source_id'))
            ->whereExists(function ($query) use ($comparisonExpression, $messageTable, $retentionDaysExpression): void {
                $query->selectRaw('1')
                    ->from('hookbox_sources')
                    ->whereColumn('hookbox_sources.id', $messageTable.'.source_id')
                    ->whereRaw(sprintf($comparisonExpression, $messageTable, $retentionDaysExpression), [now()->toDateTimeString()]);
            });
    }
}
