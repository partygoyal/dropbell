<?php

namespace App\Http\Controllers;

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
            'vendorCount' => DB::table('vendors')->count(),
            'pendingVendors' => DB::table('vendors')->where('status', 'pending')->count(),
            'products' => DB::table('products')->count(),
            'activeProducts' => DB::table('products')->where('active', true)->count(),
            'orders' => DB::table('orders')->count(),
            'pendingOrders' => DB::table('orders')->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'recentCustomers' => DB::table('users')->where('is_admin', false)->where('is_vendor', false)->orderByDesc('id')->limit(8)->get(['id', 'name', 'phone', 'created_at']),
            'pendingKyc' => DB::table('delivery_partner_kycs')->where('status', 'pending')->count(),
            'customers' => DB::table('users')->where('is_admin', false)->where('is_vendor', false)->count(),
            'sales' => DB::table('orders')->where('payment_status', 'paid')->sum('total_paise'),
            'recentOrders' => DB::table('orders')->latest()->limit(8)->get(),
            'catalogue' => DB::table('products')->orderBy('id')->limit(10)->get(),
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
