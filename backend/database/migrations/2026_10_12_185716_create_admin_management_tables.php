<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        foreach (collect(['Fresh', 'Breakfast', 'Snacks', 'Home'])->merge(DB::table('products')->distinct()->pluck('category'))->unique() as $name) {
            DB::table('categories')->insert(['name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['category_id', 'name']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('sub_category_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('delivery_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 15)->unique();
            $table->string('vehicle', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('admin_notes')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->string('coupon_code', 30)->nullable();
        });
        DB::table('orders')->where('discount_paise', '>', 0)->update(['coupon_code' => 'DROP10']);
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_partner_id');
            $table->dropColumn(['admin_notes', 'revision', 'coupon_code']);
        });
        Schema::dropIfExists('delivery_partners');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_category_id');
        });
        Schema::dropIfExists('sub_categories');
        Schema::dropIfExists('categories');
    }
};
