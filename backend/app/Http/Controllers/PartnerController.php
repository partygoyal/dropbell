<?php

namespace App\Http\Controllers;

use App\Services\AppNotifications;
use App\Services\OrderAftercare;
use App\Services\ServiceLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PartnerController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $input = $request->validate(['phone' => ['required', 'regex:/^[6-9]\d{9}$/'], 'password' => 'required|string|max:72']);
        $key = 'partner-password:'.$input['phone'];
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many sign-in attempts. Try again in a few minutes.');
        RateLimiter::hit($key, 300);
        $token = DB::transaction(function () use ($input): ?string {
            $partner = DB::table('delivery_partners')->where('phone', $input['phone'])->lockForUpdate()->first();
            if (strlen($input['password']) > 72 || ! $partner || ! $partner->password || ! Hash::check($input['password'], $partner->password) || ! $partner->active || ! $this->approved($partner->id)) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('partner_tokens')->insert(['delivery_partner_id' => $partner->id, 'phone' => $partner->phone, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return $token;
        }, 3);
        if (! $token) {
            return response()->json(['message' => 'Could not sign in. Check your mobile number and password, or contact your admin to confirm account access and KYC approval.'], 422)->header('Cache-Control', 'private, no-store');
        }
        RateLimiter::clear($key);

        return response()->json(['token' => $token])->header('Cache-Control', 'private, no-store');
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['phone' => ['required', 'regex:/^[6-9]\d{9}$/']]);
        abort_unless(app()->environment('local') && config('partner.local_otp'), 503, 'Rider SMS sign-in is not configured.');
        $key = 'partner-otp:'.$input['phone'];
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Please wait before requesting another code.');
        RateLimiter::hit($key, 300);
        $partner = DB::table('delivery_partners')->where('phone', $input['phone'])->where('active', true)->first();
        abort_unless($partner && $this->approved($partner->id), 403, 'Contact your admin for an active account and approved KYC.');
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        DB::transaction(function () use ($partner, $id, $code) {
            DB::table('delivery_partners')->where('id', $partner->id)->lockForUpdate()->first();
            DB::table('partner_otps')->where('delivery_partner_id', $partner->id)->delete();
            DB::table('partner_otps')->insert(['id' => $id, 'delivery_partner_id' => $partner->id, 'phone' => $partner->phone, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['challenge_id' => $id, 'local_code' => $code, 'message' => 'Local development only. No SMS was sent.'])->header('Cache-Control', 'private, no-store');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['challenge_id' => 'required|uuid', 'code' => 'required|digits:6']);
        abort_unless(app()->environment('local') && config('partner.local_otp'), 503, 'Rider SMS sign-in is not configured.');
        $token = DB::transaction(function () use ($input) {
            $challenge = DB::table('partner_otps')->where('id', $input['challenge_id'])->lockForUpdate()->first();
            if (! $challenge || $challenge->expires_at <= now() || $challenge->attempts >= 5) {
                return null;
            }
            if (! Hash::check($input['code'], $challenge->code_hash)) {
                DB::table('partner_otps')->where('id', $challenge->id)->increment('attempts');

                return null;
            }
            DB::table('partner_otps')->where('id', $challenge->id)->delete();
            $partner = DB::table('delivery_partners')->where('id', $challenge->delivery_partner_id)->where('phone', $challenge->phone)->where('active', true)->lockForUpdate()->first();
            if (! $partner || ! $this->approved($partner->id)) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('partner_tokens')->insert(['delivery_partner_id' => $partner->id, 'phone' => $partner->phone, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return $token;
        });

        return response()->json($token ? ['token' => $token] : ['message' => 'Incorrect, expired or used code. Request another code.'], $token ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    private function approved(int $id): bool
    {
        return DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->where('status', 'approved')->exists();
    }

    public function profile(Request $request): JsonResponse
    {
        $partner = DB::table('delivery_partners')->find($request->attributes->get('partner')->id);

        return response()->json(['data' => ['locations' => ServiceLocations::query()->whereIn('l.id', DB::table('service_locality_partner')->where('delivery_partner_id', $partner->id)->select('locality_id'))->select('l.id', 'l.name', 'c.name as city', 's.name as state')->get(), 'id' => $partner->id, 'name' => $partner->name, 'phone' => $partner->phone, 'vehicle' => $partner->vehicle, 'is_online' => (bool) $partner->is_online, 'kyc_status' => 'approved']]);
    }

    public function availability(Request $request): JsonResponse
    {
        $input = $request->validate(['is_online' => 'required|boolean']);
        DB::table('delivery_partners')->where('id', $request->attributes->get('partner')->id)->update($input + ['updated_at' => now()]);

        return $this->profile($request);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            DB::table('delivery_partners')->where('id', $request->attributes->get('partner')->id)->lockForUpdate()->update(['is_online' => false, 'updated_at' => now()]);
            DB::table('app_devices')->where('owner_type', 'partner')->where('session_id', $request->attributes->get('partner_token_id'))->delete();
            DB::table('partner_tokens')->where('id', $request->attributes->get('partner_token_id'))->delete();
        });

        return response()->json(['message' => 'Signed out.']);
    }

    public function orders(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1', 'scope' => 'sometimes|in:active,history,all']);
        $query = DB::table('orders')->where('delivery_partner_id', $request->attributes->get('partner')->id)->where('is_sample', false);
        if (($input['scope'] ?? 'all') === 'active') {
            $query->whereIn('status', ['received', 'picking', 'out_for_delivery']);
        } elseif (($input['scope'] ?? 'all') === 'history') {
            $query->whereIn('status', ['delivered', 'cancelled']);
        }
        $orders = $query->orderByRaw("CASE WHEN status IN ('received', 'picking', 'out_for_delivery') THEN 0 ELSE 1 END")->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => $this->present($order)), 'next_page' => $orders->hasMorePages() ? $orders->currentPage() + 1 : null]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->present($this->owned($request, $reference))]);
    }

    private function owned(Request $request, string $reference, bool $lock = false): object
    {
        $query = DB::table('orders')->where('reference', $reference)->where('delivery_partner_id', $request->attributes->get('partner')->id)->where('is_sample', false);
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($order, 404);

        return $order;
    }

    private function present(object $order): array
    {
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $stops = $items->groupBy(fn ($item) => $item->vendor_id ? 'vendor-'.$item->vendor_id : 'hub')->map(function ($items, $key) {
            $first = $items->first();
            $requiresPreparation = $items->contains(fn ($item) => $item->vendor_accepted_at !== null) || DB::table('products')->whereIn('id', $items->pluck('product_id'))->whereIn('category', DB::table('categories')->where('shopping_section', 'food')->select('name'))->exists();
            $vendorPhone = $first->vendor_id ? DB::table('vendors')->join('users', 'users.id', '=', 'vendors.user_id')->where('vendors.id', $first->vendor_id)->value('users.phone') : null;

            return ['requires_preparation' => $requiresPreparation, 'pickup_ready' => ! $requiresPreparation || $items->every(fn ($item) => $item->vendor_ready_at !== null), 'preparation_status' => $items->every(fn ($item) => $item->vendor_ready_at !== null) ? 'ready' : ($items->every(fn ($item) => $item->vendor_accepted_at !== null) ? 'preparing' : 'awaiting_vendor'), 'id' => $key, 'name' => $first->vendor_name ?: 'Dropbell pickup hub', 'address' => $first->pickup_address ? json_decode($first->pickup_address, true) : null, 'phone' => $vendorPhone, 'picked_up' => $items->every(fn ($item) => $item->picked_up_at !== null), 'items' => $items->map(fn ($item) => ['id' => $item->id, 'name' => $item->product_name, 'pack_size' => $item->pack_size, 'quantity' => $item->quantity, 'options' => json_decode($item->selected_options ?? '[]', true)])->values()];
        })->values();
        $payment = DB::table('order_transactions')->where('operation_key', 'payment:'.$order->id)->first();

        return ['vendor_review_required' => (bool) ($order->vendor_rejection_reason && DB::table('customer_requests')->where('order_id', $order->id)->where('type', 'cancellation')->where('status', 'requested')->exists()), 'reference' => $order->reference, 'revision' => $order->revision, 'status' => $order->status, 'address' => json_decode($order->delivery_address, true), 'cod_paise' => $order->payment_status === 'paid' ? 0 : $order->total_paise, 'total_paise' => $order->total_paise, 'receipt' => $payment?->reference, 'delivered_at' => $order->delivered_at ? Carbon::parse($order->delivered_at)->toIso8601String() : null, 'stops' => $stops];
    }

    public function pickup(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'stop_id' => 'required|string|max:40']);
        DB::transaction(function () use ($request, $reference, $input) {
            $order = $this->owned($request, $reference, true);
            $this->canWork($request, $order);
            $stops = collect($this->present($order)['stops']);
            $stop = $stops->firstWhere('id', $input['stop_id']);
            abort_unless($stop, 422, 'This pickup stop no longer exists. Refresh the order.');
            if ($stop['picked_up']) {
                return;
            }
            $this->currentRevision($order, $input['revision']);
            abort_unless($stops->firstWhere('picked_up', false)['id'] === $stop['id'], 409, 'Complete the current pickup first.');
            $this->dispatchChecks($order);
            abort_unless($stop['pickup_ready'], 409, 'The restaurant is still preparing this order. Refresh after it is marked ready.');
            DB::table('order_items')->where('order_id', $order->id)->whereIn('id', $stop['items']->pluck('id'))->update(['picked_up_at' => now(), 'updated_at' => now()]);
            $remaining = DB::table('order_items')->where('order_id', $order->id)->whereNull('picked_up_at')->exists();
            DB::table('orders')->where('id', $order->id)->update(['status' => $remaining ? 'picking' : 'out_for_delivery', 'revision' => $order->revision + 1, 'updated_at' => now()]);
            AppNotifications::status(DB::table('orders')->find($order->id));
            $this->event($request, $order, 'Pickup confirmed', ['stop_id' => $stop['id']]);
        }, 3);

        return $this->show($request, $reference);
    }

    public function complete(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'payment_collected' => 'required|boolean', 'receipt' => 'nullable|string|max:80']);
        DB::transaction(function () use ($request, $reference, $input) {
            $order = $this->owned($request, $reference, true);
            $receipt = trim($input['receipt'] ?? '');
            if ($order->status === 'delivered') {
                abort_unless(DB::table('partner_delivery_events')->where('order_id', $order->id)->where('delivery_partner_id', $request->attributes->get('partner')->id)->where('action', 'Delivery confirmed')->exists(), 409, 'This order was completed elsewhere. Refresh and contact your admin about payment.');
                $payment = DB::table('order_transactions')->where('operation_key', 'payment:'.$order->id)->first();
                abort_if($payment && $receipt !== '' && $payment->reference !== $receipt, 409, 'This delivery already has a different collection receipt.');

                return;
            }
            $this->canWork($request, $order);
            $this->currentRevision($order, $input['revision']);
            abort_unless($order->status === 'out_for_delivery' && DB::table('order_items')->where('order_id', $order->id)->exists() && ! DB::table('order_items')->where('order_id', $order->id)->whereNull('picked_up_at')->exists(), 409, 'Confirm all pickups before delivery.');
            if ($order->payment_status !== 'paid' && $order->total_paise > 0) {
                abort_unless($input['payment_collected'] && $receipt !== '', 422, 'Confirm COD collection and enter the receipt reference.');
                $payment = OrderAftercare::payment($order);
                abort_unless($payment->status === 'pending', 409, 'Payment has changed. Contact your admin.');
                DB::table('order_transactions')->where('id', $payment->id)->update(['status' => 'completed', 'amount_paise' => $order->total_paise, 'reference' => $receipt, 'updated_at' => now()]);
            }
            DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'payment_status' => 'paid', 'delivered_at' => now(), 'revision' => $order->revision + 1, 'updated_at' => now()]);
            AppNotifications::status(DB::table('orders')->find($order->id));
            $this->event($request, $order, 'Delivery confirmed', ['receipt' => $receipt, 'collected_paise' => $order->payment_status === 'paid' ? 0 : $order->total_paise]);
        }, 3);

        return $this->show($request, $reference);
    }

    private function currentRevision(object $order, int $revision): void
    {
        abort_unless((int) $order->revision === $revision, 409, 'This order changed. Refresh and review the latest details.');
    }

    private function canWork(Request $request, object $order): void
    {
        abort_if($order->vendor_rejection_reason && DB::table('customer_requests')->where('order_id', $order->id)->where('type', 'cancellation')->where('status', 'requested')->exists(), 409, 'A vendor rejected this order. Wait for admin review before pickup.');
        ServiceLocations::assertPartner($request->attributes->get('partner')->id, $order->locality_id);
        $identity = $request->attributes->get('partner');
        $partner = DB::table('delivery_partners')->where('id', $identity->id)->lockForUpdate()->first();
        abort_unless($partner && $partner->active && $partner->phone === $identity->phone && $this->approved($partner->id), 401, 'Your rider account changed. Sign in again.');
        abort_unless($partner->is_online, 409, 'Go online before updating a delivery.');
        abort_unless(in_array($order->status, ['received', 'picking', 'out_for_delivery'], true), 409, 'This order is no longer active.');
    }

    private function dispatchChecks(object $order): void
    {
        abort_if(DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')->where('order_id', $order->id)->whereNull('order_items.vendor_id')->where('products.expires_on', '<', today('Asia/Kolkata')->toDateString())->exists(), 409, 'An item has expired. Contact your admin.');
        abort_if(DB::table('order_items')->join('vendors', 'vendors.id', '=', 'order_items.vendor_id')->where('order_id', $order->id)->where('vendors.status', '!=', 'approved')->exists(), 409, 'A vendor is no longer approved. Contact your admin.');
    }

    private function event(Request $request, object $order, string $action, array $details): void
    {
        DB::table('partner_delivery_events')->insert(['order_id' => $order->id, 'delivery_partner_id' => $request->attributes->get('partner')->id, 'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
