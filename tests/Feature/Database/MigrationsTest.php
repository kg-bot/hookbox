<?php

declare(strict_types=1);

namespace Hookbox\Tests\Feature\Database;

use Hookbox\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationsTest extends TestCase
{
    public function test_package_migrations_create_the_hookbox_schema(): void
    {
        $this->runPackageMigrations();

        $this->assertTrue(Schema::hasTable('hookbox_sources'));
        $this->assertTrue(Schema::hasTable('hookbox_messages'));
        $this->assertTrue(Schema::hasTable('hookbox_message_receipts'));
        $this->assertTrue(Schema::hasTable('hookbox_attempts'));

        $sourceColumns = $this->keyColumnsByName($this->schemaColumns('hookbox_sources'));
        $this->assertArrayHasKey('id', $sourceColumns);
        $this->assertArrayHasKey('slug', $sourceColumns);
        $this->assertArrayHasKey('config', $sourceColumns);
        $this->assertBooleanLikeColumn('hookbox_sources', 'is_active');
        $this->assertContains($this->normalizeDefaultValue($sourceColumns['is_active']['default']), ['1', 'true']);

        $messageColumns = $this->keyColumnsByName($this->schemaColumns('hookbox_messages'));
        $this->assertArrayHasKey('body_hash', $messageColumns);
        $this->assertTrue($messageColumns['source_id']['nullable']);
        $this->assertTrue($messageColumns['redacted_at']['nullable']);
        $this->assertEnumLikeColumn('hookbox_messages', 'signature_status', ['valid', 'invalid', 'skipped']);

        $attemptColumns = $this->keyColumnsByName($this->schemaColumns('hookbox_attempts'));
        $this->assertArrayHasKey('duration_ms', $attemptColumns);
        $this->assertTrue($attemptColumns['duration_ms']['nullable']);
        $this->assertEnumLikeColumn('hookbox_attempts', 'kind', ['initial', 'replay', 'dry_run']);
        $this->assertEnumLikeColumn('hookbox_attempts', 'status', ['pending', 'succeeded', 'failed', 'skipped']);

        $sourceIndexes = $this->schemaIndexes('hookbox_sources');
        $this->assertTrue($this->hasUniqueIndex($sourceIndexes, ['slug']));

        $messageIndexes = $this->schemaIndexes('hookbox_messages');
        $this->assertTrue($this->hasIndex($messageIndexes, ['source_id', 'received_at']));
        $this->assertDescendingMessagesReceivedAtIndex();
        $this->assertTrue($this->hasUniqueIndex($messageIndexes, ['source_id', 'idempotency_key']));
        $this->assertTrue($this->hasIndex($messageIndexes, ['signature_status']));
        $this->assertTrue($this->hasIndex($messageIndexes, ['event_type']));

        $receiptColumns = $this->keyColumnsByName($this->schemaColumns('hookbox_message_receipts'));
        $this->assertArrayHasKey('message_id', $receiptColumns);
        $this->assertArrayHasKey('method', $receiptColumns);
        $this->assertArrayHasKey('url', $receiptColumns);
        $this->assertArrayHasKey('headers', $receiptColumns);
        $this->assertArrayHasKey('body', $receiptColumns);
        $this->assertArrayHasKey('client_ip', $receiptColumns);
        $this->assertTrue($receiptColumns['client_ip']['nullable']);

        $attemptIndexes = $this->schemaIndexes('hookbox_attempts');
        $this->assertTrue($this->hasIndex($attemptIndexes, ['message_id', 'started_at']));
        $this->assertTrue($this->hasIndex($attemptIndexes, ['status']));
        $this->assertTrue($this->hasIndex($attemptIndexes, ['kind', 'started_at']));

        $messageForeignKeys = $this->schemaForeignKeys('hookbox_messages');
        $this->assertTrue($this->hasForeignKey(
            $messageForeignKeys,
            ['source_id'],
            'hookbox_sources',
            ['id'],
        ));

        $attemptForeignKeys = $this->schemaForeignKeys('hookbox_attempts');
        $this->assertTrue($this->hasForeignKey(
            $attemptForeignKeys,
            ['message_id'],
            'hookbox_messages',
            ['id'],
            'cascade',
        ));

        $receiptForeignKeys = $this->schemaForeignKeys('hookbox_message_receipts');
        $this->assertTrue($this->hasForeignKey(
            $receiptForeignKeys,
            ['message_id'],
            'hookbox_messages',
            ['id'],
            'cascade',
        ));

        DB::table('hookbox_messages')->insert([
            'id' => '01jtm3c8g62byrrq7w21z4fgr7',
            'source_id' => null,
            'idempotency_key' => null,
            'event_type' => null,
            'headers' => json_encode(['accept' => ['application/json']], JSON_THROW_ON_ERROR),
            'body' => '{}',
            'body_hash' => str_repeat('a', 64),
            'signature_status' => 'valid',
            'received_at' => '2026-05-09 00:00:00',
            'client_ip' => null,
            'redacted_at' => null,
            'created_at' => '2026-05-09 00:00:00',
            'updated_at' => '2026-05-09 00:00:00',
        ]);

        DB::table('hookbox_message_receipts')->insert([
            'message_id' => '01jtm3c8g62byrrq7w21z4fgr7',
            'method' => 'POST',
            'url' => 'https://example.test/webhook',
            'headers' => json_encode(['content-type' => ['application/json']], JSON_THROW_ON_ERROR),
            'body' => '{"ok":true}',
            'client_ip' => '127.0.0.1',
            'created_at' => '2026-05-09 00:00:00',
            'updated_at' => '2026-05-09 00:00:00',
        ]);

        $this->assertSame(1, DB::table('hookbox_message_receipts')->count());

        try {
            DB::table('hookbox_message_receipts')->insert([
                'message_id' => '01jtm3c8g62byrrq7w21z4fgr7',
                'method' => 'POST',
                'url' => 'https://example.test/webhook/retry',
                'headers' => json_encode(['content-type' => ['application/json']], JSON_THROW_ON_ERROR),
                'body' => '{"ok":false}',
                'client_ip' => '127.0.0.1',
                'created_at' => '2026-05-09 00:00:00',
                'updated_at' => '2026-05-09 00:00:00',
            ]);

            self::fail('Expected duplicate receipt insert to fail.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('hookbox_message_receipts')->count());
        }

        DB::table('hookbox_messages')->where('id', '=', '01jtm3c8g62byrrq7w21z4fgr7')->delete();

        $this->assertSame(0, DB::table('hookbox_message_receipts')->count());
    }

    /**
     * @param  array<int|string, array{name: string}>  $columns
     * @return array<string, array<string, mixed>>
     */
    private function keyColumnsByName(array $columns): array
    {
        $keyedColumns = [];

        foreach ($columns as $column) {
            $keyedColumns[$column['name']] = $column;
        }

        return $keyedColumns;
    }

    private function normalizeDefaultValue(mixed $value): string
    {
        return trim(strtolower((string) $value), "'");
    }

    private function normalizeEnumDefinition(string $definition): string
    {
        return preg_replace('/\s+/', '', strtolower($definition)) ?? strtolower($definition);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertEnumLikeColumn(string $table, string $column, array $allowed): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $sql = DB::table('sqlite_master')
                ->where('type', '=', 'table')
                ->where('name', '=', $table)
                ->value('sql');

            $this->assertIsString($sql);
            $this->assertStringContainsString(
                sprintf('"%s" varchar check ("%s" in (%s))', $column, $column, $this->quoteEnumValues($allowed)),
                strtolower($sql),
            );

            return;
        }

        if ($driver === 'mysql') {
            $columnType = DB::table('information_schema.columns')
                ->where('table_schema', '=', DB::raw('database()'))
                ->where('table_name', '=', $table)
                ->where('column_name', '=', $column)
                ->value('column_type');

            $this->assertSame(
                $this->normalizeEnumDefinition(sprintf('enum(%s)', $this->quoteEnumValues($allowed))),
                $this->normalizeEnumDefinition((string) $columnType),
            );

            return;
        }

        if ($driver === 'pgsql') {
            $constraintDefinition = DB::table('pg_constraint as c')
                ->join('pg_class as t', 't.oid', '=', 'c.conrelid')
                ->join('pg_namespace as n', 'n.oid', '=', 't.relnamespace')
                ->selectRaw('pg_get_constraintdef(c.oid) as definition')
                ->where('c.contype', '=', 'c')
                ->where('t.relname', '=', $table)
                ->whereRaw('n.nspname = current_schema()')
                ->pluck('definition')
                ->first(function (mixed $definition) use ($allowed, $column): bool {
                    if (! is_string($definition)) {
                        return false;
                    }

                    $normalizedDefinition = $this->normalizeEnumDefinition($definition);

                    if (! str_contains($normalizedDefinition, strtolower($column))) {
                        return false;
                    }

                    foreach ($allowed as $value) {
                        if (! str_contains($normalizedDefinition, sprintf("'%s'", strtolower($value)))) {
                            return false;
                        }
                    }

                    return true;
                });

            $this->assertIsString($constraintDefinition);

            return;
        }

        self::fail(sprintf('Unsupported driver [%s].', $driver));
    }

    private function assertBooleanLikeColumn(string $table, string $column): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $sql = DB::table('sqlite_master')
                ->where('type', '=', 'table')
                ->where('name', '=', $table)
                ->value('sql');

            $this->assertIsString($sql);
            $this->assertStringContainsString(sprintf('"%s" tinyint(1)', $column), strtolower($sql));

            return;
        }

        if ($driver === 'mysql') {
            $dataType = DB::table('information_schema.columns')
                ->where('table_schema', '=', DB::raw('database()'))
                ->where('table_name', '=', $table)
                ->where('column_name', '=', $column)
                ->value('data_type');

            $this->assertSame('tinyint', strtolower((string) $dataType));

            return;
        }

        if ($driver === 'pgsql') {
            $dataType = DB::table('information_schema.columns')
                ->where('table_name', '=', $table)
                ->where('column_name', '=', $column)
                ->whereRaw('table_schema = current_schema()')
                ->value('data_type');

            $this->assertSame('boolean', strtolower((string) $dataType));

            return;
        }

        self::fail(sprintf('Unsupported driver [%s].', $driver));
    }

    private function assertDescendingMessagesReceivedAtIndex(): void
    {
        $driver = DB::getDriverName();
        $indexName = 'hookbox_messages_source_id_received_at_index';

        if ($driver === 'sqlite') {
            $sql = DB::table('sqlite_master')
                ->where('type', '=', 'index')
                ->where('name', '=', $indexName)
                ->value('sql');

            $this->assertIsString($sql);
            $this->assertStringContainsString('(source_id, received_at desc)', strtolower($sql));

            return;
        }

        if ($driver === 'mysql') {
            $collations = DB::table('information_schema.statistics')
                ->selectRaw('column_name as index_column_name, collation as index_collation')
                ->where('table_schema', '=', DB::raw('database()'))
                ->where('table_name', '=', 'hookbox_messages')
                ->where('index_name', '=', $indexName)
                ->orderBy('seq_in_index')
                ->get()
                ->values();

            $this->assertCount(2, $collations);
            $first = $collations->get(0);
            $second = $collations->get(1);

            $this->assertNotNull($first);
            $this->assertNotNull($second);
            $this->assertSame('source_id', $first->index_column_name);
            $this->assertSame('received_at', $second->index_column_name);
            $this->assertSame('A', $first->index_collation);
            $this->assertSame('D', $second->index_collation);

            return;
        }

        if ($driver === 'pgsql') {
            $definition = DB::table('pg_indexes')
                ->where('schemaname', '=', DB::raw('current_schema()'))
                ->where('tablename', '=', 'hookbox_messages')
                ->where('indexname', '=', $indexName)
                ->value('indexdef');

            $this->assertIsString($definition);
            $this->assertStringContainsString('(source_id, received_at desc)', strtolower($definition));

            return;
        }

        self::fail(sprintf('Unsupported driver [%s].', $driver));
    }

    /**
     * @param  list<string>  $values
     */
    private function quoteEnumValues(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => sprintf("'%s'", strtolower($value)),
            $values,
        ));
    }

    /**
     * @return list<array{name: string, nullable: bool, default: mixed}>
     */
    private function schemaColumns(string $table): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => $this->sqliteColumns($table),
            'mysql' => $this->mysqlColumns($table),
            'pgsql' => $this->pgsqlColumns($table),
            default => throw new \RuntimeException(sprintf('Unsupported driver [%s].', DB::getDriverName())),
        };
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private function schemaIndexes(string $table): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => $this->sqliteIndexes($table),
            'mysql' => $this->mysqlIndexes($table),
            'pgsql' => $this->pgsqlIndexes($table),
            default => throw new \RuntimeException(sprintf('Unsupported driver [%s].', DB::getDriverName())),
        };
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}>
     */
    private function schemaForeignKeys(string $table): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => $this->sqliteForeignKeys($table),
            'mysql' => $this->mysqlForeignKeys($table),
            'pgsql' => $this->pgsqlForeignKeys($table),
            default => throw new \RuntimeException(sprintf('Unsupported driver [%s].', DB::getDriverName())),
        };
    }

    /**
     * @return list<array{name: string, nullable: bool, default: mixed}>
     */
    private function sqliteColumns(string $table): array
    {
        /** @var list<object{name: string, notnull: int, dflt_value: mixed}> $columns */
        $columns = DB::select(sprintf("pragma table_info('%s')", $table));

        return array_map(static fn (object $column): array => [
            'name' => $column->name,
            'nullable' => (int) $column->notnull === 0,
            'default' => $column->dflt_value,
        ], $columns);
    }

    /**
     * @return list<array{name: string, nullable: bool, default: mixed}>
     */
    private function mysqlColumns(string $table): array
    {
        $columns = DB::table('information_schema.columns')
            ->selectRaw("column_name as name, case when is_nullable = 'YES' then 1 else 0 end as nullable, column_default as default_value")
            ->where('table_schema', '=', DB::raw('database()'))
            ->where('table_name', '=', $table)
            ->orderBy('ordinal_position')
            ->get();

        return array_values(array_map(static fn (object $column): array => [
            'name' => (string) $column->name,
            'nullable' => (int) $column->nullable === 1,
            'default' => $column->default_value,
        ], $columns->all()));
    }

    /**
     * @return list<array{name: string, nullable: bool, default: mixed}>
     */
    private function pgsqlColumns(string $table): array
    {
        $columns = DB::table('information_schema.columns')
            ->selectRaw("column_name as name, case when is_nullable = 'YES' then 1 else 0 end as nullable, column_default as default_value")
            ->where('table_name', '=', $table)
            ->whereRaw('table_schema = current_schema()')
            ->orderBy('ordinal_position')
            ->get();

        return array_values(array_map(static fn (object $column): array => [
            'name' => (string) $column->name,
            'nullable' => (int) $column->nullable === 1,
            'default' => $column->default_value,
        ], $columns->all()));
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private function sqliteIndexes(string $table): array
    {
        /** @var list<object{name: string, unique: int}> $indexes */
        $indexes = DB::select(sprintf("pragma index_list('%s')", $table));

        return array_map(function (object $index): array {
            /** @var list<object{name: string}> $columns */
            $columns = DB::select(sprintf("pragma index_info('%s')", $index->name));

            return [
                'name' => (string) $index->name,
                'columns' => array_map(static fn (object $column): string => (string) $column->name, $columns),
                'unique' => (int) $index->unique === 1,
            ];
        }, $indexes);
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private function mysqlIndexes(string $table): array
    {
        $rows = DB::table('information_schema.statistics')
            ->selectRaw('index_name as name, case when non_unique = 0 then 1 else 0 end as is_unique, seq_in_index as position, column_name as index_column_name')
            ->where('table_schema', '=', DB::raw('database()'))
            ->where('table_name', '=', $table)
            ->orderBy('index_name')
            ->orderBy('seq_in_index')
            ->get();

        /** @var array<string, array{name: string, columns: list<string>, unique: bool}> $indexes */
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (! array_key_exists($name, $indexes)) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (int) $row->is_unique === 1,
                ];
            }

            $indexes[$name]['columns'][] = (string) $row->index_column_name;
        }

        return array_values($indexes);
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    private function pgsqlIndexes(string $table): array
    {
        /** @var list<object{name: string, is_unique: bool, column_name: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
select i.relname as name,
       ix.indisunique as is_unique,
       a.attname as column_name,
       cols.ordinality as position
from pg_class t
join pg_namespace n on n.oid = t.relnamespace
join pg_index ix on ix.indrelid = t.oid
join pg_class i on i.oid = ix.indexrelid
join lateral unnest(ix.indkey) with ordinality as cols(attnum, ordinality) on true
join pg_attribute a on a.attrelid = t.oid and a.attnum = cols.attnum
where n.nspname = current_schema()
  and t.relname = ?
order by i.relname, cols.ordinality
SQL,
            [$table],
        );

        /** @var array<string, array{name: string, columns: list<string>, unique: bool}> $indexes */
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (! array_key_exists($name, $indexes)) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'unique' => (bool) $row->is_unique,
                ];
            }

            $indexes[$name]['columns'][] = (string) $row->column_name;
        }

        return array_values($indexes);
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}>
     */
    private function sqliteForeignKeys(string $table): array
    {
        /** @var list<object{id: int, table: string, from: string, to: string, on_delete: string}> $rows */
        $rows = DB::select(sprintf("pragma foreign_key_list('%s')", $table));

        /** @var array<int, array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}> $foreignKeys */
        $foreignKeys = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            if (! array_key_exists($id, $foreignKeys)) {
                $foreignKeys[$id] = [
                    'columns' => [],
                    'foreign_table' => (string) $row->table,
                    'foreign_columns' => [],
                    'on_delete' => strtolower((string) $row->on_delete),
                ];
            }

            $foreignKeys[$id]['columns'][] = (string) $row->from;
            $foreignKeys[$id]['foreign_columns'][] = (string) $row->to;
        }

        return array_values($foreignKeys);
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}>
     */
    private function mysqlForeignKeys(string $table): array
    {
        $rows = DB::table('information_schema.key_column_usage as kcu')
            ->join('information_schema.referential_constraints as rc', function ($join): void {
                $join->on('rc.constraint_schema', '=', 'kcu.constraint_schema')
                    ->on('rc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('rc.table_name', '=', 'kcu.table_name');
            })
            ->selectRaw('kcu.constraint_name as name, kcu.column_name as local_column_name, kcu.referenced_table_name as foreign_table_name, kcu.referenced_column_name as foreign_column_name, rc.delete_rule as on_delete_rule')
            ->where('kcu.constraint_schema', '=', DB::raw('database()'))
            ->where('kcu.table_name', '=', $table)
            ->whereNotNull('kcu.referenced_table_name')
            ->orderBy('kcu.constraint_name')
            ->orderBy('kcu.ordinal_position')
            ->get();

        /** @var array<string, array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}> $foreignKeys */
        $foreignKeys = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (! array_key_exists($name, $foreignKeys)) {
                $foreignKeys[$name] = [
                    'columns' => [],
                    'foreign_table' => (string) $row->foreign_table_name,
                    'foreign_columns' => [],
                    'on_delete' => strtolower((string) $row->on_delete_rule),
                ];
            }

            $foreignKeys[$name]['columns'][] = (string) $row->local_column_name;
            $foreignKeys[$name]['foreign_columns'][] = (string) $row->foreign_column_name;
        }

        return array_values($foreignKeys);
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}>
     */
    private function pgsqlForeignKeys(string $table): array
    {
        /** @var list<object{name: string, column_name: string, foreign_table: string, foreign_column_name: string, delete_type: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
select c.conname as name,
       ta.attname as column_name,
       ft.relname as foreign_table,
       fa.attname as foreign_column_name,
       c.confdeltype as delete_type,
       src.ordinality as position
from pg_constraint c
join pg_class t on t.oid = c.conrelid
join pg_namespace n on n.oid = t.relnamespace
join pg_class ft on ft.oid = c.confrelid
join lateral unnest(c.conkey) with ordinality as src(attnum, ordinality) on true
join lateral unnest(c.confkey) with ordinality as dst(attnum, ordinality) on dst.ordinality = src.ordinality
join pg_attribute ta on ta.attrelid = t.oid and ta.attnum = src.attnum
join pg_attribute fa on fa.attrelid = ft.oid and fa.attnum = dst.attnum
where c.contype = 'f'
  and n.nspname = current_schema()
  and t.relname = ?
order by c.conname, src.ordinality
SQL,
            [$table],
        );

        /** @var array<string, array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}> $foreignKeys */
        $foreignKeys = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            if (! array_key_exists($name, $foreignKeys)) {
                $foreignKeys[$name] = [
                    'columns' => [],
                    'foreign_table' => (string) $row->foreign_table,
                    'foreign_columns' => [],
                    'on_delete' => $this->pgsqlDeleteAction((string) $row->delete_type),
                ];
            }

            $foreignKeys[$name]['columns'][] = (string) $row->column_name;
            $foreignKeys[$name]['foreign_columns'][] = (string) $row->foreign_column_name;
        }

        return array_values($foreignKeys);
    }

    private function pgsqlDeleteAction(string $action): string
    {
        return match ($action) {
            'a' => 'no action',
            'r' => 'restrict',
            'c' => 'cascade',
            'n' => 'set null',
            'd' => 'set default',
            default => strtolower($action),
        };
    }

    /**
     * @param  array<int|string, mixed>  $indexes
     * @param  list<string>  $columns
     */
    private function hasIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? null) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $indexes
     * @param  list<string>  $columns
     */
    private function hasUniqueIndex(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? null) === $columns && ($index['unique'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $foreignKeys
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    private function hasForeignKey(
        array $foreignKeys,
        array $columns,
        string $foreignTable,
        array $foreignColumns,
        ?string $onDelete = null,
    ): bool {
        foreach ($foreignKeys as $foreignKey) {
            if (($foreignKey['columns'] ?? null) !== $columns) {
                continue;
            }

            if (($foreignKey['foreign_table'] ?? null) !== $foreignTable) {
                continue;
            }

            if (($foreignKey['foreign_columns'] ?? null) !== $foreignColumns) {
                continue;
            }

            if ($onDelete !== null && ($foreignKey['on_delete'] ?? null) !== $onDelete) {
                continue;
            }

            return true;
        }

        return false;
    }
}
