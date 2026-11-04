<?php

use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\CustomerAftercareController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPasswordController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\SupportController;
use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\AuthenticatePartner;
use App\Services\DeliveryZones;
use App\Services\Marketplace;
use App\Services\Promotions;
use App\Services\ServiceLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/partner/auth/login', [PartnerController::class, 'login'])->middleware('throttle:10,1,partner-password:');
Route::post('/partner/auth/request', [PartnerController::class, 'requestOtp'])->middleware('throttle:10,1,partner-otp:');
Route::post('/partner/auth/verify', [PartnerController::class, 'verifyOtp'])->middleware('throttle:15,1,partner-verify:');
Route::prefix('partner')->middleware([AuthenticatePartner::class, 'throttle:120,1'])->group(function () {
    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'read'])->whereNumber('id');
    Route::post('/notification-devices', [AppNotificationController::class, 'register']);
    Route::delete('/notification-devices/{installation}', [AppNotificationController::class, 'unregister'])->whereUuid('installation');

    Route::get('/me', [PartnerController::class, 'profile']);
    Route::patch('/availability', [PartnerController::class, 'availability']);
    Route::post('/logout', [PartnerController::class, 'logout']);
    Route::get('/orders', [PartnerController::class, 'orders']);
    Route::get('/orders/{reference}', [PartnerController::class, 'show']);
    Route::post('/orders/{reference}/pickup', [PartnerController::class, 'pickup']);
    Route::post('/orders/{reference}/complete', [PartnerController::class, 'complete']);
});

Route::get('/health', function () {
    DB::select('SELECT 1');

    return ['status' => 'ok', 'service' => 'dropbell-api', 'database' => 'connected'];
});

Route::get('/products', function (Request $request) {
    $input = $request->validate(['section' => 'nullable|in:food,groceries', 'category' => 'nullable|string|max:80', 'q' => 'nullable|string|max:120', 'postal_code' => ['nullable', 'regex:/^[1-9]\d{5}$/'], 'locality_id' => 'nullable|integer', 'vendor_id' => 'nullable|integer|min:1']);
    if (! empty($input['locality_id'])) {
        $input['postal_code'] = ServiceLocations::address(['locality_id' => $input['locality_id']])['pin'];
    }
    $query = Marketplace::publicProducts($input['postal_code'] ?? null, isset($input['vendor_id']) ? (int) $input['vendor_id'] : null);
    if (! empty($input['section'])) {
        $query->whereIn('products.category', DB::table('categories')->where('shopping_section', $input['section'])->select('name'));
    }
    if (! empty($input['category'])) {
        $query->where('products.category', $input['category']);
    }
    if (! empty($input['q'])) {
        $query->where('products.name', 'like', '%'.$input['q'].'%');
    }

    $categories = DB::table('categories')->where('active', true)->when($input['section'] ?? null, fn ($q, $section) => $q->where('shopping_section', $section))->orderBy('id')->get(['id', 'name', 'shopping_section', 'banner_path'])->map(fn ($category) => (object) ['id' => $category->id, 'name' => $category->name, 'shopping_section' => $category->shopping_section, 'image_url' => $category->banner_path ? asset('storage/'.$category->banner_path) : null]);
    $sections = $categories->pluck('shopping_section', 'name');
    $vendorSections = Marketplace::offers($input['postal_code'] ?? null)->whereIn('vendor_products.product_id', Marketplace::catalogue()->select('products.id'))->join('products', 'products.id', '=', 'vendor_products.product_id')->join('categories', 'categories.name', '=', 'products.category')->select('vendor_products.vendor_id', 'categories.shopping_section')->selectRaw('COUNT(DISTINCT vendor_products.product_id) as product_count, MIN(products.id) as first_product_id')->groupBy('vendor_products.vendor_id', 'categories.shopping_section')->get()->groupBy('vendor_id');
    $vendorImages = DB::table('products')->whereIn('id', $vendorSections->flatten(1)->pluck('first_product_id'))->pluck('image_path', 'id');
    $vendorCuisines = Marketplace::offers($input['postal_code'] ?? null)->whereIn('vendor_products.product_id', Marketplace::catalogue()->select('products.id'))->join('products', 'products.id', '=', 'vendor_products.product_id')->join('categories', 'categories.name', '=', 'products.category')->where('categories.shopping_section', 'food')->select('vendor_products.vendor_id', 'categories.name')->distinct()->get()->groupBy('vendor_id');
    $vendors = Marketplace::publicVendors($input['postal_code'] ?? null)->get()->map(fn ($vendor) => (array) $vendor + ['image_url' => ($path = $vendorImages->get(($vendorSections->get($vendor->id, collect())->firstWhere('shopping_section', 'food') ?? $vendorSections->get($vendor->id, collect())->first())?->first_product_id)) ? asset('storage/'.$path) : null, 'cuisines' => $vendorCuisines->get($vendor->id, collect())->pluck('name')->values()->all(), 'sections' => $vendorSections->get($vendor->id, collect())->pluck('shopping_section')->unique()->values()->all(), 'section_counts' => $vendorSections->get($vendor->id, collect())->pluck('product_count', 'shopping_section')->all()])->filter(fn ($vendor) => empty($input['section']) || in_array($input['section'], $vendor['sections'], true))->values();

    return response()->json(['vendors' => $vendors, 'categories' => $categories, 'sub_categories' => DB::table('sub_categories')->where('active', true)->whereIn('category_id', $categories->pluck('id'))->orderBy('name')->get(['id', 'name', 'category_id']), 'data' => $query->orderBy('products.id')->get()->map(fn (object $product): array => Marketplace::present($product) + ['shopping_section' => $sections[$product->category] ?? 'groceries'])]);
});
Route::get('/products/{id}', function (Request $request, int $id) {
    $input = $request->validate(['postal_code' => ['nullable', 'regex:/^[1-9]\d{5}$/'], 'locality_id' => 'nullable|integer', 'vendor_id' => 'nullable|integer|min:1']);
    if (! empty($input['locality_id'])) {
        $input['postal_code'] = ServiceLocations::address(['locality_id' => $input['locality_id']])['pin'];
    }
    $product = Marketplace::publicProducts($input['postal_code'] ?? null, isset($input['vendor_id']) ? (int) $input['vendor_id'] : null)->where('products.id', $id)->first();
    abort_unless($product, 404);

    return response()->json(['data' => Marketplace::present($product)]);
})->whereNumber('id');

Route::post('/customer/sample-quote', [CustomerOrderController::class, 'sampleQuote'])->middleware('throttle:30,1,sample-quote:');
Route::post('/customer/sample-orders', [CustomerOrderController::class, 'storeSample'])->middleware('throttle:10,1,sample-orders:');
Route::post('/customer/auth/demo/request', [CustomerController::class, 'requestDemoOtp'])->middleware('throttle:20,1,demo-otp-request:');
Route::post('/customer/auth/demo/verify', [CustomerController::class, 'verifyDemoOtp'])->middleware('throttle:30,1,demo-otp-verify:');
Route::get('/locations', fn () => response()->json(['data' => ServiceLocations::tree()]));
Route::post('/customer/auth/forgot-password', [CustomerPasswordController::class, 'forgot'])->middleware('throttle:5,1,customer-forgot:');
Route::post('/customer/auth/reset-password', [CustomerPasswordController::class, 'reset'])->middleware('throttle:10,1,customer-reset:');
Route::post('/customer/auth/login', [CustomerController::class, 'login'])->middleware('throttle:10,1,customer-login:');
Route::post('/customer/auth/register', [CustomerController::class, 'register'])->middleware('throttle:5,1,customer-register:');
Route::post('/customer/auth/request', [CustomerController::class, 'requestOtp'])->middleware('throttle:10,1,otp-request:');
Route::post('/customer/auth/verify', [CustomerController::class, 'verifyOtp'])->middleware('throttle:15,1,otp-verify:');
Route::prefix('customer')->middleware([AuthenticateCustomer::class, 'throttle:120,1'])->group(function () {
    Route::get('/support/tickets', [SupportController::class, 'index']);
    Route::post('/support/tickets', [SupportController::class, 'store']);
    Route::get('/support/tickets/{id}', [SupportController::class, 'show'])->whereNumber('id');
    Route::post('/support/tickets/{id}/messages', [SupportController::class, 'reply'])->whereNumber('id');
    Route::post('/support/tickets/{id}/read', [SupportController::class, 'read'])->whereNumber('id');
    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'read'])->whereNumber('id');
    Route::post('/notification-devices', [AppNotificationController::class, 'register']);
    Route::delete('/notification-devices/{installation}', [AppNotificationController::class, 'unregister'])->whereUuid('installation');

    Route::post('/quote', [CustomerOrderController::class, 'quote']);
    Route::get('/me', [CustomerController::class, 'profile']);
    Route::patch('/me', [CustomerController::class, 'updateProfile']);
    Route::post('/logout', [CustomerController::class, 'logout']);
    Route::post('/addresses', [CustomerController::class, 'saveAddress']);
    Route::put('/addresses/{id}', [CustomerController::class, 'saveAddress'])->whereNumber('id');
    Route::delete('/addresses/{id}', [CustomerController::class, 'deleteAddress'])->whereNumber('id');
    Route::get('/requests', [CustomerAftercareController::class, 'requests']);
    Route::get('/transactions', [CustomerAftercareController::class, 'transactions']);
    Route::post('/orders/{reference}/requests', [CustomerAftercareController::class, 'store']);
    Route::get('/orders/{reference}/invoice', [CustomerAftercareController::class, 'invoice']);
    Route::get('/orders', [CustomerOrderController::class, 'index']);
    Route::post('/orders', [CustomerOrderController::class, 'store']);
    Route::get('/orders/{reference}', [CustomerOrderController::class, 'show']);
});

Route::get('/delivery-options', fn () => response()->json(['per_km_enabled' => DeliveryZones::enabled(), 'cities' => DB::table('delivery_cities')->where('active', true)->orderBy('name')->get(['id', 'name', 'state'])]));

Route::get('/promotions', fn () => response()->json(['data' => Promotions::banners()])->header('Cache-Control', 'no-store'));

Route::post('/promotions/validate', function (Request $request) {
    $input = $request->validate(['code' => 'required|string|max:30', 'subtotal' => 'required|integer|min:0|max:100000000']);
    $rules = Promotions::resolve($input['code']);

    return response()->json(['data' => ['code' => $rules['code'], 'discount' => Promotions::discount($rules, $input['subtotal'])]])->header('Cache-Control', 'no-store');
})->middleware('throttle:60,1');
