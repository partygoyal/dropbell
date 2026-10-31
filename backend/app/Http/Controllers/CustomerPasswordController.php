<?php

namespace App\Http\Controllers;

use App\Mail\CustomerPasswordReset;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CustomerPasswordController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => 'required|email|max:255']);
        $email = mb_strtolower(trim($input['email']));
        $base = rtrim((string) config('customer.web_url'), '/');
        $local = app()->environment('local') && config('mail.default') === 'log';
        abort_unless(filter_var($base, FILTER_VALIDATE_URL) && (str_starts_with($base, 'https://') || app()->environment('local', 'testing')), 503, 'Password recovery is not configured yet.');
        abort_if(! app()->environment('local', 'testing') && in_array(config('mail.default'), ['log', 'array'], true), 503, 'Password recovery email delivery is not configured yet.');
        $url = null;
        $user = User::where('email', $email)->where('is_admin', false)->where('is_vendor', false)->where('is_sample_guest', false)->first();
        if ($user) {
            Password::broker()->sendResetLink(['email' => $email, 'is_admin' => false, 'is_vendor' => false, 'is_sample_guest' => false], function (User $user, string $token) use ($base, &$url): void {
                $url = $base.'/reset-password?'.http_build_query(['email' => $user->email, 'token' => $token]);
                try {
                    Mail::to($user->email)->send(new CustomerPasswordReset($url));
                } catch (\Throwable $exception) {
                    Password::broker()->deleteToken($user);
                    report($exception);
                    abort(503, 'Password recovery is temporarily unavailable. Please try again later.');
                }
            });
        }

        return response()->json(['message' => 'If this email belongs to a customer account, a password reset link has been sent. The link expires in 60 minutes.', ...($local && $url ? ['local_reset_url' => $url] : [])])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $input = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'password' => 'required|string|min:8|max:255|confirmed']);
        $status = DB::transaction(function () use ($input): string {
            $user = User::where('email', $input['email'])->where('is_admin', false)->where('is_vendor', false)->where('is_sample_guest', false)->lockForUpdate()->first();
            if (! $user) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker()->reset($input + ['is_admin' => false, 'is_vendor' => false, 'is_sample_guest' => false], function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('app_devices')->where('owner_type', 'customer')->where('owner_id', $user->id)->delete();
                DB::table('customer_tokens')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid, expired or already used. Request a new link.'], 422);
        }

        return response()->json(['message' => 'Password updated. Sign in with your new password.'])->header('Cache-Control', 'no-store');
    }
}
