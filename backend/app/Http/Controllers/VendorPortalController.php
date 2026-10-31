<?php

namespace App\Http\Controllers;

use App\Services\Marketplace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorPortalController extends Controller
{
    public function orderAlerts(Request $request): JsonResponse
    {
        $input = $request->validate(['after' => 'sometimes|integer|min:0']);
        $query = DB::table('orders')->where('is_sample', false)->whereExists(fn ($items) => $items->selectRaw('1')->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->where('vendor_id', $request->attributes->get('vendor')->id));
        if (! array_key_exists('after', $input)) {
            return response()->json(['cursor' => (int) $query->max('orders.id'), 'orders' => []])->header('Cache-Control', 'private, no-store');
        }
        $orders = $query->where('orders.id', '>', $input['after'])->orderBy('orders.id')->limit(100)->get(['orders.id', 'orders.reference', 'orders.status']);

        return response()->json(['cursor' => $orders->last()?->id ?? (int) $input['after'], 'orders' => $orders->where('status', '!=', 'cancelled')->values()])->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(Request $request): View
    {
        $vendor = $request->attributes->get('vendor');

        return view('vendor.dashboard', ['vendor' => $vendor, 'selected' => DB::table('vendor_products')->where('vendor_id', $vendor->id)->count(), 'available' => DB::table('vendor_products')->where('vendor_id', $vendor->id)->where('available', true)->count(), 'items' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('vendor_id', $vendor->id)->select('order_items.product_name', 'order_items.pack_size', 'order_items.quantity', 'order_items.unit_price_paise', 'orders.reference', 'orders.status', 'orders.created_at')->latest('orders.id')->paginate(20)]);
    }

    public function onboarding(Request $request): View
    {
        return view('vendor.onboarding', ['vendor' => $request->attributes->get('vendor'), 'areas' => DB::table('delivery_areas')->where('active', true)->orderBy('postal_code')->get()]);
    }

    public function saveOnboarding(Request $request): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'business_name' => 'required|string|max:120', 'pickup_address' => 'required|string|max:255', 'city' => 'required|string|max:120', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'service_postal_codes' => 'required|array|min:1|max:50', 'service_postal_codes.*' => ['required', 'distinct', 'regex:/^[1-9]\d{5}$/', 'exists:delivery_areas,postal_code']]);
        if (DB::table('delivery_areas')->whereIn('postal_code', $input['service_postal_codes'])->where('active', true)->count() !== count($input['service_postal_codes'])) {
            throw ValidationException::withMessages(['service_postal_codes' => 'Choose active delivery areas.']);
        }
        DB::transaction(function () use ($request, $input) {
            $vendor = DB::table('vendors')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_if($vendor->status === 'suspended', 403, 'Your account is suspended. Contact Dropbell.');
            if ($vendor->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Your profile changed. Reload before submitting.']);
            }
            DB::table('vendors')->where('id', $vendor->id)->update(collect($input)->only(['business_name', 'pickup_address', 'city', 'postal_code'])->all() + ['service_postal_codes' => json_encode(array_values($input['service_postal_codes'])), 'status' => 'pending', 'revision' => $vendor->revision + 1, 'review_note' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
        });

        return redirect()->route('vendor.dashboard')->with('status', 'Business details submitted for admin approval. Offers appear to customers after approval.');
    }

    public function products(Request $request): View
    {
        $input = $request->validate(['category' => 'nullable|string|max:80|exists:categories,name', 'sub_category_id' => 'nullable|integer|exists:sub_categories,id', 'q' => 'nullable|string|max:120', 'selected' => 'nullable|in:1', 'uncategorized' => 'nullable|in:1']);
        $vendor = $request->attributes->get('vendor');
        $category = $input['category'] ?? null;
        if (! empty($input['sub_category_id'])) {
            $sub = DB::table('sub_categories')->join('categories', 'categories.id', '=', 'sub_categories.category_id')->where('sub_categories.id', $input['sub_category_id'])->where('sub_categories.active', true)->where('categories.active', true)->select('categories.name')->first();
            if (! $sub || ($category && $category !== $sub->name) || ! empty($input['uncategorized'])) {
                throw ValidationException::withMessages(['sub_category_id' => 'Choose a sub-category belonging to the selected category.']);
            }
            $category = $sub->name;
        }
        $categories = DB::table('categories')->where('active', true)->orderBy('name')->get();
        $selectedCategory = $categories->firstWhere('name', $category);
        if ($category && ! $selectedCategory) {
            throw ValidationException::withMessages(['category' => 'Choose an active category.']);
        }
        $subCategories = DB::table('sub_categories')->where('active', true)->where('category_id', $selectedCategory?->id ?? 0)->orderBy('name')->get();
        $query = Marketplace::catalogue()->leftJoin('vendor_products', fn ($join) => $join->on('products.id', '=', 'vendor_products.product_id')->where('vendor_products.vendor_id', $vendor->id))->select('products.*', 'vendor_products.price_paise as vendor_price', 'vendor_products.available as vendor_available', 'vendor_products.revision as offer_revision', 'vendor_products.stock_quantity as vendor_stock');
        if ($category) {
            $query->where('products.category', $category);
        }
        if (! empty($input['sub_category_id'])) {
            $query->where('products.sub_category_id', $input['sub_category_id']);
        } elseif (! empty($input['uncategorized'])) {
            $query->whereNull('products.sub_category_id');
        }
        if (! empty($input['q'])) {
            $query->where('products.name', 'like', '%'.$input['q'].'%');
        }
        if (! empty($input['selected'])) {
            $query->whereNotNull('vendor_products.id');
        }
        $showProducts = $category || ! empty($input['q']) || ! empty($input['selected']);
        if (! $showProducts) {
            $query->whereRaw('1 = 0');
        }

        return view('vendor.products', [
            'vendor' => $vendor, 'products' => $query->orderBy('products.name')->paginate(30)->withQueryString(),
            'categories' => $categories, 'selectedCategory' => $selectedCategory, 'subCategories' => $subCategories,
            'showProducts' => $showProducts,
            'hasUncategorized' => $selectedCategory && Marketplace::catalogue()->where('products.category', $category)->whereNull('products.sub_category_id')->exists(),
        ]);
    }

    public function saveProducts(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'products' => 'required|array|min:1|max:30',
            'products.*.id' => 'required|integer|distinct|min:1',
            'products.*.revision' => 'required|integer|min:0',
            'products.*.available' => 'required|boolean',
            'products.*.stock_quantity' => 'nullable|integer|min:0|max:1000000',
            'products.*.price' => ['nullable', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        DB::transaction(function () use ($request, $input): void {
            $ids = array_column($input['products'], 'id');
            $products = Marketplace::catalogue()->whereIn('products.id', $ids)->orderBy('products.id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($products->count() === count($ids), 404, 'A product is no longer in the catalogue. Reload your selection.');
            $vendor = DB::table('vendors')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_if($vendor->status === 'suspended', 403, 'Your account is suspended. Contact Dropbell.');
            $offers = DB::table('vendor_products')->where('vendor_id', $vendor->id)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            foreach ($input['products'] as $key => $row) {
                $offer = $offers->get($row['id']);
                $product = $products->get($row['id']);
                if ((int) $row['revision'] !== ($offer?->revision ?? 0)) {
                    throw ValidationException::withMessages(['products.'.$key.'.revision' => $product->name.' changed since you opened this page. Reload before saving. No changes were saved.']);
                }
                if ($row['available'] && ! isset($row['price'])) {
                    throw ValidationException::withMessages(['products.'.$key.'.price' => 'Enter your selling price for '.$product->name.'.']);
                }
                if (! $offer && ! $row['available']) {
                    continue;
                }
                $price = isset($row['price']) ? (int) round((float) $row['price'] * 100) : ($offer?->price_paise ?? 0);
                $stock = array_key_exists('stock_quantity', $row) ? (isset($row['stock_quantity']) ? (int) $row['stock_quantity'] : null) : $offer?->stock_quantity;
                if ($offer && (bool) $offer->available === (bool) $row['available'] && $offer->price_paise === $price && $offer->stock_quantity === $stock) {
                    continue;
                }
                DB::table('vendor_products')->updateOrInsert(['vendor_id' => $vendor->id, 'product_id' => $row['id']], [
                    'price_paise' => $price, 'stock_quantity' => $stock, 'available' => (bool) $row['available'],
                    'revision' => ($offer?->revision ?? 0) + 1, 'created_at' => $offer?->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        }, 3);

        return redirect()->route('vendor.products', $request->only(['category', 'sub_category_id', 'uncategorized', 'q', 'selected', 'page']))->with('status', 'Shop availability saved. Available products appear to customers in your approved delivery areas.');
    }

    public function saveProduct(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'available' => 'required|boolean', 'stock_quantity' => 'nullable|integer|min:0|max:1000000', 'price' => ['nullable', 'required_if:available,1', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/']]);
        DB::transaction(function () use ($request, $id, $input) {
            $product = Marketplace::catalogue()->where('products.id', $id)->lockForUpdate()->first();
            abort_unless($product, 404);
            $vendor = DB::table('vendors')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_if($vendor->status === 'suspended', 403, 'Your account is suspended. Contact Dropbell.');
            $offer = DB::table('vendor_products')->where('vendor_id', $vendor->id)->where('product_id', $id)->lockForUpdate()->first();
            if ((int) $input['revision'] !== ($offer?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'This offer changed. Reload to see your latest price and availability.']);
            }
            DB::table('vendor_products')->updateOrInsert(['vendor_id' => $vendor->id, 'product_id' => $id], ['stock_quantity' => array_key_exists('stock_quantity', $input) ? $input['stock_quantity'] : ($offer?->stock_quantity), 'price_paise' => isset($input['price']) ? (int) round((float) $input['price'] * 100) : ($offer?->price_paise ?? 0), 'available' => (bool) $input['available'], 'revision' => ($offer?->revision ?? 0) + 1, 'created_at' => $offer?->created_at ?? now(), 'updated_at' => now()]);
        });

        return back()->with('status', 'Your price and availability have been saved.');
    }
}
