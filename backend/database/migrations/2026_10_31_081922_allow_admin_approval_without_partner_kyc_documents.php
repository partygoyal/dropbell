<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_partner_kycs', function (Blueprint $table) {
            $table->string('legal_name', 120)->nullable()->change();
            $table->string('address', 255)->nullable()->change();
            $table->string('city', 120)->nullable()->change();
            $table->string('postal_code', 6)->nullable()->change();
            $table->string('document_type', 40)->nullable()->change();
            $table->string('identity_path')->nullable()->change();
            $table->string('address_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve document-free approvals when rolling back application code.
    }
};
