<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class VendorAuthController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        return auth()->user()?->is_vendor ? redirect()->route('vendor.dashboard') : view('vendor.auth', ['register' => false]);
    }

    public function registerForm(): View|RedirectResponse
    {
        return auth()->user()?->is_vendor ? redirect()->route('vendor.dashboard') : view('vendor.auth', ['register' => true]);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_if($request->user(), 403, 'Sign out of your current workspace before registering.');
        $input = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'phone' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'], 'password' => 'required|string|min:10|max:72|confirmed']);
        $user = DB::transaction(function () use ($input) {
            $user = User::create(collect($input)->only(['name', 'email', 'phone', 'password'])->all());
            $user->is_vendor = true;
            $user->save();
            DB::table('vendors')->insert(['user_id' => $user->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('vendor.onboarding')->with('status', 'Your vendor account is ready. Complete your business details for approval.');
    }

    public function login(Request $request): RedirectResponse
    {
        $input = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:72']);
        $key = 'vendor-login:'.Str::lower($input['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Please try again shortly.'])->onlyInput('email');
        }
        if (! Auth::attempt($input + ['is_vendor' => true, 'is_admin' => false])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'These vendor credentials do not match our records.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('vendor.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.login');
    }
}
