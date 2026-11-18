<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs databases imported without the ALTER TABLE part of the dump
 * (primary keys, AUTO_INCREMENT, indexes, foreign keys were missing, so every
 * new row got id = 0).
 *
 * Idempotent: a table that already has a primary key / index / foreign key is
 * left alone, so servers built purely from migrations are a no-op.
 *
 * Phase 1 tables (seo_slug_registry, taxonomy_slugs,
 * country_location_indexability) get only PK + AUTO_INCREMENT + non-unique
 * indexes + FKs here; their UNIQUE keys belong to the Phase 1 migration.
 */
return new class extends Migration
{
    /**
     * Tables with a broken id. Per table:
     *  order   - ORDER BY used when renumbering rows that all have id = 0
     *  keys    - [name => [columns, unique]]
     *  fks     - [name => [column, referenced table, on delete]]
     */
    protected function idRepairs(): array
    {
        return [
            'attribution_event_logs' => [
                'order' => 'created_at, provider, event_key',
                'keys' => [
                    'attribution_event_logs_event_key_index' => [['event_key'], false],
                    'attribution_event_logs_user_id_foreign' => [['user_id'], false],
                ],
                'fks' => [
                    'attribution_event_logs_user_id_foreign' => ['user_id', 'users', 'SET NULL'],
                ],
            ],
            'attribution_event_log_params' => [
                'order' => 'log_id, `key`',
                'keys' => [
                    'attribution_event_log_params_log_id_key_unique' => [['log_id', 'key'], true],
                ],
                'fks' => [
                    'attribution_event_log_params_log_id_foreign' => ['log_id', 'attribution_event_logs', 'CASCADE'],
                ],
            ],
            'module_setting_definitions' => [
                'order' => 'section, name',
                'keys' => [
                    'module_setting_definitions_name_unique' => [['name'], true],
                    'module_setting_definitions_section_index' => [['section'], false],
                ],
                'fks' => [],
            ],
            'module_setting_values' => [
                'order' => 'module_id, module_setting_definition_id',
                'keys' => [
                    'msv_module_definition_unique' => [['module_id', 'module_setting_definition_id'], true],
                    'module_setting_values_module_setting_definition_id_foreign' => [['module_setting_definition_id'], false],
                ],
                'fks' => [
                    'module_setting_values_module_id_foreign' => ['module_id', 'modules', 'CASCADE'],
                    'module_setting_values_module_setting_definition_id_foreign' => ['module_setting_definition_id', 'module_setting_definitions', 'CASCADE'],
                ],
            ],
            'plugin_licenses' => [
                'order' => 'created_at, plugin_slug',
                'keys' => [
                    'plugin_licenses_plugin_slug_index' => [['plugin_slug'], false],
                    'plugin_licenses_plugin_slug_domain_unique' => [['plugin_slug', 'domain'], true],
                ],
                'fks' => [],
            ],
            'seo_slug_registry' => [
                'order' => 'created_at, country_id, slug',
                'keys' => [
                    'seo_slug_registry_entity_index' => [['entity_type', 'entity_id'], false],
                    'seo_slug_registry_parent_id_index' => [['parent_id'], false],
                    'seo_slug_registry_country_id_foreign' => [['country_id'], false],
                ],
                'fks' => [
                    'seo_slug_registry_country_id_foreign' => ['country_id', 'countries', 'CASCADE'],
                ],
            ],
            'taxonomy_slugs' => [
                'order' => 'created_at, entity_type, entity_id',
                'keys' => [
                    'taxonomy_slugs_locale_slug_index' => [['locale', 'slug'], false],
                ],
                'fks' => [],
            ],
            'country_location_indexability' => [
                'order' => 'created_at, country_id, level',
                'keys' => [
                    'country_location_indexability_country_id_foreign' => [['country_id'], false],
                ],
                'fks' => [
                    'country_location_indexability_country_id_foreign' => ['country_id', 'countries', 'CASCADE'],
                ],
            ],
        ];
    }

    /** Tables that own a module_id column (same list as v3.2.0 core). */
    protected array $moduleScopedTables = [
        'categories',
        'custom_fields',
        'packages',
        'sliders',
        'banner_ads',
        'feature_sections',
        'tips',
        'notifications',
        'blog_categories',
    ];

    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->idRepairs() as $table => $spec) {
            if (Schema::hasTable($table)) {
                $this->repairTable($table, $spec);
            }
        }

        foreach ($this->moduleScopedTables as $table) {
            $this->repairModuleId($table);
        }
    }

    public function down(): void
    {
        // Repair migration: nothing to undo. Dropping primary keys again
        // would re-break the schema.
    }

    protected function repairTable(string $table, array $spec): void
    {
        if (!$this->hasPrimaryKey($table)) {
            $this->prepareRows($table, $spec['order']);

            DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`), MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        foreach ($spec['keys'] as $name => [$columns, $unique]) {
            $this->addKey($table, $name, $columns, $unique);
        }

        foreach ($spec['fks'] as $name => [$column, $refTable, $onDelete]) {
            $this->addForeignKey($table, $name, $column, $refTable, $onDelete);
        }
    }

    /**
     * Make the existing rows safe for a primary key before it is added.
     */
    protected function prepareRows(string $table, string $order): void
    {
        $rows = DB::table($table)->count();
        if ($rows === 0) {
            return;
        }

        if ($table === 'attribution_event_log_params') {
            // Every row has log_id = 0 (the link to its event is lost), so
            // the rows cannot be renumbered into a valid (log_id, key)
            // relation. Keep a copy, then empty the table.
            if (!Schema::hasTable('attribution_event_log_params_backup')) {
                DB::statement('CREATE TABLE `attribution_event_log_params_backup` AS SELECT * FROM `attribution_event_log_params`');
            }
            DB::statement('TRUNCATE TABLE `attribution_event_log_params`');
            Log::warning("Repair keys migration: {$rows} rows of attribution_event_log_params could not be linked to an event; copied to attribution_event_log_params_backup and cleared.");

            return;
        }

        // Rows that were imported without a key all share id = 0: renumber.
        DB::statement('SET @repair_id := 0');
        DB::update("UPDATE `{$table}` SET `id` = (@repair_id := @repair_id + 1) ORDER BY {$order}");

        if ($table === 'attribution_event_logs') {
            // user_id FK is ON DELETE SET NULL: detach users that no longer exist.
            DB::update('UPDATE `attribution_event_logs` SET `user_id` = NULL WHERE `user_id` IS NOT NULL AND `user_id` NOT IN (SELECT `id` FROM `users`)');
        }
    }

    protected function repairModuleId(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'module_id')) {
            return;
        }

        $this->addKey($table, "{$table}_module_id_index", ['module_id'], false);

        $orphans = DB::table($table)
            ->whereNotNull('module_id')
            ->whereNotIn('module_id', DB::table('modules')->select('id'))
            ->count();

        if ($orphans > 0) {
            Log::warning("Repair keys migration: {$table} has {$orphans} rows with an unknown module_id; module_id foreign key skipped.");

            return;
        }

        $this->addForeignKey($table, "{$table}_module_id_foreign", 'module_id', 'modules', 'RESTRICT');
    }

    protected function hasPrimaryKey(string $table): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn(array $index) => !empty($index['primary']));
    }

    protected function addKey(string $table, string $name, array $columns, bool $unique): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        // Skip when the same columns are already indexed under another name.
        $existing = collect(Schema::getIndexes($table))->contains(
            fn(array $index) => empty($index['primary'])
                && $index['columns'] === $columns
                && (bool) $index['unique'] === $unique
        );
        if ($existing) {
            return;
        }

        $cols = collect($columns)->map(fn($c) => "`{$c}`")->implode(', ');
        $type = $unique ? 'UNIQUE KEY' : 'KEY';

        DB::statement("ALTER TABLE `{$table}` ADD {$type} `{$name}` ({$cols})");
    }

    protected function addForeignKey(string $table, string $name, string $column, string $refTable, string $onDelete): void
    {
        $exists = collect(Schema::getForeignKeys($table))->contains(
            fn(array $fk) => $fk['name'] === $name || $fk['columns'] === [$column]
        );
        if ($exists) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (`id`) ON DELETE {$onDelete}");
        } catch (\Throwable $e) {
            Log::warning("Repair keys migration: could not add foreign key {$name} on {$table}: {$e->getMessage()}");
        }
    }
};
