<?php

namespace App\Http\Controllers;

use App\Services\DeliveryPricing;
use App\Services\Inventory;
use App\Services\Marketplace;
use App\Services\OrderAftercare;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function notifications(): JsonResponse
    {
        $query = DB::table('orders')->where('status', 'received');

        return response()->json([
            'count' => (clone $query)->count(),
            'orders' => $query->orderByDesc('id')->limit(8)->get()->map(fn (object $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'total' => '₹'.number_format($order->total_paise / 100, 2),
                'url' => route('admin.orders.show', $order->id),
            ]),
        ])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): View
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|in:received,picking,out_for_delivery,delivered,cancelled']);
        $query = DB::table('orders');
        if (! empty($input['q'])) {
            $query->where('reference', 'like', '%'.$input['q'].'%');
        }
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        return view('admin.orders.index', ['orders' => $query->latest()->paginate(20)->withQueryString()]);
    }

    public function show(int $id): View
    {
        $order = DB::table('orders')->find($id);
        abort_unless($order, 404);

        return view('admin.orders.show', ['order' => $order, 'items' => DB::table('order_items')->where('order_id', $id)->get(), 'address' => json_decode($order->delivery_address, true), 'partner' => $order->delivery_partner_id ? DB::table('delivery_partners')->find($order->delivery_partner_id) : null, 'events' => DB::table('order_events')->join('users', 'users.id', '=', 'order_events.admin_id')->where('order_id', $id)->select('order_events.*', 'users.name as admin_name')->latest('order_events.id')->get()]);
    }

    public function edit(int $id): View
    {
        $order = DB::table('orders')->find($id);
        abort_unless($order, 404);
        abort_unless(in_array($order->status, ['received', 'picking'], true) && $order->payment_status === 'pending', 422, 'Only received or picking orders can be edited.');

        $items = DB::table('order_items')->where('order_id', $id)->get();
        $isMarketplace = $items->contains(fn ($item) => $item->vendor_id !== null);
        $availableProducts = $isMarketplace ? Marketplace::publicProducts(json_decode($order->delivery_address, true)['pin'])->orderBy('products.name')->get()->map(function ($product) {
            $product->price_paise = $product->selling_price;

            return $product;
        }) : DB::table('products')->where('active', true)->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', today('Asia/Kolkata')->toDateString()))->where('stock_quantity', '>', 0)->orderBy('name')->get();

        return view('admin.orders.edit', [
            'order' => $order, 'address' => json_decode($order->delivery_address, true), 'areas' => DB::table('delivery_areas')->where('active', true)->get(),
            'items' => $items, 'isMarketplace' => $isMarketplace, 'products' => $availableProducts,
            'partners' => DB::table('delivery_partners')->where(function ($query) use ($order) {
                $query->where(function ($query) {
                    $query->where('active', true)->whereExists(fn ($kyc) => $kyc->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'delivery_partners.id')->where('status', 'approved'));
                })->orWhere('id', $order->delivery_partner_id);
            })->orderBy('name')->get(),
        ]);
    }

    public function modify(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate([
            'revision' => 'required|integer|min:0',
            'address' => 'required|array', 'address.name' => 'required|string|max:120',
            'address.phone' => ['required', 'regex:/^[6-9]\d{9}$/'], 'address.street' => 'required|string|max:255',
            'address.city' => 'required|string|max:120', 'address.pin' => ['required', 'regex:/^[1-9]\d{5}$/'],
            'quantities' => 'required|array|min:1|max:100', 'quantities.*' => 'required|integer|min:0|max:99',
            'add_product_id' => 'nullable|integer|exists:products,id', 'add_quantity' => 'nullable|integer|min:1|max:99',
            'delivery_preference' => 'required|in:standard,evening',
            'delivery_partner_id' => 'nullable|integer|exists:delivery_partners,id',
            'admin_notes' => 'nullable|string|max:3000', 'reason' => 'required|string|max:500',
        ]);
        DB::transaction(function () use ($request, $id, $input) {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            if (! in_array($order->status, ['received', 'picking'], true) || $order->payment_status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Only unpaid received or picking orders can be modified.']);
            }
            if ((int) $input['revision'] !== $order->revision) {
                throw ValidationException::withMessages(['order' => 'This order changed while you were editing. Reopen the editor to load the latest details.']);
            }
            $items = DB::table('order_items')->where('order_id', $id)->orderBy('id')->get();
            if (array_diff(array_keys($input['quantities']), $items->pluck('id')->all()) || count($input['quantities']) !== $items->count()) {
                throw ValidationException::withMessages(['quantities' => 'The order items changed. Reopen the editor.']);
            }
            $productIds = $items->pluck('product_id')->filter()->all();
            if (! empty($input['add_product_id'])) {
                $productIds[] = $input['add_product_id'];
            }
            $products = DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $isMarketplace = $items->contains(fn ($item) => $item->vendor_id !== null);
            $stockChanges = [];
            $updatedItems = [];
            $subtotal = 0;
            foreach ($items as $item) {
                $quantity = (int) $input['quantities'][$item->id];
                $delta = $quantity - $item->quantity;
                if ($item->vendor_id) {
                    if ($quantity && ($delta > 0 || $input['address']['pin'] !== json_decode($order->delivery_address, true)['pin'])) {
                        $valid = Marketplace::offers($input['address']['pin'])->where('vendor_products.id', $item->vendor_product_id)->lockForUpdate()->exists();
                        if (! $valid || ! Marketplace::catalogue()->where('products.id', $item->product_id)->exists()) {
                            throw ValidationException::withMessages(['quantities' => 'The original vendor cannot fulfil this item at the new quantity or address. Remove the item and add an available product instead.']);
                        }
                    }
                } else {
                    if ($delta > 0 && (! $item->product_id || ! $products->get($item->product_id)?->active || Inventory::expired($products->get($item->product_id)))) {
                        throw ValidationException::withMessages(['quantities' => 'An unavailable item cannot be increased.']);
                    }
                    if ($item->product_id) {
                        $stockChanges[$item->product_id] = ($stockChanges[$item->product_id] ?? 0) + $delta;
                    }
                }
                if ($quantity) {
                    $updatedItems[] = ['product_id' => $item->product_id, 'product_name' => $item->product_name, 'pack_size' => $item->pack_size, 'unit_price_paise' => $item->unit_price_paise, 'quantity' => $quantity, 'vendor_id' => $item->vendor_id, 'vendor_product_id' => $item->vendor_product_id, 'vendor_name' => $item->vendor_name, 'pickup_address' => $item->pickup_address];
                    $subtotal += $quantity * $item->unit_price_paise;
                }
            }
            if (! empty($input['add_product_id'])) {
                $product = $products->get($input['add_product_id']);
                $quantity = (int) ($input['add_quantity'] ?? 1);
                if (! $product || ! $product->active || (! $isMarketplace && Inventory::expired($product))) {
                    throw ValidationException::withMessages(['add_product_id' => 'That product is unavailable.']);
                }
                if ($items->contains('product_id', $product->id)) {
                    throw ValidationException::withMessages(['add_product_id' => 'This item is already in the order. Change its quantity instead.']);
                }
                if ($isMarketplace) {
                    $offer = Marketplace::selectOffer($product->id, $input['address']['pin'], true);
                    $updatedItems[] = Marketplace::snapshot($offer) + ['product_id' => $product->id, 'product_name' => $product->name, 'pack_size' => $product->pack_size, 'unit_price_paise' => $offer->price_paise, 'quantity' => $quantity];
                    $subtotal += $quantity * $offer->price_paise;
                } else {
                    $stockChanges[$product->id] = ($stockChanges[$product->id] ?? 0) + $quantity;
                    $updatedItems[] = ['product_id' => $product->id, 'product_name' => $product->name, 'pack_size' => $product->pack_size, 'unit_price_paise' => $product->price_paise, 'quantity' => $quantity];
                    $subtotal += $quantity * $product->price_paise;
                }
            }
            if (! $updatedItems) {
                throw ValidationException::withMessages(['quantities' => 'Keep at least one item, or cancel the order instead.']);
            }
            foreach ($stockChanges as $productId => $delta) {
                if ($delta > $products->get($productId)->stock_quantity) {
                    throw ValidationException::withMessages(['quantities' => 'There is insufficient stock for this change.']);
                }
            }
            $partnerId = $input['delivery_partner_id'] ?? null;
            if ($partnerId && (int) $partnerId !== $order->delivery_partner_id && ! DB::table('delivery_partners')->where('id', $partnerId)->where('active', true)->whereExists(fn ($kyc) => $kyc->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'delivery_partners.id')->where('status', 'approved'))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['delivery_partner_id' => 'Choose an active delivery partner with approved KYC.']);
            }
            $discount = ($order->coupon_code === 'DROP10' || $order->discount_paise > 0) && $subtotal >= 10000 ? min(5000, (int) round($subtotal * .1)) : 0;
            $previousAddress = json_decode($order->delivery_address, true);
            $rules = $input['address']['pin'] === $previousAddress['pin'] && $order->delivery_rules
                ? json_decode($order->delivery_rules, true) : DeliveryPricing::rules($input['address']['pin'], true);
            $delivery = DeliveryPricing::fee($rules, $subtotal);
            $changes = ['coupon_code' => ($order->coupon_code === 'DROP10' || $order->discount_paise > 0) ? 'DROP10' : null, 'delivery_address' => json_encode($input['address']), 'delivery_rules' => json_encode($rules), 'delivery_preference' => $input['delivery_preference'], 'delivery_partner_id' => $partnerId, 'admin_notes' => $input['admin_notes'] ?? null, 'subtotal_paise' => $subtotal, 'discount_paise' => $discount, 'delivery_paise' => $delivery, 'total_paise' => $subtotal - $discount + $delivery, 'revision' => $order->revision + 1, 'updated_at' => now()];
            foreach ($stockChanges as $productId => $delta) {
                if ($delta !== 0) {
                    Inventory::change($products->get($productId), -$delta, 'order_modified', $input['reason'], $request->user()->id, $id);
                }
            }
            DB::table('order_items')->where('order_id', $id)->delete();
            foreach ($updatedItems as $item) {
                DB::table('order_items')->insert($item + ['order_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('orders')->where('id', $id)->update($changes);
            OrderAftercare::payment(DB::table('orders')->find($id));
            DB::table('order_transactions')->where('order_id', $id)->where('type', 'payment')->where('status', 'pending')->update(['amount_paise' => $changes['total_paise'], 'updated_at' => now()]);
            DB::table('order_events')->insert(['order_id' => $id, 'admin_id' => $request->user()->id, 'action' => 'Order modified', 'details' => json_encode(['reason' => $input['reason'], 'before' => ['order' => $order, 'items' => $items], 'after' => ['order' => $changes, 'items' => $updatedItems]]), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return redirect()->route('admin.orders.show', $id)->with('status', 'Order updated. Totals have been recalculated.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['status' => 'required|in:received,picking,out_for_delivery,delivered,cancelled']);
        DB::transaction(function () use ($id, $input, $request) {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            if ($order->status === $input['status']) {
                return;
            }
            $next = ['received' => ['picking', 'cancelled'], 'picking' => ['out_for_delivery', 'cancelled'], 'out_for_delivery' => ['delivered', 'cancelled'], 'delivered' => [], 'cancelled' => []];
            if (! in_array($input['status'], $next[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'That status transition is not allowed.']);
            }
            if ($input['status'] === 'out_for_delivery' && DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')->where('order_id', $id)->whereNull('order_items.vendor_id')->where('expires_on', '<', today('Asia/Kolkata')->toDateString())->exists()) {
                throw ValidationException::withMessages(['status' => 'An item in this order has expired. Replace or remove it before dispatch.']);
            }
            if ($input['status'] === 'out_for_delivery' && (! $order->delivery_partner_id || ! DB::table('delivery_partners')->where('id', $order->delivery_partner_id)->where('active', true)->whereExists(fn ($kyc) => $kyc->selectRaw('1')->from('delivery_partner_kycs')->whereColumn('delivery_partner_kycs.delivery_partner_id', 'delivery_partners.id')->where('status', 'approved'))->lockForUpdate()->exists())) {
                throw ValidationException::withMessages(['status' => 'Assign an active partner with approved KYC before dispatching this order.']);
            }
            if ($input['status'] === 'out_for_delivery' && DB::table('order_items')->join('vendors', 'vendors.id', '=', 'order_items.vendor_id')->where('order_id', $id)->where('vendors.status', '!=', 'approved')->exists()) {
                throw ValidationException::withMessages(['status' => 'An assigned vendor is not approved. Resolve the vendor fulfilment before dispatch.']);
            }
            if ($input['status'] === 'cancelled') {
                OrderAftercare::payment($order);
                DB::table('order_transactions')->where('order_id', $id)->where('type', 'payment')->where('status', 'pending')->update(['status' => 'voided', 'updated_at' => now()]);
                foreach (DB::table('order_items')->where('order_id', $id)->orderBy('product_id')->get() as $item) {
                    if ($item->product_id && ! $item->vendor_id) {
                        $product = DB::table('products')->where('id', $item->product_id)->lockForUpdate()->first();
                        Inventory::change($product, $item->quantity, 'order_cancelled', 'Reserved stock restored after order cancellation.', $request->user()->id, $id);
                    }
                }
            }
            DB::table('orders')->where('id', $id)->update(['status' => $input['status'], 'revision' => $order->revision + 1, 'updated_at' => now()]);
            DB::table('order_events')->insert(['order_id' => $id, 'admin_id' => $request->user()->id, 'action' => 'Status updated', 'details' => json_encode(['from' => $order->status, 'to' => $input['status']]), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return back()->with('status', 'Order status updated.');
    }
}
