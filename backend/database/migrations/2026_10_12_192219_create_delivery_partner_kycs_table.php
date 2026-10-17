<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_partner_kycs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_partner_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name', 120);
            $table->string('address', 255);
            $table->string('city', 120);
            $table->string('postal_code', 6);
            $table->string('document_type', 40);
            $table->string('reference_last_four', 4)->nullable();
            $table->string('identity_path');
            $table->string('address_path');
            $table->string('licence_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_kyc_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_partner_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->text('note')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_kyc_events');
        Schema::dropIfExists('delivery_partner_kycs');
    }
};
