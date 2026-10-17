<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('inventory_revision')->default(0);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->integer('quantity_delta');
            $table->unsignedInteger('stock_before');
            $table->unsignedInteger('stock_after');
            $table->string('note', 500);
            $table->json('details')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'id']);
        });
        DB::table('products')->orderBy('id')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                DB::table('stock_movements')->insert(['product_id' => $product->id, 'type' => 'opening', 'quantity_delta' => $product->stock_quantity, 'stock_before' => 0, 'stock_after' => $product->stock_quantity, 'note' => 'Existing available stock when inventory tracking was enabled.', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['low_stock_threshold', 'expires_on', 'inventory_revision']));
    }
};
