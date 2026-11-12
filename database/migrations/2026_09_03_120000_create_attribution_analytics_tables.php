<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attribution_providers')) {
            Schema::create('attribution_providers', static function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->boolean('status')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attribution_provider_settings')) {
            Schema::create('attribution_provider_settings', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->constrained('attribution_providers')->onDelete('cascade');
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
                $table->unique(['provider_id', 'key']);
            });
        }

        $providers = [
            ['key' => 'meta', 'name' => 'Meta', 'status' => 0],
            ['key' => 'firebase', 'name' => 'Firebase', 'status' => 0],
        ];
        foreach ($providers as $provider) {
            \DB::table('attribution_providers')->updateOrInsert(
                ['key' => $provider['key']],
                array_merge($provider, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        // Firebase only needs the enable/disable toggle, no config values.
        // Meta shows example config keys (view only in the admin panel).
        $metaProviderId = \DB::table('attribution_providers')->where('key', 'meta')->value('id');
        $metaConfig = [
            ['key' => 'app_id', 'value' => '1234567890123456'],
            ['key' => 'client_token', 'value' => 'EXAMPLE_CLIENT_TOKEN'],
        ];
        foreach ($metaConfig as $config) {
            \DB::table('attribution_provider_settings')->updateOrInsert(
                ['provider_id' => $metaProviderId, 'key' => $config['key']],
                ['value' => $config['value'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Permission::firstOrCreate([
            'name' => 'attribution-settings-update',
            'guard_name' => 'web',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_provider_settings');
        Schema::dropIfExists('attribution_providers');

        Permission::where('name', 'attribution-settings-update')->delete();
    }
};
