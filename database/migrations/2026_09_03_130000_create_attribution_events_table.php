<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attribution_events')) {
            Schema::create('attribution_events', static function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->boolean('enable')->default(1);
                $table->boolean('log')->default(0);
                // app_open can't be toggled off from the admin panel — it's the base event.
                $table->boolean('is_configurable')->default(1);
                $table->timestamps();
            });
        }

        $events = [
            ['key' => 'app_open', 'enable' => 1, 'log' => 0, 'is_configurable' => 0],
            ['key' => 'registration_completed', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'search_performed', 'enable' => 1, 'log' => 0, 'is_configurable' => 1],
            ['key' => 'listing_viewed', 'enable' => 1, 'log' => 0, 'is_configurable' => 1],
            ['key' => 'listing_favourited', 'enable' => 1, 'log' => 0, 'is_configurable' => 1],
            ['key' => 'contact_seller', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'phone_revealed', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'whatsapp_clicked', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'listing_published', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'checkout_started', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'purchase_completed', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'listing_boosted', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
            ['key' => 'subscription_started', 'enable' => 1, 'log' => 1, 'is_configurable' => 1],
        ];

        foreach ($events as $event) {
            DB::table('attribution_events')->updateOrInsert(
                ['key' => $event['key']],
                array_merge($event, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_events');
    }
};
