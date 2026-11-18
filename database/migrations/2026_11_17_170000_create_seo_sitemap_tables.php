<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sitemap Inclusion Thresholds (WP5)
        Schema::create('seo_sitemap_thresholds', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('depth')->unique(); // 0 = Country, 1 = Location / Top Category, 2 = Subcategory / Comb, 3 = Sub-sub
            $table->string('name', 100);
            $table->unsignedInteger('min_listings')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        // 2. Pre-calculated Combination Statistics (WP5)
        Schema::create('seo_combination_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->index();
            $table->string('location_type', 32)->nullable(); // 'city', 'state', or null
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('locale', 10)->default('fr');
            $table->string('url_path', 255)->unique('seo_comb_url_path_unique');
            $table->unsignedInteger('listing_count')->default(0);
            $table->decimal('min_price', 15, 2)->nullable();
            $table->decimal('avg_price', 15, 2)->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->boolean('in_sitemap')->default(false)->index();
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();

            $table->index(['country_id', 'in_sitemap'], 'seo_comb_country_sitemap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_combination_stats');
        Schema::dropIfExists('seo_sitemap_thresholds');
    }
};
