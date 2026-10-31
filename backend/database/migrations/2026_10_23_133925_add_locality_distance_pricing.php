<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_localities', function (Blueprint $table): void {
            $table->string('pricing_mode', 24)->default('flat');
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedInteger('rate_paise_per_km')->default(1000);
        });
    }

    public function down(): void
    {
        Schema::table('service_localities', fn (Blueprint $table) => $table->dropColumn(['pricing_mode', 'distance_meters', 'rate_paise_per_km']));
    }
};
