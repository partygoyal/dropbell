<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AppNotifications;
use App\Services\DeliveryEstimates;
use App\Services\DeliveryPricing;
use App\Services\Inventory;
use App\Services\Marketplace;
use App\Services\MenuChoices;
use App\Services\OrderAftercare;
use App\Services\Promotions;
use App\Services\ServiceLocations;
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
        $items = DB::table('order_items')->where('order_id', $order->id)->get()->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->product_name, 'qty' => $item->pack_size, 'price' => $item->unit_price_paise / 100, 'quantity' => $item->quantity, 'options' => json_decode($item->selected_options ?? '[]', true)]);

        $vendorReviewRequired = (bool) ($order->vendor_rejection_reason && DB::table('customer_requests')->where('order_id', $order->id)->where('type', 'cancellation')->where('status', 'requested')->exists());
        $estimatedReady = DB::table('order_items')->where('order_id', $order->id)->whereNull('vendor_ready_at')->max('estimated_ready_at');

        return ['deliveryEstimate' => DeliveryEstimates::present($order), 'vendorReviewRequired' => $vendorReviewRequired, 'vendorRejectionReason' => $order->vendor_rejection_reason, 'estimatedReadyAt' => ! $vendorReviewRequired && in_array($order->status, ['received', 'picking'], true) && $estimatedReady ? Carbon::parse($estimatedReady)->toISOString() : null, 'isSample' => (bool) $order->is_sample, 'id' => $order->reference, 'createdAt' => Carbon::parse($order->created_at)->toISOString(), 'status' => $order->status, 'stage' => array_search($order->status, ['received', 'picking', 'out_for_delivery', 'delivered'], true) ?: 0, 'address' => json_decode($order->delivery_address, true), 'delivery' => $order->delivery_preference === 'evening' ? 'Evening delivery' : 'Next available delivery', 'payment' => 'Pay on delivery · '.$order->payment_status, 'paymentStatus' => $order->payment_status, 'invoiceAvailable' => ! $order->is_sample && $order->status === 'delivered', 'requests' => DB::table('customer_requests')->where('order_id', $order->id)->orderByDesc('id')->get()->map(fn ($row) => OrderAftercare::presentRequest($row)), 'refunded' => (int) DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'refund')->where('status', 'completed')->sum('amount_paise'), 'items' => $items, 'deliveryPricing' => self::deliveryPricing($order), 'bill' => ['subtotal' => $order->subtotal_paise, 'discount' => $order->discount_paise, 'delivery' => $order->delivery_paise, 'total' => $order->total_paise]];
    }

    public static function deliveryPricing(object $order): array
    {
        $rules = json_decode($order->delivery_rules ?? '{}', true);

        return in_array($rules['mode'] ?? null, ['per_km', 'locality_per_km'], true) ? ['mode' => $rules['mode'], 'distance_meters' => $rules['distance_meters'], 'rate_paise_per_km' => $rules['rate_paise_per_km'], 'zone_name' => $rules['name'], 'fee_paise' => $order->delivery_paise] : ['mode' => 'flat'];
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
        $input = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99', 'items.*.options' => 'sometimes|array|max:18', 'items.*.options.*' => 'string|max:40', 'items.*.menu_revision' => 'nullable|integer|min:0', 'items.*.vendor_id' => 'nullable|integer|min:1', 'coupon' => 'nullable|string|max:30|regex:/^[A-Z0-9_-]+$/', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'vendor_id' => 'nullable|integer|min:1', 'sample_checkout' => 'sometimes|boolean', 'address' => 'sometimes|array', 'address.locality_id' => 'sometimes|integer', 'address.state_id' => 'sometimes|integer', 'address.city_id' => 'sometimes|integer', 'address.city' => 'sometimes|string|max:120', 'address.latitude' => 'nullable|required_with:address.longitude|numeric|between:-90,90', 'address.longitude' => 'nullable|required_with:address.latitude|numeric|between:-180,180']);
        $input['address'] = ServiceLocations::address($input['address'] ?? []);
        $input['postal_code'] = $input['address']['pin'];
        $calculation = $this->calculate($input['items'], $input['coupon'] ?? null, $input['postal_code'], false, $request->boolean('sample_checkout'), $input['address'] ?? [], isset($input['vendor_id']) ? (int) $input['vendor_id'] : null);

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

    private function calculate(array $items, ?string $coupon, string $postalCode, bool $lock = false, bool $sampleCheckout = false, array $address = [], ?int $vendorId = null): array
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
            if ($vendorId === null && $product->is_sample && ! Marketplace::offers($postalCode)->where('vendor_products.product_id', $product->id)->exists()) {
                if (! $sampleCheckout || $item['quantity'] > $product->stock_quantity) {
                    throw ValidationException::withMessages(['items' => 'Confirm sample checkout and choose a quantity within sample stock.']);
                }
                $isSample = true;
                $product->vendor_offer = null;
            } else {
                if ($vendorId !== null && ! empty($item['vendor_id']) && (int) $item['vendor_id'] !== $vendorId) {
                    throw ValidationException::withMessages(['items' => 'The selected item belongs to another shop.']);
                }
                $offer = Marketplace::selectOffer($product->id, $postalCode, $lock, $item['quantity'], $vendorId ?? ($item['vendor_id'] ?? null));
                if (isset($item['menu_revision']) && (int) $item['menu_revision'] !== (int) $offer->menu_revision) {
                    throw ValidationException::withMessages(['items' => 'The menu options changed. Select them again before ordering.']);
                }
                $custom = MenuChoices::resolve($offer, $item['options'] ?? []);
                $product->selected_options = $custom['choices'];
                $product->price_paise = $offer->price_paise + $custom['extra'];
                $product->vendor_offer = $offer;
            }
            $subtotal += $product->price_paise * $item['quantity'];
        }
        $couponRules = Promotions::resolve($coupon, $lock);
        $discount = Promotions::discount($couponRules, $subtotal);
        if ($isSample && $products->contains(fn ($product) => $product->vendor_offer !== null)) {
            throw ValidationException::withMessages(['items' => 'Keep sample items and real vendor products in separate orders.']);
        }
        $rules = $isSample ? ['id' => null, 'name' => 'Sample delivery (test only)', 'postal_code' => $postalCode, 'fee_paise' => 0, 'minimum_paise' => 0, 'free_above_paise' => null] : DeliveryPricing::forAddress(array_merge($address, ['pin' => $postalCode]), $lock);
        $delivery = DeliveryPricing::fee($rules, $subtotal);
        $total = $subtotal - $discount + $delivery;

        return compact('products', 'subtotal', 'discount', 'delivery', 'total', 'rules', 'isSample', 'couponRules');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'vendor_id' => 'nullable|integer|min:1', 'sample_checkout' => 'sometimes|boolean', 'request_key' => 'required|uuid', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99', 'items.*.options' => 'sometimes|array|max:18', 'items.*.options.*' => 'string|max:40', 'items.*.menu_revision' => 'nullable|integer|min:0', 'items.*.vendor_id' => 'nullable|integer|min:1',
            'address' => 'required|array', 'address.name' => 'required|string|max:120', 'address.phone' => ['required', 'regex:/^[6-9]\d{9}$/'], 'address.street' => 'required|string|max:255', 'address.locality_id' => 'required|integer', 'address.state_id' => 'sometimes|integer', 'address.city_id' => 'sometimes|integer', 'address.city' => 'nullable|string|max:120', 'address.pin' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'address.latitude' => 'nullable|required_with:address.longitude|numeric|between:-90,90', 'address.longitude' => 'nullable|required_with:address.latitude|numeric|between:-180,180',
            'coupon' => 'nullable|string|max:30|regex:/^[A-Z0-9_-]+$/', 'delivery' => 'required|in:standard,evening', 'payment' => 'required|in:cod', 'expected_total' => 'required|integer|min:0',
        ]);
        $order = DB::transaction(function () use ($input, $request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('orders')->where('user_id', $request->user()->id)->where('request_key', $input['request_key'])->first();
            if ($existing) {
                return $existing;
            }
            $input['address'] = ServiceLocations::address($input['address'], true);
            $calculation = $this->calculate($input['items'], $input['coupon'] ?? null, $input['address']['pin'], true, $request->boolean('sample_checkout'), $input['address'], isset($input['vendor_id']) ? (int) $input['vendor_id'] : null);
            ['products' => $products, 'subtotal' => $subtotal, 'discount' => $discount, 'delivery' => $delivery, 'total' => $total, 'rules' => $rules, 'isSample' => $isSample] = $calculation;
            if ($request->user()->is_sample_guest && ! $isSample) {
                throw ValidationException::withMessages(['items' => 'Sign in with a verified account to order real vendor products.']);
            }
            if ($input['expected_total'] !== $total) {
                throw ValidationException::withMessages(['expected_total' => 'Prices have changed. Refresh your bag and review the new total before placing your order.']);
            }
            $id = DB::table('orders')->insertGetId(['locality_id' => $input['address']['locality_id'] ?? null, 'delivery_zone_id' => $rules['zone_id'] ?? null, 'is_sample' => $isSample, 'reference' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'request_key' => $input['request_key'], 'delivery_address' => json_encode($input['address']), 'delivery_rules' => json_encode($rules), 'status' => 'received', 'payment_status' => 'pending', 'subtotal_paise' => $subtotal, 'discount_paise' => $discount, 'delivery_paise' => $delivery, 'total_paise' => $total, 'delivery_preference' => $input['delivery'], 'payment_method' => 'cod', 'coupon_code' => $input['coupon'] ?? null, 'coupon_rules' => isset($calculation['couponRules']) ? json_encode($calculation['couponRules']) : null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($input['items'] as $item) {
                $product = $products->get($item['id']);
                if ($product->vendor_offer) {
                    Marketplace::changeStock($product->vendor_offer->id, -$item['quantity']);
                } else {
                    Inventory::change($product, -$item['quantity'], 'sample_order', 'Stock reserved for a test COD order.', null, $id);
                }
                DB::table('order_items')->insert(($product->vendor_offer ? Marketplace::snapshot($product->vendor_offer) : []) + ['selected_options' => json_encode($product->selected_options ?? []), 'order_id' => $id, 'product_id' => $product->id, 'product_name' => $product->name, 'pack_size' => $product->pack_size, 'unit_price_paise' => $product->price_paise, 'quantity' => $item['quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }

            $order = DB::table('orders')->find($id);
            OrderAftercare::payment($order);
            AppNotifications::status($order);

            return $order;
        }, 3);

        return response()->json(['data' => self::present($order)], 201);
    }
}
