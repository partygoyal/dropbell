<?php

namespace App\Http\Controllers;

use App\Services\Marketplace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function loginForm(Request $request): View|RedirectResponse
    {
        if ($request->user()?->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $input = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        $key = 'admin-login:'.Str::lower($input['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'])->onlyInput('email');
        }
        if (! Auth::attempt([...$input, 'is_admin' => true])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'These admin credentials do not match our records.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'storefrontProducts' => Marketplace::publicProducts()->count(),
            'saleableProducts' => Marketplace::publicProducts()->whereNotNull('market_prices.selling_price')->count(),
            'sampleProducts' => Marketplace::publicProducts()->whereNull('market_prices.selling_price')->count(),
            'sampleUnits' => Marketplace::publicProducts()->whereNull('market_prices.selling_price')->sum('products.stock_quantity'),
            'vendorUnits' => Marketplace::offers()->whereIn('vendor_products.product_id', Marketplace::catalogue()->select('products.id'))->sum('vendor_products.stock_quantity'),
            'untrackedOffers' => Marketplace::offers()->whereIn('vendor_products.product_id', Marketplace::catalogue()->select('products.id'))->whereNull('vendor_products.stock_quantity')->count(),
            'vendorCount' => DB::table('vendors')->count(),
            'pendingVendors' => DB::table('vendors')->where('status', 'pending')->count(),
            'products' => DB::table('products')->count(),
            'activeProducts' => DB::table('products')->where('active', true)->count(),
            'sampleOrders' => DB::table('orders')->where('is_sample', true)->count(),
            'orders' => DB::table('orders')->count(),
            'pendingOrders' => DB::table('orders')->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'recentCustomers' => DB::table('users')->where('is_admin', false)->where('is_vendor', false)->where('is_sample_guest', false)->orderByDesc('id')->limit(8)->get(['id', 'name', 'phone', 'created_at']),
            'pendingKyc' => DB::table('delivery_partner_kycs')->where('status', 'pending')->count(),
            'customers' => DB::table('users')->where('is_admin', false)->where('is_vendor', false)->where('is_sample_guest', false)->count(),
            'sales' => DB::table('orders')->where('payment_status', 'paid')->where('is_sample', false)->sum('total_paise'),
            'recentOrders' => DB::table('orders')->latest()->limit(8)->get(),
            'catalogue' => DB::table('products')->leftJoinSub(Marketplace::publicProducts(), 'storefront_inventory', fn ($join) => $join->on('products.id', '=', 'storefront_inventory.id'))->select('products.*', 'storefront_inventory.selling_price', 'storefront_inventory.sample_preview', 'storefront_inventory.available_stock', 'storefront_inventory.untracked_offers')->orderByRaw('storefront_inventory.selling_price IS NULL')->orderBy('products.id')->limit(10)->get(),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
