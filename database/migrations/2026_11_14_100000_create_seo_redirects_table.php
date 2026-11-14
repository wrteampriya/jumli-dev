<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('seo_redirects')) {
            Schema::create('seo_redirects', function (Blueprint $table) {
                $table->id();
                $table->string('source_path', 500)->unique();
                $table->string('target_path', 500);
                $table->integer('status_code')->default(301); // 301 or 410
                $table->string('redirect_type', 50)->default('custom'); // custom, legacy_category, ad_override
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('hits')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
