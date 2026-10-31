<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ServiceLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        $user = User::where('email', mb_strtolower(trim($input['email'])))->first();
        if (! $user || ! Hash::check($input['password'], $user->password) || $user->is_admin || $user->is_vendor || $user->is_sample_guest) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        return $this->session($user);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $input = $request->validate(['name' => 'required|string|max:60', 'email' => 'required|email|max:255|unique:users,email', 'phone' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'], 'password' => 'required|string|min:8|max:255|confirmed']);
        $user = User::create(collect($input)->only(['name', 'email', 'phone', 'password'])->all());

        return $this->session($user);
    }

    private function session(User $user): JsonResponse
    {
        $token = bin2hex(random_bytes(32));
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['token' => $token]);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['phone' => ['required', 'regex:/^[6-9]\d{9}$/']]);
        abort_unless(app()->environment('local') && config('customer.local_otp'), 503, 'SMS sign-in is not configured.');
        $key = 'customer-otp:'.$input['phone'];
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait before requesting another code.');
        RateLimiter::hit($key, 300);
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        DB::transaction(function () use ($input, $id, $code) {
            DB::table('customer_otps')->where('phone', $input['phone'])->delete();
            DB::table('customer_otps')->insert(['id' => $id, 'phone' => $input['phone'], 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['challenge_id' => $id, 'local_code' => $code, 'message' => 'Local development code. No SMS was sent.']);
    }

    public function requestDemoOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['phone' => ['required', 'regex:/^[6-9]\d{9}$/']]);
        $key = 'customer-demo-otp:'.$input['phone'];
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Please wait '.RateLimiter::availableIn($key).' seconds before generating another temporary code.'], 429);
        }
        RateLimiter::hit($key, 300);
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        DB::table('customer_otps')->insert(['id' => $id, 'phone' => $input['phone'], 'is_demo' => true, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['challenge_id' => $id, 'demo_code' => $code, 'message' => 'Temporary demo code. Expires in 5 minutes. Your mobile number is not verified.'])->header('Cache-Control', 'private, no-store');
    }

    public function verifyDemoOtp(Request $request): JsonResponse
    {
        return $this->verifyOtp($request, true);
    }

    public function verifyOtp(Request $request, bool $demo = false): JsonResponse
    {
        $input = $request->validate(['challenge_id' => 'required|uuid', 'code' => 'required|digits:6']);
        $result = DB::transaction(function () use ($input, $demo) {
            $challenge = DB::table('customer_otps')->where('id', $input['challenge_id'])->lockForUpdate()->first();
            if (! $challenge || (bool) $challenge->is_demo !== $demo || $challenge->expires_at <= now() || $challenge->attempts >= 5) {
                return null;
            }
            if (! Hash::check($input['code'], $challenge->code_hash)) {
                DB::table('customer_otps')->where('id', $challenge->id)->increment('attempts');

                return null;
            }
            DB::table('customer_otps')->where('id', $challenge->id)->delete();
            if ($demo) {
                $user = User::create(['name' => 'Demo customer', 'email' => 'demo-'.$challenge->id.'@dropbell.invalid', 'password' => Str::random(64)]);
                $user->is_sample_guest = true;
                $user->save();
            } else {
                $user = User::firstOrCreate(['phone' => $challenge->phone], ['name' => 'Dropbell customer', 'email' => $challenge->phone.'@customer.dropbell.invalid', 'password' => Str::random(64)]);
            }
            if ($user->is_admin || $user->is_vendor) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $demo ? now()->addHours(6) : now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return $token;
        });

        return $result ? response()->json(['token' => $result]) : response()->json(['message' => 'The code is incorrect, expired, or used. Request a new code if needed.'], 422);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $addresses = DB::table('addresses')->where('user_id', $user->id)->orderByDesc('is_default')->get()->map(fn ($item) => ['locality_id' => $item->locality_id, 'state_id' => $item->locality_id ? DB::table('service_cities')->where('id', DB::table('service_localities')->where('id', $item->locality_id)->value('city_id'))->value('state_id') : null, 'city_id' => $item->locality_id ? DB::table('service_localities')->where('id', $item->locality_id)->value('city_id') : null, 'locality' => $item->locality_id ? DB::table('service_localities')->where('id', $item->locality_id)->value('name') : null, 'id' => (string) $item->id, 'label' => $item->label, 'name' => $item->name, 'phone' => $item->phone, 'street' => $item->street, 'city' => $item->city, 'pin' => $item->postal_code, 'isDefault' => (bool) $item->is_default, 'latitude' => $item->latitude === null ? null : (float) $item->latitude, 'longitude' => $item->longitude === null ? null : (float) $item->longitude]);

        return response()->json(['data' => ['email' => $user->email, 'isDemo' => (bool) $user->is_sample_guest, 'name' => $user->name, 'phone' => $user->phone, 'addresses' => $addresses]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:60']);
        $request->user()->update($input);

        return $this->profile($request);
    }

    public function saveAddress(Request $request, ?int $id = null): JsonResponse
    {
        $input = $request->validate(['label' => 'required|in:Home,Work,Other', 'name' => 'required|string|max:120', 'phone' => ['required', 'regex:/^[6-9]\d{9}$/'], 'street' => 'required|string|max:255', 'locality_id' => 'required|integer', 'state_id' => 'sometimes|integer', 'city_id' => 'sometimes|integer', 'city' => 'nullable|string|max:120', 'pin' => ['nullable', 'regex:/^[1-9]\d{5}$/'], 'isDefault' => 'sometimes|boolean', 'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90', 'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180']);
        $input = ServiceLocations::address($input);
        DB::transaction(function () use ($request, $input, $id) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $query = DB::table('addresses')->where('user_id', $request->user()->id);
            $existing = $id ? (clone $query)->where('id', $id)->first() : null;
            if ($id) {
                abort_unless($existing, 404);
            }
            $default = ($input['isDefault'] ?? false) || ! (clone $query)->exists() || ($existing?->is_default ?? false);
            if ($default) {
                (clone $query)->update(['is_default' => false]);
            }
            $row = collect($input)->only(['label', 'name', 'phone', 'street', 'city', 'locality_id', 'latitude', 'longitude'])->all() + ['postal_code' => $input['pin'], 'is_default' => $default, 'updated_at' => now()];
            if ($id) {
                (clone $query)->where('id', $id)->update($row);
            } else {
                DB::table('addresses')->insert($row + ['user_id' => $request->user()->id, 'created_at' => now()]);
            }
        });

        return $this->profile($request);
    }

    public function deleteAddress(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $query = DB::table('addresses')->where('user_id', $request->user()->id);
            $address = (clone $query)->where('id', $id)->first();
            abort_unless($address, 404);
            (clone $query)->where('id', $id)->delete();
            if ($address->is_default) {
                $next = (clone $query)->orderBy('id')->first();
                if ($next) {
                    (clone $query)->where('id', $next->id)->update(['is_default' => true]);
                }
            }
        });

        return $this->profile($request);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('app_devices')->where('owner_type', 'customer')->where('session_id', $request->attributes->get('customer_token_id'))->delete();
        DB::table('customer_tokens')->where('id', $request->attributes->get('customer_token_id'))->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
