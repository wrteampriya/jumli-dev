<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Country extensions
        if (Schema::hasTable('countries')) {
            Schema::table('countries', function (Blueprint $table) {
                if (!Schema::hasColumn('countries', 'seo_slug')) {
                    $table->string('seo_slug', 64)->nullable()->unique()->after('name');
                }
                if (!Schema::hasColumn('countries', 'default_locale')) {
                    $table->string('default_locale', 5)->default('fr')->after('iso2');
                }
                if (!Schema::hasColumn('countries', 'currency_code')) {
                    $table->char('currency_code', 3)->nullable()->after('currency');
                }
                if (!Schema::hasColumn('countries', 'is_default')) {
                    $table->boolean('is_default')->default(false)->after('status');
                }
            });
        }

        // 2. Categories extensions
        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (!Schema::hasColumn('categories', 'is_indexable')) {
                    $table->boolean('is_indexable')->default(true)->after('status');
                }
            });
        }

        // 3. Location indexability per country
        if (!Schema::hasTable('country_location_indexability')) {
            Schema::create('country_location_indexability', function (Blueprint $table) {
                $table->id();
                $table->foreignId('country_id')->constrained('countries')->onDelete('cascade');
                $table->enum('level', ['region', 'department', 'city']);
                $table->boolean('is_indexable')->default(true);
                $table->timestamps();

                $table->unique(['country_id', 'level']);
            });
        }

        // 4. Multi-language slugs for locations and categories
        if (!Schema::hasTable('taxonomy_slugs')) {
            Schema::create('taxonomy_slugs', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 32); // 'category', 'state', 'city'
                $table->unsignedBigInteger('entity_id');
                $table->string('locale', 5); // 'fr', 'en'
                $table->string('slug', 96);
                $table->timestamps();

                $table->unique(['entity_type', 'entity_id', 'locale'], 'tax_slug_entity_locale_unique');
                $table->index(['entity_type', 'entity_id']);
                $table->index(['slug', 'locale']);
            });
        }

        // 5. Slug registry table
        if (!Schema::hasTable('seo_slug_registry')) {
            Schema::create('seo_slug_registry', function (Blueprint $table) {
                $table->id();
                $table->foreignId('country_id')->constrained('countries')->onDelete('cascade');
                $table->string('locale', 5);
                $table->string('slug', 96);
                $table->enum('entity_type', ['location', 'category']);
                $table->unsignedBigInteger('entity_id');
                $table->unsignedTinyInteger('level'); // location: 1 region/state, 2 dept, 3 city; category: 1, 2, 3
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestamps();

                $table->unique(['country_id', 'locale', 'slug'], 'seo_slug_registry_unique');
                $table->index(['country_id', 'locale', 'entity_type']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_slug_registry');
        Schema::dropIfExists('taxonomy_slugs');
        Schema::dropIfExists('country_location_indexability');

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (Schema::hasColumn('categories', 'is_indexable')) {
                    $table->dropColumn('is_indexable');
                }
            });
        }

        if (Schema::hasTable('countries')) {
            Schema::table('countries', function (Blueprint $table) {
                $columns = ['seo_slug', 'default_locale', 'currency_code', 'is_default'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('countries', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
