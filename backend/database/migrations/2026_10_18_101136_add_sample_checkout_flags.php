<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->boolean('is_sample')->default(false));
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_sample_guest')->default(false));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('is_sample'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_sample_guest'));
    }
};
