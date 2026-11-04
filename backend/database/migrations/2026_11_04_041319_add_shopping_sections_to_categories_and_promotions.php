<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('shopping_section', 20)->default('groceries')->index();
        });
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('banner_section', 20)->default('groceries');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', fn (Blueprint $table) => $table->dropColumn('banner_section'));
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['shopping_section']);
            $table->dropColumn('shopping_section');
        });
    }
};
