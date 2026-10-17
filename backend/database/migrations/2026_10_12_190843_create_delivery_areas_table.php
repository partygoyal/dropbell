<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('postal_code', 6)->unique();
            $table->unsignedInteger('fee_paise');
            $table->unsignedInteger('minimum_paise')->default(0);
            $table->unsignedInteger('free_above_paise')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_rules')->nullable();
        });
        DB::table('orders')->orderBy('id')->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                $address = json_decode($order->delivery_address, true);
                DB::table('orders')->where('id', $order->id)->update(['delivery_rules' => json_encode([
                    'id' => null, 'name' => $address['city'] ?? 'Original delivery area',
                    'postal_code' => $address['pin'], 'fee_paise' => 2500,
                    'minimum_paise' => 0, 'free_above_paise' => 30000,
                ])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_rules'));
        Schema::dropIfExists('delivery_areas');
    }
};
