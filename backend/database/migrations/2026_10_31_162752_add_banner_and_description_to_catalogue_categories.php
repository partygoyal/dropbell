<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'sub_categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('description', 300)->nullable();
                $table->string('banner_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['categories', 'sub_categories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['description', 'banner_path']);
            });
        }
    }
};
