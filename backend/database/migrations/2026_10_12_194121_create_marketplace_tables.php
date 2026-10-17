<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_vendor')->default(false)->index());
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('business_name', 120)->nullable();
            $table->string('pickup_address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 6)->nullable();
            $table->json('service_postal_codes')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('revision')->default(0);
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('vendor_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('price_paise');
            $table->boolean('available')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['vendor_id', 'product_id']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_product_id')->nullable()->constrained('vendor_products')->restrictOnDelete();
            $table->string('vendor_name', 120)->nullable();
            $table->json('pickup_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('vendor_product_id');
            $table->dropColumn(['vendor_name', 'pickup_address']);
        });
        Schema::dropIfExists('vendor_products');
        Schema::dropIfExists('vendors');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_vendor'));
    }
};
