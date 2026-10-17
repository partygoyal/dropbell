<?php

use App\Http\Controllers\CustomerAftercareController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Middleware\AuthenticateCustomer;
use App\Services\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('SELECT 1');

    return ['status' => 'ok', 'service' => 'dropbell-api', 'database' => 'connected'];
});

Route::get('/products', function (Request $request) {
    $input = $request->validate(['category' => 'nullable|string|max:80', 'q' => 'nullable|string|max:120', 'postal_code' => ['nullable', 'regex:/^[1-9]\d{5}$/']]);
    $query = Marketplace::publicProducts($input['postal_code'] ?? null);
    if (! empty($input['category'])) {
        $query->where('products.category', $input['category']);
    }
    if (! empty($input['q'])) {
        $query->where('products.name', 'like', '%'.$input['q'].'%');
    }

    return response()->json(['categories' => DB::table('categories')->where('active', true)->orderBy('id')->get(['id', 'name']), 'sub_categories' => DB::table('sub_categories')->where('active', true)->whereIn('category_id', DB::table('categories')->where('active', true)->select('id'))->orderBy('name')->get(['id', 'name', 'category_id']), 'data' => $query->orderBy('products.id')->get()->map(fn (object $product): array => Marketplace::present($product))]);
});
Route::get('/products/{id}', function (Request $request, int $id) {
    $input = $request->validate(['postal_code' => ['nullable', 'regex:/^[1-9]\d{5}$/']]);
    $product = Marketplace::publicProducts($input['postal_code'] ?? null)->where('products.id', $id)->first();
    abort_unless($product, 404);

    return response()->json(['data' => Marketplace::present($product)]);
})->whereNumber('id');

Route::post('/customer/auth/request', [CustomerController::class, 'requestOtp'])->middleware('throttle:10,1');
Route::post('/customer/auth/verify', [CustomerController::class, 'verifyOtp'])->middleware('throttle:15,1');
Route::prefix('customer')->middleware([AuthenticateCustomer::class, 'throttle:120,1'])->group(function () {
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
