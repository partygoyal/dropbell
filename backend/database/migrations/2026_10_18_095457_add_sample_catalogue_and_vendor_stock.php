<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('is_sample')->default(false));
        Schema::table('vendor_products', fn (Blueprint $table) => $table->unsignedInteger('stock_quantity')->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_sample'));
        Schema::table('vendor_products', fn (Blueprint $table) => $table->dropColumn('stock_quantity'));
    }
};
