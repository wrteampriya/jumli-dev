<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attribution_event_logs')) {
            Schema::create('attribution_event_logs', static function (Blueprint $table) {
                $table->id();
                $table->string('event_key')->index();
                $table->string('provider')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('success'); // success | error
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }

        // Event parameters, stored as key/value rows instead of a json column
        // (same pattern as attribution_provider_settings).
        if (! Schema::hasTable('attribution_event_log_params')) {
            Schema::create('attribution_event_log_params', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('log_id')->constrained('attribution_event_logs')->onDelete('cascade');
                $table->string('key');
                $table->text('value')->nullable();
                $table->unique(['log_id', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_event_log_params');
        Schema::dropIfExists('attribution_event_logs');
    }
};
