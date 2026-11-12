<?php

use App\Services\Module\ModuleService;
use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->dropModulesIsCore();
        $this->requireModuleId();

        /*
        |----------------------------------------------------------------------
        | home_screen_sections
        |----------------------------------------------------------------------
        */
        if (!Schema::hasTable('home_screen_sections')) {
            return;
        }

        if (Schema::hasIndex('home_screen_sections', 'home_screen_sections_section_type_unique')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->dropUnique('home_screen_sections_section_type_unique');
            });
        }

        if (!Schema::hasIndex('home_screen_sections', 'hss_module_section_unique')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->unique(['module_id', 'section_type'], 'hss_module_section_unique');
            });
        }

        // Section order is fixed (HomeScreenSection::DEFAULTS), no longer admin-set.
        if (Schema::hasColumn('home_screen_sections', 'sequence')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->dropColumn('sequence');
            });
        }
        /*
        |----------------------------------------------------------------------
        | module_setting_definitions & module_setting_values
        |----------------------------------------------------------------------
        */
        if (!Schema::hasTable('module_setting_definitions')) {
            Schema::create('module_setting_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->enum('type', ['string', 'integer', 'boolean', 'array']);
                $table->text('default_value')->nullable();
                $table->json('options')->nullable();
                $table->string('rules')->nullable();
                $table->boolean('is_required')->default(false);
                $table->string('section', 50)->default('general')->index();
                $table->string('label', 150);
                $table->string('help_text')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('module_setting_values')) {
            Schema::create('module_setting_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
                $table->foreignId('module_setting_definition_id')->constrained('module_setting_definitions')->cascadeOnDelete();
                $table->text('value')->nullable();
                $table->timestamps();

                $table->unique(['module_id', 'module_setting_definition_id'], 'msv_module_definition_unique');
            });
        }

        ModuleService::syncSettingDefinitions();

        $definitions = DB::table('module_setting_definitions')->get()->keyBy('name');
        $now = now();
        $values = [];

        // max_images / video_max_file_size_mb used to fall back to these
        // global settings; carry a non-default global value into every module
        // so nothing changes after the upgrade.
        $globalFallbacks = [
            'max_images' => 'max_gallery_images',
            'video_max_file_size_mb' => 'item_video_max_file_size_mb',
        ];

        foreach ($globalFallbacks as $name => $globalKey) {
            $definition = $definitions->get($name);
            $globalValue = DB::table('settings')->where('name', $globalKey)->value('value');

            if (!$definition || $globalValue === null || $globalValue === '' || (string) (int) $globalValue === $definition->default_value) {
                continue;
            }

            foreach (DB::table('modules')->pluck('id') as $moduleId) {
                $values[] = [
                    'module_id' => $moduleId,
                    'module_setting_definition_id' => $definition->id,
                    'value' => (string) (int) $globalValue,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // insertOrIgnore: re-runs never clobber an admin's edits.
        if (!empty($values)) {
            DB::table('module_setting_values')->insertOrIgnore($values);
        }

        // Carried into every module above; nothing reads the global values any more.
        DB::table('settings')->whereIn('name', array_values($globalFallbacks))->delete();

        /*
        |----------------------------------------------------------------------
        | Items
        |----------------------------------------------------------------------
        */
        // items.pricing_mode — which of the module's supported pricing modes an ad
        // uses. There are no separate price-range columns: `range` REUSES
        // min_salary / max_salary as the price range, for every category:
        //   fixed / negotiable -> price
        //   range              -> min_salary (from) + max_salary (to)
        //   price_on_request   -> price, min_salary, max_salary all NULL
        // It replaces categories.price_optional.
        if (!Schema::hasColumn('items', 'pricing_mode')) {
            Schema::table('items', function (Blueprint $table) {
                $table->enum('pricing_mode', ['free', 'fixed', 'range', 'negotiable', 'price_on_request'])
                    ->nullable()
                    ->after('price');
            });

            // Backfill existing ads from what they already store.
            DB::table('items')->whereNull('pricing_mode')->whereNotNull('price')->update(['pricing_mode' => 'negotiable']);
            DB::table('items')->whereNull('pricing_mode')
                ->where(function ($query) {
                    $query->whereNotNull('min_salary')->orWhereNotNull('max_salary');
                })
                ->update(['pricing_mode' => 'range']);
            DB::table('items')->whereNull('pricing_mode')->update(['pricing_mode' => 'price_on_request']);
        }
        // else {
        //     // Columns created before 'free' existed: without it, saving a free ad
        //     // stores '' (MySQL non-strict) or fails (strict / pgsql).
        //     $modes = ['free', 'fixed', 'range', 'negotiable', 'price_on_request'];
        //     if (DbCompat::isPgsql()) {
        //         DbCompat::pgsqlSetEnumValues('items', 'pricing_mode', $modes);
        //     } else {
        //         // '' rows are free ads saved before the fix; strict MODIFY rejects
        //         // them, so park them as NULL and restore them as 'free' after.
        //         // $blankIds = DB::table('items')->where('pricing_mode', '')->pluck('id');
        //         DB::table('items')->whereIn('id', $blankIds)->update(['pricing_mode' => null]);
        //         DB::statement("ALTER TABLE items MODIFY COLUMN pricing_mode ENUM('".implode("','", $modes)."') NULL AFTER price");
        //         DB::table('items')->whereIn('id', $blankIds)->update(['pricing_mode' => 'free']);
        //     }
        // }
        /*
        |----------------------------------------------------------------------
        | Sliders
        |----------------------------------------------------------------------
        */
        Schema::table('sliders', function (Blueprint $table) {
            if (!Schema::hasColumn('sliders', 'name')) {
                $table->string('name')->nullable()->after('id');
            }
            if (!Schema::hasColumn('sliders', 'show_on_web')) {
                $table->boolean('show_on_web')->default(1)->after('third_party_link');
            }
            if (!Schema::hasColumn('sliders', 'show_on_app')) {
                $table->boolean('show_on_app')->default(1)->after('show_on_web');
            }
            if (!Schema::hasColumn('sliders', 'status')) {
                $table->boolean('status')->default(1)->after('show_on_app');
            }
        });

        /*
        |----------------------------------------------------------------------
        | popular_categories
        |----------------------------------------------------------------------
        */
        if (!Schema::hasColumn('popular_categories', 'status')) {
            Schema::table('popular_categories', function (Blueprint $table) {
                $table->boolean('status')->default(1)->after('sequence');
            });
        }

        /*
        |----------------------------------------------------------------------
        | feature_sections
        |----------------------------------------------------------------------
        */
        Schema::table('feature_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('feature_sections', 'status')) {
                $table->boolean('status')->default(1)->after('style');
            }
            if (!Schema::hasColumn('feature_sections', 'items_limit')) {
                $table->unsignedSmallInteger('items_limit')->default(5)->after('status');
            }
            if (!Schema::hasColumn('feature_sections', 'promotion_tier')) {
                $table->string('promotion_tier', 20)->nullable()->after('items_limit')->comment('most_viewed / most_liked: featured | standard | null = all');
            }
        });

        /*
        |----------------------------------------------------------------------
        | categories & custom_fields
        |----------------------------------------------------------------------
        */
        if (Schema::hasColumn('categories', 'image')){
            Schema::table('categories', function (Blueprint $table) {
                $table->string('image')->nullable()->change();
            });
        }

        if (Schema::hasColumn('categories', 'price_optional')){
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('price_optional');
            });
        }

        if (Schema::hasColumn('custom_fields', 'image')){
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->string('image')->nullable()->change();
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | modules
    |--------------------------------------------------------------------------
    */
    /**
     * Tables that own a module_id column (same list as the v3.2.0 migration).
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

    // No core module any more: the default is simply the first active module by sequence.
    protected function dropModulesIsCore(): void
    {
        if (!Schema::hasColumn('modules', 'is_core')) {
            return;
        }

        if (Schema::hasIndex('modules', 'modules_is_core_index')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->dropIndex('modules_is_core_index');
            });
        }

        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('is_core');
        });
    }

    // Every scoped row belongs to exactly one module — leftover NULLs go to the
    // first module, then the column becomes NOT NULL.
    protected function requireModuleId(): void
    {
        $defaultModuleId = DB::table('modules')->orderBy('sequence')->orderBy('id')->value('id');

        foreach ($this->scopedTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'module_id')) {
                continue;
            }

            if ($defaultModuleId) {
                DB::table($table)->whereNull('module_id')->update(['module_id' => $defaultModuleId]);
            }

            $column = collect(Schema::getColumns($table))->firstWhere('name', 'module_id');
            if (!$column['nullable']) {
                continue;
            }

            // MySQL refuses to modify a column used by a foreign key while checks are on.
            Schema::withoutForeignKeyConstraints(function () use ($table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('module_id')->nullable(false)->change();
                });
            });
        }
    }

    protected function restoreModulesIsCore(): void
    {
        foreach ($this->scopedTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'module_id')) {
                continue;
            }

            Schema::withoutForeignKeyConstraints(function () use ($table) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('module_id')->nullable()->change();
                });
            });
        }

        if (Schema::hasTable('modules') && !Schema::hasColumn('modules', 'is_core')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->boolean('is_core')->default(false)->after('path_prefix')->index();
            });
            DB::table('modules')->where('slug', 'classified')->update(['is_core' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->restoreModulesIsCore();

        DB::table('settings')->updateOrInsert(['name' => 'max_gallery_images'], ['value' => '5', 'type' => 'number']);
        DB::table('settings')->updateOrInsert(['name' => 'item_video_max_file_size_mb'], ['value' => '50', 'type' => 'string']);

        /*
        |----------------------------------------------------------------------
        | items
        |----------------------------------------------------------------------
        */
        if(Schema::hasTable('items')){
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('pricing_mode');
            });
        }
        /*
        |----------------------------------------------------------------------
        | home_screen_sections
        |----------------------------------------------------------------------
        */
        if (!Schema::hasTable('home_screen_sections')) {
            return;
        }

        if (!Schema::hasColumn('home_screen_sections', 'sequence')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->integer('sequence')->default(0)->after('is_active');
            });
            $defaults = ['all_categories' => 1, 'slider' => 2, 'popular_categories' => 3, 'featured_section' => 4, 'all_ads' => 5];
            foreach ($defaults as $type => $sequence) {
                DB::table('home_screen_sections')->where('section_type', $type)->update(['sequence' => $sequence]);
            }
        }

        if (Schema::hasIndex('home_screen_sections', 'hss_module_section_unique')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->dropUnique('hss_module_section_unique');
            });
        }

        if (!Schema::hasIndex('home_screen_sections', 'home_screen_sections_section_type_unique')) {
            Schema::table('home_screen_sections', function (Blueprint $blueprint) {
                $blueprint->unique('section_type', 'home_screen_sections_section_type_unique');
            });
        }
        /*
        |----------------------------------------------------------------------
        | module_setting_values & module_setting_definitions
        |----------------------------------------------------------------------
        */
        Schema::dropIfExists('module_setting_values');
        Schema::dropIfExists('module_setting_definitions');
        /*
        |----------------------------------------------------------------------
        | popular_categories
        |----------------------------------------------------------------------
        */
        if (Schema::hasColumn('popular_categories', 'status')) {
            Schema::table('popular_categories', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
        /*
        |----------------------------------------------------------------------
        | feature_sections
        |----------------------------------------------------------------------
        */
        Schema::table('feature_sections', function (Blueprint $table) {
            if (Schema::hasColumn('feature_sections', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('feature_sections', 'items_limit')) {
                $table->dropColumn('items_limit');
            }
            if (Schema::hasColumn('feature_sections', 'promotion_tier')) {
                $table->dropColumn('promotion_tier');
            }
        });
        /*
        |----------------------------------------------------------------------
        | categories & custom_fields
        |----------------------------------------------------------------------
        */
        if (Schema::hasColumn('categories', 'image')){
            Schema::table('categories', function (Blueprint $table) {
                $table->string('image')->nullable(false)->change();
            });
        }
        if (!Schema::hasColumn('categories', 'price_optional')){
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('price_optional')->default(0);
            });
        }
        if (Schema::hasColumn('custom_fields', 'image')){
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->string('image')->nullable(false)->change();
            });
        }
        /*
        |----------------------------------------------------------------------
        | sliders
        |----------------------------------------------------------------------
        */
        Schema::table('sliders', function (Blueprint $table) {
            if (Schema::hasColumn('sliders', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('sliders', 'show_on_web')) {
                $table->dropColumn('show_on_web');
            }
            if (Schema::hasColumn('sliders', 'show_on_app')) {
                $table->dropColumn('show_on_app');
            }
            if (Schema::hasColumn('sliders', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
