<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('operating_mode', 12)->default('open');
            $table->json('opening_hours')->nullable();
            $table->unsignedSmallInteger('preparation_minutes')->default(25);
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->timestamp('estimated_ready_at')->nullable());
        Schema::table('orders', function (Blueprint $table) {
            $table->text('vendor_rejection_reason')->nullable();
            $table->unsignedBigInteger('vendor_rejected_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['vendor_rejection_reason', 'vendor_rejected_by']));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('estimated_ready_at'));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn(['operating_mode', 'opening_hours', 'preparation_minutes']));
    }
};
