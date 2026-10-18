<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DeliveryPricing;
use App\Services\Inventory;
use App\Services\Marketplace;
use App\Services\OrderAftercare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOrderController extends Controller
{
    public static function present(object $order): array
    {
        $items = DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->product_name, 'qty' => $item->pack_size, 'price' => $item->unit_price_paise / 100, 'quantity' => $item->quantity]);

        return ['isSample' => (bool) $order->is_sample, 'id' => $order->reference, 'createdAt' => Carbon::parse($order->created_at)->toISOString(), 'status' => $order->status, 'stage' => array_search($order->status, ['received', 'picking', 'out_for_delivery', 'delivered'], true) ?: 0, 'address' => json_decode($order->delivery_address, true), 'delivery' => $order->delivery_preference === 'evening' ? 'Evening delivery' : 'Next available delivery', 'payment' => 'Pay on delivery · '.$order->payment_status, 'paymentStatus' => $order->payment_status, 'invoiceAvailable' => ! $order->is_sample && $order->status === 'delivered', 'requests' => DB::table('customer_requests')->where('order_id', $order->id)->orderByDesc('id')->get()->map(fn ($row) => OrderAftercare::presentRequest($row)), 'refunded' => (int) DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'refund')->where('status', 'completed')->sum('amount_paise'), 'items' => $items, 'bill' => ['subtotal' => $order->subtotal_paise, 'discount' => $order->discount_paise, 'delivery' => $order->delivery_paise, 'total' => $order->total_paise]];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('orders')->where('user_id', $request->user()->id)->latest()->get()->map(fn ($order) => self::present($order))]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $order = DB::table('orders')->where('user_id', $request->user()->id)->where('reference', $reference)->first();
        abort_unless($order, 404);

        return response()->json(['data' => self::present($order)]);
    }

    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99', 'coupon' => 'nullable|in:DROP10', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'sample_checkout' => 'sometimes|boolean']);
        $calculation = $this->calculate($input['items'], $input['coupon'] ?? null, $input['postal_code'], false, $request->boolean('sample_checkout'));

        return response()->json(['data' => ['bill' => collect($calculation)->only(['subtotal', 'discount', 'delivery', 'total'])->all(), 'rules' => $calculation['rules'], 'items' => collect($input['items'])->map(fn (array $item): array => ['id' => $item['id'], 'unit_price_paise' => $calculation['products']->get($item['id'])->price_paise, 'quantity' => $item['quantity']])]])->header('Cache-Control', 'no-store');
    }

    public function sampleQuote(Request $request): JsonResponse
    {
        $request->merge(['sample_checkout' => true]);
        $response = $this->quote($request);
        $ids = array_column($request->input('items'), 'id');
        if (Marketplace::offers($request->input('postal_code'))->whereIn('vendor_products.product_id', $ids)->exists() || Marketplace::catalogue()->whereIn('products.id', $ids)->where('products.is_sample', false)->exists()) {
            throw ValidationException::withMessages(['items' => 'Sign in to order real vendor products.']);
        }

        return $response;
    }

    public function storeSample(Request $request): JsonResponse
    {
        $input = $request->validate(['request_key' => 'required|uuid', 'sample_checkout' => 'required|accepted']);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $email = 'sample-'.hash('sha256', $input['request_key']).'@dropbell.invalid';
            $user = User::firstOrCreate(['email' => $email], ['name' => 'Sample checkout guest', 'password' => Str::random(64)]);
            $user->is_sample_guest = true;
            $user->save();
            $request->setUserResolver(fn () => $user);
            $response = $this->store($request);
            $token = Str::random(64);
            DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(6), 'created_at' => now(), 'updated_at' => now()]);
            $body = $response->getData(true);
            $body['token'] = $token;

            return response()->json($body, 201);
        }, 3);
    }

    private function calculate(array $items, ?string $coupon, string $postalCode, bool $lock = false, bool $sampleCheckout = false): array
    {
        $products = Marketplace::catalogue()->whereIn('products.id', array_column($items, 'id'))->orderBy('products.id');
        if ($lock) {
            $products->lockForUpdate();
        }
        $products = $products->get()->keyBy('id');
        $subtotal = 0;
        $isSample = false;
        foreach ($items as $item) {
            $product = $products->get($item['id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'A product is no longer available. Refresh your bag.']);
            }
            if ($product->is_sample && ! Marketplace::offers($postalCode)->where('vendor_products.product_id', $product->id)->exists()) {
                if (! $sampleCheckout || $item['quantity'] > $product->stock_quantity) {
                    throw ValidationException::withMessages(['items' => 'Confirm sample checkout and choose a quantity within sample stock.']);
                }
                $isSample = true;
                $product->vendor_offer = null;
            } else {
                $offer = Marketplace::selectOffer($product->id, $postalCode, $lock, $item['quantity']);
                $product->price_paise = $offer->price_paise;
                $product->vendor_offer = $offer;
            }
            $subtotal += $product->price_paise * $item['quantity'];
        }
        if (! empty($coupon) && $subtotal < 10000) {
            throw ValidationException::withMessages(['coupon' => 'DROP10 requires an items subtotal of ₹100.']);
        }
        $discount = ! empty($coupon) ? min(5000, (int) round($subtotal * .1)) : 0;
        if ($isSample && $products->contains(fn ($product) => $product->vendor_offer !== null)) {
            throw ValidationException::withMessages(['items' => 'Keep sample items and real vendor products in separate orders.']);
        }
        $rules = $isSample ? ['id' => null, 'name' => 'Sample delivery (test only)', 'postal_code' => $postalCode, 'fee_paise' => 0, 'minimum_paise' => 0, 'free_above_paise' => null] : DeliveryPricing::rules($postalCode, $lock);
        $delivery = DeliveryPricing::fee($rules, $subtotal);
        $total = $subtotal - $discount + $delivery;

        return compact('products', 'subtotal', 'discount', 'delivery', 'total', 'rules', 'isSample');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'sample_checkout' => 'sometimes|boolean', 'request_key' => 'required|uuid', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99',
            'address' => 'required|array', 'address.name' => 'required|string|max:120', 'address.phone' => ['required', 'regex:/^[6-9]\d{9}$/'], 'address.street' => 'required|string|max:255', 'address.city' => 'required|string|max:120', 'address.pin' => ['required', 'regex:/^[1-9]\d{5}$/'],
            'coupon' => 'nullable|in:DROP10', 'delivery' => 'required|in:standard,evening', 'payment' => 'required|in:cod', 'expected_total' => 'required|integer|min:0',
        ]);
        $order = DB::transaction(function () use ($input, $request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('orders')->where('user_id', $request->user()->id)->where('request_key', $input['request_key'])->first();
            if ($existing) {
                return $existing;
            }
            $calculation = $this->calculate($input['items'], $input['coupon'] ?? null, $input['address']['pin'], true, $request->boolean('sample_checkout'));
            ['products' => $products, 'subtotal' => $subtotal, 'discount' => $discount, 'delivery' => $delivery, 'total' => $total, 'rules' => $rules, 'isSample' => $isSample] = $calculation;
            if ($request->user()->is_sample_guest && ! $isSample) {
                throw ValidationException::withMessages(['items' => 'Sign in with a verified account to order real vendor products.']);
            }
            if ($input['expected_total'] !== $total) {
                throw ValidationException::withMessages(['expected_total' => 'Prices have changed. Refresh your bag and review the new total before placing your order.']);
            }
            $id = DB::table('orders')->insertGetId(['is_sample' => $isSample, 'reference' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'request_key' => $input['request_key'], 'delivery_address' => json_encode($input['address']), 'delivery_rules' => json_encode($rules), 'status' => 'received', 'payment_status' => 'pending', 'subtotal_paise' => $subtotal, 'discount_paise' => $discount, 'delivery_paise' => $delivery, 'total_paise' => $total, 'delivery_preference' => $input['delivery'], 'payment_method' => 'cod', 'coupon_code' => $input['coupon'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($input['items'] as $item) {
                $product = $products->get($item['id']);
                if ($product->vendor_offer) {
                    Marketplace::changeStock($product->vendor_offer->id, -$item['quantity']);
                } else {
                    Inventory::change($product, -$item['quantity'], 'sample_order', 'Stock reserved for a test COD order.', null, $id);
                }
                DB::table('order_items')->insert(($product->vendor_offer ? Marketplace::snapshot($product->vendor_offer) : []) + ['order_id' => $id, 'product_id' => $product->id, 'product_name' => $product->name, 'pack_size' => $product->pack_size, 'unit_price_paise' => $product->price_paise, 'quantity' => $item['quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }

            $order = DB::table('orders')->find($id);
            OrderAftercare::payment($order);

            return $order;
        }, 3);

        return response()->json(['data' => self::present($order)], 201);
    }
}
