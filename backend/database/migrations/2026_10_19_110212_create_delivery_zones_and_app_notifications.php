<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('state', 120);
            $table->decimal('hub_latitude', 10, 7);
            $table->decimal('hub_longitude', 10, 7);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['name', 'state']);
        });
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('delivery_cities')->restrictOnDelete();
            $table->string('name', 120);
            $table->decimal('center_latitude', 10, 7);
            $table->decimal('center_longitude', 10, 7);
            $table->unsignedInteger('radius_meters');
            $table->unsignedInteger('rate_paise_per_km');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['city_id', 'name']);
        });
        Schema::create('delivery_zone_partner', function (Blueprint $table) {
            $table->foreignId('zone_id')->constrained('delivery_zones')->cascadeOnDelete();
            $table->foreignId('delivery_partner_id')->constrained()->restrictOnDelete();
            $table->primary(['zone_id', 'delivery_partner_id']);
        });
        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('per_km_enabled')->default(false);
            $table->timestamps();
        });
        DB::table('delivery_settings')->insert(['id' => 1, 'per_km_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('addresses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->restrictOnDelete();
        });
        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('installation_id');
            $table->string('owner_type', 12);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('session_id');
            $table->string('platform', 12);
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->timestamps();
            $table->unique(['installation_id', 'owner_type']);
            $table->index(['owner_type', 'owner_id']);
        });
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 12);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('event_type', 120);
            $table->string('title', 120);
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'id']);
        });
        Schema::create('app_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('app_devices')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'device_id']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notification_deliveries');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('app_devices');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_zone_id'));
        Schema::table('addresses', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::dropIfExists('delivery_zone_partner');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('delivery_cities');
        Schema::dropIfExists('delivery_settings');
    }
};
