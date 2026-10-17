<?php

use App\Http\Controllers\AdminAftercareController;
use App\Http\Controllers\AdminCategoryController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminDeliveryAreaController;
use App\Http\Controllers\AdminDeliveryPartnerController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminPartnerKycController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminSubCategoryController;
use App\Http\Controllers\AdminVendorController;
use App\Http\Controllers\VendorAuthController;
use App\Http\Controllers\VendorPortalController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureVendor;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:10,1');
Route::get('/vendor/login', [VendorAuthController::class, 'loginForm'])->name('vendor.login');
Route::post('/vendor/login', [VendorAuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/vendor/register', [VendorAuthController::class, 'registerForm'])->name('vendor.register');
Route::post('/vendor/register', [VendorAuthController::class, 'register'])->middleware('throttle:5,1');
Route::middleware(EnsureVendor::class)->group(function () {
    Route::get('/vendor', [VendorPortalController::class, 'dashboard'])->name('vendor.dashboard');
    Route::get('/vendor/onboarding', [VendorPortalController::class, 'onboarding'])->name('vendor.onboarding');
    Route::put('/vendor/onboarding', [VendorPortalController::class, 'saveOnboarding'])->name('vendor.onboarding.save');
    Route::get('/vendor/products', [VendorPortalController::class, 'products'])->name('vendor.products');
    Route::put('/vendor/products/{id}', [VendorPortalController::class, 'saveProduct'])->whereNumber('id')->name('vendor.products.save');
    Route::post('/vendor/logout', [VendorAuthController::class, 'logout'])->name('vendor.logout');
});
Route::middleware(EnsureAdmin::class)->group(function () {
    Route::get('/admin/requests', [AdminAftercareController::class, 'index'])->name('admin.requests.index');
    Route::get('/admin/requests/{id}', [AdminAftercareController::class, 'show'])->whereNumber('id')->name('admin.requests.show');
    Route::patch('/admin/requests/{id}', [AdminAftercareController::class, 'review'])->whereNumber('id')->name('admin.requests.review');
    Route::get('/admin/transactions', [AdminAftercareController::class, 'transactions'])->name('admin.transactions.index');
    Route::post('/admin/orders/{id}/payment', [AdminAftercareController::class, 'payment'])->whereNumber('id')->name('admin.orders.payment');
    Route::get('/admin/vendors', [AdminVendorController::class, 'index'])->name('admin.vendors.index');
    Route::get('/admin/vendors/{id}', [AdminVendorController::class, 'show'])->whereNumber('id')->name('admin.vendors.show');
    Route::patch('/admin/vendors/{id}', [AdminVendorController::class, 'review'])->whereNumber('id')->name('admin.vendors.review');
    Route::get('/admin/customers', [AdminCustomerController::class, 'index'])->name('admin.customers.index');
    Route::get('/admin/customers/{id}', [AdminCustomerController::class, 'show'])->whereNumber('id')->name('admin.customers.show');
    Route::get('/admin/delivery-partners/{id}/kyc', [AdminPartnerKycController::class, 'show'])->whereNumber('id')->name('admin.partner-kyc.show');
    Route::post('/admin/delivery-partners/{id}/kyc', [AdminPartnerKycController::class, 'store'])->whereNumber('id')->name('admin.partner-kyc.store');
    Route::patch('/admin/delivery-partners/{id}/kyc/review', [AdminPartnerKycController::class, 'review'])->whereNumber('id')->name('admin.partner-kyc.review');
    Route::get('/admin/delivery-partners/{id}/kyc/documents/{kind}', [AdminPartnerKycController::class, 'document'])->whereNumber('id')->whereIn('kind', ['identity', 'address', 'licence'])->name('admin.partner-kyc.document');
    Route::resource('/admin/delivery-areas', AdminDeliveryAreaController::class)->only(['index', 'store', 'edit', 'update'])->names('admin.delivery-areas');
    Route::resource('/admin/categories', AdminCategoryController::class)->only(['index', 'store', 'edit', 'update'])->names('admin.categories');
    Route::resource('/admin/sub-categories', AdminSubCategoryController::class)->only(['index', 'store', 'edit', 'update'])->names('admin.sub-categories');
    Route::resource('/admin/delivery-partners', AdminDeliveryPartnerController::class)->only(['index', 'store', 'edit', 'update'])->names('admin.delivery-partners');
    Route::get('/admin/orders/{id}/edit', [AdminOrderController::class, 'edit'])->whereNumber('id')->name('admin.orders.edit');
    Route::put('/admin/orders/{id}', [AdminOrderController::class, 'modify'])->whereNumber('id')->name('admin.orders.modify');
    Route::get('/admin/orders', [AdminOrderController::class, 'index'])->name('admin.orders.index');
    Route::get('/admin/orders/notifications', [AdminOrderController::class, 'notifications'])->name('admin.orders.notifications');
    Route::get('/admin/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id')->name('admin.orders.show');
    Route::patch('/admin/orders/{id}', [AdminOrderController::class, 'update'])->whereNumber('id')->name('admin.orders.update');
    Route::resource('/admin/products', AdminProductController::class)->except(['show', 'destroy'])->names('admin.products');
    Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');
});

Route::get('/', function () {
    return redirect()->route('admin.login');
});
