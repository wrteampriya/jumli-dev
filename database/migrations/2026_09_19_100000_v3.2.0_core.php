<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v3.2.0 — module-based marketplace and home-screen redesign.
//
// - `modules` table, the default "classified" module, and a module_id on every
//   scoped table (existing rows attributed to classified).
// - Per-module settings: module_setting_definitions + module_setting_values.
// - items.pricing_mode (replaces categories.price_optional).
// - Nullable category / custom field images (bulk import).
// - Sliders: name, platforms, status. Feature sections: status, item limit,
//   promotion tier. Popular categories: status.
// - plugin_catalog_items dropped (the catalog is read live from the validator).
//
// Every block is guarded so re-running this migration is a clean no-op.
return new class extends Migration {
    /**
     * Tables that own a module_id column. `items` is deliberately absent —
     * it reaches its module via `category_id`.
     */
    protected array $scopedTables = [
        'categories',
        'custom_fields',
        'packages',
        'sliders',
        'banner_ads',
        'feature_sections',
        'home_screen_sections',
        'tips',
        'notifications',
        'blog_categories',
    ];

    public function up(): void
    {
        $this->createModulesTable();

        $classifiedId = $this->seedClassifiedModule();

        $this->addModuleIdColumns();

        $this->backfillModuleId($classifiedId);

        // The plugin catalog is read live from the validator (MarketplaceCatalogService).
        Schema::dropIfExists('plugin_catalog_items');
        DB::table('settings')->where('name', 'plugin_catalog_etag')->delete();

        DB::table('settings')->insertOrIgnore([
            'name' => 'default_module',
            'value' => 'classified',
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Defensive: SystemUpgradeSeeder's insertOrIgnore never overwrites an
        // existing row, so a buyer who migrates by hand (skipping db:seed)
        // would otherwise keep reporting 3.1.0 and get refused by the
        // strictly-sequential update check on the next release.
        DB::table('settings')->updateOrInsert(
            ['name' => 'system_version'],
            ['value' => '3.2.0', 'type' => 'string', 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        $this->dropModuleIdColumns();

        Schema::dropIfExists('modules');
    }

    protected function createModulesTable(): void
    {
        if (Schema::hasTable('modules')) {
            return;
        }

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            // Seeded 'ads' for classified so no existing category URL
            // changes. The /ads -> /classified rename is on hold pending
            // the web team; this column exists so it can happen later
            // without a schema change.
            $table->string('path_prefix', 64)->default('ads');
            $table->boolean('status')->default(true);
            $table->integer('sequence')->default(0);
            $table->string('version')->nullable();
            // NULL = not plugin-installed, so no license is needed.
            $table->string('plugin_slug')->nullable();
            $table->timestamps();

            $table->index(['status', 'sequence']);
        });
    }

    protected function seedClassifiedModule(): int
    {
        DB::table('modules')->updateOrInsert(
            ['slug' => 'classified'],
            [
                'name' => 'Classified',
                'description' => 'General items, electronics, furniture, and fashion.',
                // Icons are uploaded images; the admin sets one from the module edit page.
                'icon' => 'modules/classified-module-icon.jpg',
                'path_prefix' => 'ads',
                'status' => true,
                'sequence' => 1,
                'version' => null,
                'plugin_slug' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return DB::table('modules')->where('slug', 'classified')->value('id');
    }

    protected function addModuleIdColumns(): void
    {
        foreach ($this->scopedTables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'module_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('module_id')->nullable()->after('id');
                $blueprint->index('module_id');
            });

            // Buyers run MySQL 5.7/8 and MariaDB; a table with orphaned rows
            // or a non-InnoDB engine can reject the FK without the column
            // add itself being at fault. Log and move on rather than
            // aborting the whole upgrade over one table's constraint.
            try {
                DbCompat::savepoint(function () use ($table) {
                    Schema::table($table, function (Blueprint $blueprint) {
                        $blueprint->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
                    });
                });
            } catch (\Throwable $e) {
                logger()->warning("v3.2.0: could not add module_id FK on {$table}: {$e->getMessage()}");
            }
        }
    }

    protected function backfillModuleId(int $classifiedId): void
    {
        foreach ($this->scopedTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'module_id')) {
                continue;
            }

            // whereNull guard is what makes re-running this a no-op.
            DB::table($table)->whereNull('module_id')->update(['module_id' => $classifiedId]);
        }
    }
    protected function dropModuleIdColumns(): void
    {
        foreach ($this->scopedTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'module_id')) {
                continue;
            }

            $hasForeign = collect(Schema::getForeignKeys($table))
                ->contains(fn (array $foreign) => $foreign['columns'] === ['module_id']);

            Schema::table($table, function (Blueprint $blueprint) use ($hasForeign) {
                if ($hasForeign) {
                    $blueprint->dropForeign(['module_id']);
                }
                $blueprint->dropColumn('module_id');
            });
        }
    }
};
