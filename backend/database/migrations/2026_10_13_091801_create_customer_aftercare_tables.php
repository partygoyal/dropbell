<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('requested');
            $table->text('reason');
            $table->unsignedBigInteger('amount_paise')->default(0);
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'type']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('order_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_request_id')->nullable()->constrained('customer_requests')->restrictOnDelete();
            $table->string('operation_key')->unique();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_paise');
            $table->string('method', 20)->default('cod');
            $table->string('reference', 120)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('order_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->json('snapshot');
            $table->timestamps();
        });
        DB::table('orders')->orderBy('id')->chunkById(100, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('order_transactions')->insert(['order_id' => $order->id, 'operation_key' => 'payment:'.$order->id, 'type' => 'payment', 'status' => $order->payment_status === 'paid' ? 'completed' : ($order->status === 'cancelled' ? 'voided' : 'pending'), 'amount_paise' => $order->total_paise, 'method' => 'cod', 'created_at' => $order->created_at, 'updated_at' => $order->updated_at]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoices');
        Schema::dropIfExists('order_transactions');
        Schema::dropIfExists('customer_requests');
    }
};
