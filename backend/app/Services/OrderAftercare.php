<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAftercare
{
    public static function payment(object $order): object
    {
        DB::table('order_transactions')->insertOrIgnore(['order_id' => $order->id, 'operation_key' => 'payment:'.$order->id, 'type' => 'payment', 'status' => $order->is_sample ? 'voided' : ($order->payment_status === 'paid' ? 'completed' : ($order->status === 'cancelled' ? 'voided' : 'pending')), 'amount_paise' => $order->is_sample ? 0 : $order->total_paise, 'method' => 'cod', 'created_at' => $order->created_at, 'updated_at' => now()]);

        return DB::table('order_transactions')->where('operation_key', 'payment:'.$order->id)->first();
    }

    public static function remaining(object $order, bool $reserved = true, ?int $exceptRequest = null): int
    {
        self::payment($order);
        $paid = (int) DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'payment')->where('status', 'completed')->sum('amount_paise');
        $refunded = (int) DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'refund')->where('status', 'completed')->sum('amount_paise');
        $held = $reserved ? (int) DB::table('customer_requests')->where('order_id', $order->id)->whereIn('type', ['return', 'refund'])->whereIn('status', ['approved', 'returned'])->when($exceptRequest, fn ($query) => $query->where('id', '!=', $exceptRequest))->sum('amount_paise') : 0;

        return max(0, $paid - $refunded - $held);
    }

    public static function cancel(object $order, int $adminId, string $reason): void
    {
        if ($order->status === 'cancelled') {
            return;
        }
        if (! in_array($order->status, ['received', 'picking'], true)) {
            throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled before dispatch. Reject the request or arrange a return.']);
        }
        foreach (DB::table('order_items')->where('order_id', $order->id)->orderBy('product_id')->get() as $item) {
            if ($item->vendor_product_id) {
                Marketplace::changeStock($item->vendor_product_id, $item->quantity);
            }
            if ($item->product_id && ! $item->vendor_id) {
                $product = DB::table('products')->where('id', $item->product_id)->lockForUpdate()->first();
                Inventory::change($product, $item->quantity, 'order_cancelled', $reason, $adminId, $order->id);
            }
        }
        self::payment($order);
        DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'payment')->where('status', 'pending')->update(['status' => 'voided', 'updated_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'revision' => $order->revision + 1, 'updated_at' => now()]);
    }

    public static function presentRequest(object $request): array
    {
        return ['id' => $request->id, 'orderId' => $request->reference ?? null, 'type' => $request->type, 'status' => $request->status, 'reason' => $request->reason, 'amount' => $request->amount_paise, 'reviewNote' => $request->review_note, 'createdAt' => $request->created_at, 'updatedAt' => $request->updated_at];
    }

    public static function event(int $orderId, int $adminId, string $action, array $details): void
    {
        DB::table('order_events')->insert(['order_id' => $orderId, 'admin_id' => $adminId, 'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
