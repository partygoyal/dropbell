<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('description', 300)->nullable();
            $table->string('code', 30)->nullable()->unique();
            $table->string('discount_type', 16)->default('none');
            $table->unsignedInteger('discount_value')->default(0);
            $table->unsignedInteger('minimum_paise')->default(0);
            $table->unsignedInteger('maximum_discount_paise')->nullable();
            $table->string('banner_path')->nullable();
            $table->boolean('show_banner')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->json('coupon_rules')->nullable());
        DB::table('promotions')->insert(['title' => 'Save 10% on your essentials', 'description' => '10% off, up to ₹50, on an items subtotal of ₹100 or more.', 'code' => 'DROP10', 'discount_type' => 'percent', 'discount_value' => 10, 'minimum_paise' => 10000, 'maximum_discount_paise' => 5000, 'show_banner' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('coupon_rules'));
        Schema::dropIfExists('promotions');
    }
};
