<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Global Page-Type Templates (WP4)
        Schema::create('seo_template_global', function (Blueprint $table) {
            $table->id();
            $table->string('page_type', 32); // 'country', 'location', 'category', 'combination', 'ad'
            $table->string('locale', 10)->default('fr'); // 'fr', 'en'
            $table->string('variant', 16)->default('default'); // 'default' (count > 0), 'empty' (count == 0)
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1', 255)->nullable();
            $table->text('intro_text')->nullable();
            $table->timestamps();

            $table->unique(['page_type', 'locale', 'variant'], 'seo_tpl_global_unique');
        });

        // 2. Entity Overrides (Category / Location) (WP4)
        Schema::create('seo_meta_entity', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 32); // 'category', 'city', 'state', 'country'
            $table->unsignedBigInteger('entity_id');
            $table->string('locale', 10)->default('fr');
            $table->string('variant', 16)->default('default'); // 'default', 'empty'
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1', 255)->nullable();
            $table->text('intro_text')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'locale', 'variant'], 'seo_meta_entity_unique');
            $table->index(['entity_type', 'entity_id'], 'seo_meta_entity_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta_entity');
        Schema::dropIfExists('seo_template_global');
    }
};
