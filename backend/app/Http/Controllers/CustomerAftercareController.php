<?php

namespace App\Http\Controllers;

use App\Services\OrderAftercare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerAftercareController extends Controller
{
    public function requests(Request $request): JsonResponse
    {
        $rows = DB::table('customer_requests')->join('orders', 'orders.id', '=', 'customer_requests.order_id')->where('orders.user_id', $request->user()->id)->select('customer_requests.*', 'orders.reference')->latest('customer_requests.id')->get();

        return response()->json(['data' => $rows->map(fn ($row) => OrderAftercare::presentRequest($row))])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['type' => 'required|in:cancellation,return,refund', 'reason' => 'required|string|min:5|max:2000']);
        $row = DB::transaction(function () use ($request, $reference, $input) {
            $order = DB::table('orders')->where('reference', $reference)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($order, 404);
            $existing = DB::table('customer_requests')->where('order_id', $order->id)->where('type', $input['type'])->first();
            if ($existing) {
                return $existing;
            }
            $eligible = match ($input['type']) {
                'cancellation' => in_array($order->status, ['received', 'picking'], true),
                'return' => $order->status === 'delivered',
                'refund' => in_array($order->status, ['delivered', 'cancelled'], true) && $order->payment_status === 'paid',
            };
            if (! $eligible) {
                throw ValidationException::withMessages(['type' => 'This request is not available for the current order status.']);
            }
            $amount = $input['type'] === 'cancellation' ? 0 : OrderAftercare::remaining($order);
            if ($input['type'] === 'refund' && $amount === 0) {
                throw ValidationException::withMessages(['type' => 'There is no unrefunded payment available for this order.']);
            }
            $id = DB::table('customer_requests')->insertGetId(['order_id' => $order->id, 'type' => $input['type'], 'reason' => $input['reason'], 'amount_paise' => $amount, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('customer_requests')->find($id);
        }, 3);

        return response()->json(['data' => OrderAftercare::presentRequest($row)], 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        DB::table('orders')->where('user_id', $request->user()->id)->orderBy('id')->get()->each(fn ($order) => OrderAftercare::payment($order));
        $rows = DB::table('order_transactions')->join('orders', 'orders.id', '=', 'order_transactions.order_id')->where('orders.user_id', $request->user()->id)->select('order_transactions.*', 'orders.reference as order_reference')->latest('order_transactions.id')->get();

        return response()->json(['data' => $rows->map(fn ($row) => ['id' => $row->id, 'orderId' => $row->order_reference, 'type' => $row->type, 'status' => $row->status, 'amount' => $row->amount_paise, 'method' => $row->method, 'reference' => $row->reference, 'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at])])->header('Cache-Control', 'no-store');
    }

    public function invoice(Request $request, string $reference): Response
    {
        $invoice = DB::transaction(function () use ($request, $reference) {
            $order = DB::table('orders')->where('reference', $reference)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_if($order->is_sample, 422, 'Sample orders do not generate sales invoices.');
            $existing = DB::table('order_invoices')->where('order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }
            if ($order->status !== 'delivered') {
                throw ValidationException::withMessages(['invoice' => 'Invoices are available after delivery.']);
            }
            $items = DB::table('order_items')->where('order_id', $order->id)->get(['product_name', 'pack_size', 'quantity', 'unit_price_paise', 'vendor_name']);
            $number = 'DB-'.now()->format('Y').'-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
            $id = DB::table('order_invoices')->insertGetId(['order_id' => $order->id, 'number' => $number, 'snapshot' => json_encode(['order' => $order, 'items' => $items]), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('order_invoices')->find($id);
        }, 3);

        return response()->view('customer.invoice', ['invoice' => $invoice, 'snapshot' => json_decode($invoice->snapshot, true)])->header('Content-Disposition', 'attachment; filename="'.$invoice->number.'.html"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
