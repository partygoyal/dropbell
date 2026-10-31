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
        Schema::table('delivery_partners', fn (Blueprint $table) => $table->boolean('is_online')->default(false));
        Schema::table('order_items', fn (Blueprint $table) => $table->timestamp('picked_up_at')->nullable());
        Schema::table('orders', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        Schema::create('partner_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('delivery_partner_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('partner_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_partner_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('partner_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_partner_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->json('details');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_delivery_events');
        Schema::dropIfExists('partner_tokens');
        Schema::dropIfExists('partner_otps');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivered_at'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('picked_up_at'));
        Schema::table('delivery_partners', fn (Blueprint $table) => $table->dropColumn('is_online'));
    }
};
