<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile', ['admin' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['username' => Str::lower(trim((string) $request->input('username'))), 'email' => Str::lower(trim((string) $request->input('email')))]);
        $input = $request->validate([
            'name' => 'required|string|max:120',
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/', Rule::unique('users')->ignore($user->id)],
            'current_password' => 'required|current_password:web',
        ]);
        if ($user->email !== $input['email']) {
            $user->email_verified_at = null;
        }
        $user->name = $input['name'];
        $user->username = $input['username'];
        $user->email = $input['email'];
        $user->phone = $input['phone'] ?? null;
        $user->save();
        $request->session()->regenerate();

        return redirect()->route('admin.profile.edit')->with('status', 'Profile updated. You can sign in with your username or email.');
    }

    public function password(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'current_password' => 'required|current_password:web',
            'password' => 'required|string|min:12|max:72|confirmed|different:current_password',
        ]);
        if (strlen($input['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must be at most 72 bytes.']);
        }
        $user = $request->user();
        $user->password = $input['password'];
        $user->remember_token = Str::random(60);
        $user->save();
        Auth::logoutOtherDevices($input['password']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.profile.edit')->with('status', 'Password changed. Other admin sessions will need to sign in again.');
    }
}
