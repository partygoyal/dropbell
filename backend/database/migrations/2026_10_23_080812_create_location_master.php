<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_states', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('service_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained('service_states')->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['state_id', 'name']);
        });
        Schema::create('service_localities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('service_cities')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('postal_code', 6);
            $table->unsignedInteger('fee_paise')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['city_id', 'name']);
        });
        Schema::create('service_locality_partner', function (Blueprint $table) {
            $table->foreignId('locality_id')->constrained('service_localities')->cascadeOnDelete();
            $table->foreignId('delivery_partner_id')->constrained()->restrictOnDelete();
            $table->primary(['locality_id', 'delivery_partner_id']);
        });
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('locality_id')->nullable()->constrained('service_localities')->restrictOnDelete();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('locality_id')->nullable()->constrained('service_localities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('locality_id'));
        Schema::table('addresses', fn (Blueprint $table) => $table->dropConstrainedForeignId('locality_id'));
        Schema::dropIfExists('service_locality_partner');
        Schema::dropIfExists('service_localities');
        Schema::dropIfExists('service_cities');
        Schema::dropIfExists('service_states');
    }
};
