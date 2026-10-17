<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public static function expired(object $product): bool
    {
        return $product->expires_on !== null && $product->expires_on < today('Asia/Kolkata')->toDateString();
    }

    public static function change(object $product, int $delta, string $type, string $note, ?int $actorId = null, ?int $orderId = null, ?string $requestKey = null, ?string $requestHash = null, ?array $details = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Inventory changes require a transaction and a locked product.');
        }
        $before = (int) $product->stock_quantity;
        $after = $before + $delta;
        if ($after < 0 || $after > 1000000) {
            throw ValidationException::withMessages(['quantity' => 'This change would take stock below zero or above 1,000,000 units.']);
        }
        DB::table('products')->where('id', $product->id)->update(['stock_quantity' => $after, 'inventory_revision' => $product->inventory_revision + 1, 'updated_at' => now()]);
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'actor_id' => $actorId, 'order_id' => $orderId, 'type' => $type, 'quantity_delta' => $delta, 'stock_before' => $before, 'stock_after' => $after, 'note' => $note, 'details' => $details ? json_encode($details) : null, 'request_key' => $requestKey, 'request_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now()]);
        $product->stock_quantity = $after;
        $product->inventory_revision++;
    }
}
