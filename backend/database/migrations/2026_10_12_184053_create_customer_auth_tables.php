<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone', 15)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('request_key')->nullable();
            $table->unique(['user_id', 'request_key']);
            $table->string('delivery_preference')->default('standard');
            $table->string('payment_method')->default('cod');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'request_key']);
            $table->dropColumn(['request_key', 'delivery_preference', 'payment_method']);
        });
        Schema::dropIfExists('customer_tokens');
        Schema::dropIfExists('customer_otps');
    }
};
