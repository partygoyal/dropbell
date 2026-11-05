<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_products', function (Blueprint $table) {
            $table->json('option_groups')->nullable();
            $table->unsignedInteger('menu_revision')->default(0);
            $table->string('dietary_label', 16)->default('unspecified');
            $table->json('allergens')->nullable();
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->json('selected_options')->nullable());
        Schema::create('customer_favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'target_id']);
        });
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->unsignedInteger('revision')->default(0);
            $table->string('comment', 1000);
            $table->string('status', 12)->default('pending');
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
            $table->index(['product_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->string('tracking_session_hash', 64)->nullable());
        Schema::create('delivery_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_partner_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_meters');
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_locations');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('tracking_session_hash'));
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('customer_favourites');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('selected_options'));
        Schema::table('vendor_products', fn (Blueprint $table) => $table->dropColumn(['option_groups', 'menu_revision', 'dietary_label', 'allergens']));
    }
};
