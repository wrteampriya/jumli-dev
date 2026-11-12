<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attribution_provider_events')) {
            Schema::create('attribution_provider_events', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->constrained('attribution_providers')->onDelete('cascade');
                $table->foreignId('event_id')->constrained('attribution_events')->onDelete('cascade');
                $table->boolean('enable')->default(1);
                $table->boolean('log')->default(0);
                $table->timestamps();

                $table->unique(['provider_id', 'event_id']);
            });
        } else {
            Schema::table('attribution_provider_events', static function (Blueprint $table) {
                if (! Schema::hasColumn('attribution_provider_events', 'enable')) {
                    $table->boolean('enable')->default(1);
                }
                if (! Schema::hasColumn('attribution_provider_events', 'log')) {
                    $table->boolean('log')->default(0);
                }
            });
        }

        $providers = DB::table('attribution_providers')->get();
        $events = DB::table('attribution_events')->get();

        foreach ($providers as $provider) {
            foreach ($events as $event) {
                DB::table('attribution_provider_events')->updateOrInsert(
                    [
                        'provider_id' => $provider->id,
                        'event_id' => $event->id,
                    ],
                    [
                        'enable' => $event->enable ?? 1,
                        'log' => $event->log ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attribution_provider_events')) {
            Schema::table('attribution_provider_events', static function (Blueprint $table) {
                if (Schema::hasColumn('attribution_provider_events', 'enable')) {
                    $table->dropColumn('enable');
                }
                if (Schema::hasColumn('attribution_provider_events', 'log')) {
                    $table->dropColumn('log');
                }
            });
        }
    }
};
