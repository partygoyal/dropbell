<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('subject', 120);
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('customer_read_id')->default(0);
            $table->unsignedBigInteger('admin_read_id')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'updated_at']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_role', 12);
            $table->uuid('request_key');
            $table->text('body');
            $table->timestamps();
            $table->unique(['ticket_id', 'sender_role', 'request_key']);
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
