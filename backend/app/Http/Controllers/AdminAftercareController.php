<?php

namespace App\Http\Controllers;

use App\Services\OrderAftercare;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAftercareController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate(['status' => 'nullable|in:requested,approved,rejected,returned,completed', 'type' => 'nullable|in:cancellation,return,refund']);
        $rows = DB::table('customer_requests')->join('orders', 'orders.id', '=', 'customer_requests.order_id')->select('customer_requests.*', 'orders.reference');
        foreach (['status', 'type'] as $field) {
            if (! empty($input[$field])) {
                $rows->where('customer_requests.'.$field, $input[$field]);
            }
        }

        return view('admin.requests.index', ['requests' => $rows->latest('customer_requests.id')->paginate(20)->withQueryString()]);
    }

    public function show(int $id): View
    {
        $record = DB::table('customer_requests')->find($id);
        abort_unless($record, 404);
        $order = DB::table('orders')->find($record->order_id);

        return view('admin.requests.show', ['record' => $record, 'order' => $order, 'remaining' => OrderAftercare::remaining($order, true, $id)]);
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'status' => 'required|in:approved,rejected,returned,completed', 'review_note' => 'required|string|min:5|max:2000', 'amount' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'regex:/^\d+(\.\d{1,2})?$/'], 'reference' => 'nullable|string|max:120', 'method' => 'nullable|in:cash,bank_transfer,upi']);
        DB::transaction(function () use ($request, $id, $input): void {
            $initial = DB::table('customer_requests')->find($id);
            abort_unless($initial, 404);
            $order = DB::table('orders')->where('id', $initial->order_id)->lockForUpdate()->first();
            $record = DB::table('customer_requests')->where('id', $id)->lockForUpdate()->first();
            if ((int) $input['revision'] !== $record->revision) {
                throw ValidationException::withMessages(['revision' => 'This request changed. Reload before reviewing it.']);
            }
            $status = $input['status'];
            $amount = $record->amount_paise;
            if (in_array($status, ['approved', 'rejected'], true)) {
                if ($record->status !== 'requested') {
                    throw ValidationException::withMessages(['status' => 'Only new requests can be approved or rejected.']);
                }
                if ($status === 'approved' && $record->type === 'cancellation') {
                    OrderAftercare::cancel($order, $request->user()->id, $input['review_note']);
                    $status = 'completed';
                } elseif ($status === 'approved') {
                    if (($record->type === 'return' && $order->status !== 'delivered') || ($record->type === 'refund' && (! in_array($order->status, ['delivered', 'cancelled'], true) || $order->payment_status !== 'paid'))) {
                        throw ValidationException::withMessages(['status' => 'The order is no longer eligible for this request.']);
                    }
                    $amount = isset($input['amount']) ? (int) round((float) $input['amount'] * 100) : $record->amount_paise;
                    if ($amount > OrderAftercare::remaining($order, true, $id) || ($record->type === 'refund' && $amount <= 0)) {
                        throw ValidationException::withMessages(['amount' => 'Choose an amount within the remaining paid balance. Approved requests also reserve this balance.']);
                    }
                }
            } elseif ($status === 'returned') {
                if ($record->type !== 'return' || $record->status !== 'approved') {
                    throw ValidationException::withMessages(['status' => 'Only approved returns can be marked as received.']);
                }
            } else {
                $expected = $record->type === 'return' ? 'returned' : 'approved';
                if ($record->type === 'cancellation' || $record->status !== $expected) {
                    throw ValidationException::withMessages(['status' => 'Approve the request and receive returned goods before completing it.']);
                }
                if ($record->type === 'return' && $order->payment_status === 'pending' && $amount === 0) {
                    DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'payment')->where('status', 'pending')->update(['status' => 'voided', 'updated_at' => now()]);
                    DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'voided', 'revision' => $order->revision + 1, 'updated_at' => now()]);
                }
                if ($amount > 0) {
                    if (empty($input['reference']) || empty($input['method'])) {
                        throw ValidationException::withMessages(['reference' => 'Enter the refund method and the reference of the completed payment.']);
                    }
                    if ($amount > OrderAftercare::remaining($order, false)) {
                        throw ValidationException::withMessages(['amount' => 'This refund exceeds the remaining paid balance.']);
                    }
                    DB::table('order_transactions')->insert(['order_id' => $order->id, 'customer_request_id' => $record->id, 'operation_key' => 'refund:'.$record->id, 'type' => 'refund', 'status' => 'completed', 'amount_paise' => $amount, 'method' => $input['method'], 'reference' => $input['reference'], 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('customer_requests')->where('id', $id)->update(['status' => $status, 'amount_paise' => $amount, 'review_note' => $input['review_note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'revision' => $record->revision + 1, 'updated_at' => now()]);
            OrderAftercare::event($order->id, $request->user()->id, ucfirst($record->type).' request '.$status, ['request_id' => $id, 'from' => $record->status, 'to' => $status, 'reason' => $input['review_note'], 'amount_paise' => $amount, 'reference' => $input['reference'] ?? null]);
        }, 3);

        return back()->with('status', 'Request updated. Refund completion records a payment already made; it does not transfer money.');
    }

    public function payment(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['reference' => 'required|string|min:3|max:120']);
        DB::transaction(function () use ($request, $id, $input): void {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            if ($order->is_sample) {
                throw ValidationException::withMessages(['reference' => 'Test COD orders do not collect real payments.']);
            }
            if ($order->status !== 'delivered') {
                throw ValidationException::withMessages(['payment' => 'Record collection only after delivery.']);
            }
            $payment = OrderAftercare::payment($order);
            if ($payment->status === 'completed') {
                if ($payment->reference === $input['reference']) {
                    return;
                }
                throw ValidationException::withMessages(['payment' => 'Payment has already been recorded.']);
            }
            if (DB::table('customer_requests')->where('order_id', $id)->where('type', 'return')->whereIn('status', ['approved', 'returned', 'completed'])->exists()) {
                throw ValidationException::withMessages(['payment' => 'Resolve this order’s return before recording collection.']);
            }
            DB::table('order_transactions')->where('id', $payment->id)->update(['status' => 'completed', 'amount_paise' => $order->total_paise, 'reference' => $input['reference'], 'recorded_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'revision' => $order->revision + 1, 'updated_at' => now()]);
            OrderAftercare::event($id, $request->user()->id, 'COD payment collected', ['reason' => 'Payment collection confirmed: '.$input['reference'], 'amount_paise' => $order->total_paise]);
        }, 3);

        return back()->with('status', 'Payment collection recorded.');
    }

    public function transactions(Request $request): View
    {
        $input = $request->validate(['type' => 'nullable|in:payment,refund', 'status' => 'nullable|in:pending,completed,voided']);
        $query = DB::table('order_transactions')->join('orders', 'orders.id', '=', 'order_transactions.order_id')->select('order_transactions.*', 'orders.reference as order_reference');
        foreach (['type', 'status'] as $field) {
            if (! empty($input[$field])) {
                $query->where('order_transactions.'.$field, $input[$field]);
            }
        }

        return view('admin.requests.transactions', ['transactions' => $query->latest('order_transactions.id')->paginate(30)->withQueryString()]);
    }
}
