<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Helpers for SQL that differs between MySQL and PostgreSQL.
 * MySQL keeps the exact SQL it ran before; the pgsql branch is the PostgreSQL equivalent.
 */
final class DbCompat
{
    public static function isPgsql(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * PostgreSQL stores Laravel enum() columns as varchar + CHECK constraint "{table}_{column}_check".
     * Replaces the allowed values (Blueprint::enum()->change() compiles to invalid SQL on pgsql).
     */
    public static function pgsqlSetEnumValues(string $table, string $column, array $values): void
    {
        $constraint = "{$table}_{$column}_check";
        $list = implode(', ', array_map(static fn ($v) => DB::getPdo()->quote($v), $values));

        DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
        DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$constraint}\" CHECK (\"{$column}\" IN ({$list}))");
    }

    /**
     * Runs a statement that the caller may catch and skip. On pgsql a failed statement aborts the
     * surrounding migration transaction, so it runs inside a savepoint; MySQL DDL auto-commits, so it runs directly.
     */
    public static function savepoint(callable $callback): mixed
    {
        return self::isPgsql() ? DB::transaction($callback) : $callback();
    }

    /**
     * Rows inserted with an explicit id do not advance a PostgreSQL sequence; move it past MAX(id)
     * so the next auto-generated id does not collide.
     */
    public static function pgsqlSyncSequence(string $table, string $column = 'id'): void
    {
        if (!self::isPgsql()) {
            return;
        }
        DB::statement(
            "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(\"{$column}\") FROM \"{$table}\"), 0) + 1, false)",
            [$table, $column]
        );
    }

    /**
     * Updates existing rows by primary key. Replaces Model::upsert($rows, ['id'], $cols) when rows carry only
     * id + a few columns: pgsql checks NOT NULL on the proposed insert row before resolving the conflict.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $model
     */
    public static function updateById(string $model, array $rows, array $columns): void
    {
        foreach ($rows as $row) {
            $model::query()->withoutGlobalScopes()->whereKey($row['id'])->update(array_intersect_key($row, array_flip($columns)));
        }
    }

    /** Drops the enum CHECK constraint, leaving a plain varchar column. */
    public static function pgsqlDropEnumCheck(string $table, string $column): void
    {
        DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_{$column}_check\"");
    }

    /**
     * SQL fragment that formats a timestamp column as the start of its day/month/year: 'YYYY-MM-DD' / 'YYYY-MM-01' / 'YYYY-01-01'.
     * $column must be a trusted identifier (never request input).
     */
    public static function dateBucketSql(string $column, string $bucket): string
    {
        $mysql = ['day' => '%Y-%m-%d', 'month' => '%Y-%m-01', 'year' => '%Y-01-01'];
        if (!isset($mysql[$bucket])) {
            throw new InvalidArgumentException("Unknown date bucket [{$bucket}]");
        }

        return self::isPgsql()
            ? "to_char(date_trunc('{$bucket}', ({$column})::timestamp), 'YYYY-MM-DD')"
            : "DATE_FORMAT({$column}, '{$mysql[$bucket]}')";
    }

    /**
     * Stable pseudo-random order for paginated listings: the same seed yields the same order on every page.
     * MySQL keeps RAND(seed); PostgreSQL has no seeded random(), so it orders by a hash of id + seed.
     */
    public static function orderBySeededRandom(BuilderContract $query, string $idColumn, int $seed): BuilderContract
    {
        return self::isPgsql()
            ? $query->orderByRaw("md5({$idColumn}::text || ?)", [(string) $seed])
            : $query->orderByRaw('RAND(?)', [$seed]);
    }

    /** Orders rows to follow the given id list (MySQL FIELD()). */
    public static function orderByIdList(BuilderContract $query, string $idColumn, iterable $ids): BuilderContract
    {
        $ids = array_values(array_map('intval', collect($ids)->all()));
        if ($ids === []) {
            return $query;
        }

        return self::isPgsql()
            ? $query->orderByRaw("array_position(?::bigint[], {$idColumn})", ['{' . implode(',', $ids) . '}'])
            : $query->orderByRaw("FIELD({$idColumn}, " . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /** Matches rows whose comma-separated $column contains $value (MySQL FIND_IN_SET()). */
    public static function whereCsvContains(BuilderContract $query, string $column, string|int $value, string $boolean = 'and'): BuilderContract
    {
        return self::isPgsql()
            ? $query->whereRaw("? = ANY(string_to_array({$column}, ','))", [(string) $value], $boolean)
            : $query->whereRaw("FIND_IN_SET(?, {$column})", [(string) $value], $boolean);
    }

    /**
     * Sets an integer column per row id in one portable UPDATE ... SET col = CASE id WHEN ... END.
     * Replaces upsert([id, col], ['id'], [col]) used for drag-and-drop reordering, which PostgreSQL rejects
     * because the proposed insert rows lack the table's other NOT NULL columns.
     *
     * @param array<int|string, int> $valuesById id => value (both cast to int)
     */
    public static function updateIntColumnById(string $table, string $column, array $valuesById, bool $touch = false): void
    {
        if ($valuesById === []) {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $id = $grammar->wrap('id');

        $cases = '';
        foreach ($valuesById as $rowId => $value) {
            $cases .= ' WHEN ' . (int) $rowId . ' THEN ' . (int) $value;
        }
        $ids = implode(',', array_map('intval', array_keys($valuesById)));
        $set = $grammar->wrap($column) . " = CASE {$id}{$cases} END";
        $bindings = [];
        if ($touch) {
            $set .= ', ' . $grammar->wrap('updated_at') . ' = ?';
            $bindings[] = now();
        }

        DB::update('UPDATE ' . $grammar->wrapTable($table) . " SET {$set} WHERE {$id} IN ({$ids})", $bindings);
    }

    /**
     * Great-circle distance in km from a point to each row's coordinates. Binds three values, in order:
     * [$latitude, $longitude, $latitude]. The acos() argument is clamped to [-1, 1]: float rounding can push it
     * just past 1 for identical coordinates, which PostgreSQL rejects ("input is out of range") and MySQL turns into NULL.
     */
    public static function haversineSql(string $latColumn = 'latitude', string $lngColumn = 'longitude'): string
    {
        return "(6371 * acos(LEAST(1.0, GREATEST(-1.0,
            cos(radians(?)) * cos(radians({$latColumn})) * cos(radians({$lngColumn}) - radians(?))
            + sin(radians(?)) * sin(radians({$latColumn}))))))";
    }

    /**
     * Parses a comma-separated id string (or array) into positive integers for whereIn() on bigint columns.
     * PostgreSQL rejects '' / 'abc' compared to an integer column (SQLSTATE 22P02); MySQL silently matched nothing.
     *
     * @return list<int>
     */
    public static function idList(string|array|null $ids): array
    {
        $parts = is_array($ids) ? $ids : explode(',', (string) $ids);

        return array_values(array_unique(array_filter(
            array_map(static fn ($id) => filter_var(trim((string) $id), FILTER_VALIDATE_INT), $parts),
            static fn ($id) => is_int($id) && $id > 0
        )));
    }
}
