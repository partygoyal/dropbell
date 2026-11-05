<?php

namespace App\Http\Controllers;

use App\Services\DeliveryEstimates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveryTrackingController extends Controller
{
    public function start(Request $request, string $reference): JsonResponse
    {
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($request, $reference, $token): void {
            $order = DB::table('orders')->where('reference', $reference)->where('delivery_partner_id', $request->attributes->get('partner')->id)->where('is_sample', false)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->status === 'out_for_delivery', 409, 'Complete pickups before sharing location.');
            DB::table('orders')->where('id', $order->id)->update(['tracking_session_hash' => hash('sha256', $token)]);
            DB::table('delivery_locations')->where('order_id', $order->id)->delete();
        }, 3);

        return response()->json(['sharing_session' => $token])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['sharing_session' => 'required|string|size:64', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy_meters' => 'required|integer|min:0|max:200']);
        DB::transaction(function () use ($request, $reference, $input): void {
            $partner = $request->attributes->get('partner');
            $order = DB::table('orders')->where('reference', $reference)->where('delivery_partner_id', $partner->id)->where('is_sample', false)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->tracking_session_hash && hash_equals($order->tracking_session_hash, hash('sha256', $input['sharing_session'])), 409, 'Location sharing has stopped. Start a new sharing session.');
            unset($input['sharing_session']);
            abort_unless($order->status === 'out_for_delivery', 409, 'Location sharing is only available after all pickups and before delivery.');
            abort_unless(DB::table('delivery_partners')->where('id', $partner->id)->where('active', true)->where('is_online', true)->exists(), 409, 'Go online before sharing location.');
            DB::table('delivery_locations')->updateOrInsert(['order_id' => $order->id], $input + ['delivery_partner_id' => $partner->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return response()->json(['message' => 'Delivery location updated.']);
    }

    public function stop(Request $request, string $reference): JsonResponse
    {
        DB::transaction(function () use ($request, $reference): void {
            $partner = $request->attributes->get('partner');
            $order = DB::table('orders')->where('reference', $reference)->where('delivery_partner_id', $partner->id)->lockForUpdate()->first();
            abort_unless($order, 404);
            DB::table('orders')->where('id', $order->id)->update(['tracking_session_hash' => null]);
            DB::table('delivery_locations')->where('order_id', $order->id)->delete();
        }, 3);

        return response()->json(['message' => 'Location sharing stopped.']);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $order = DB::table('orders')->where('reference', $reference)->where('user_id', $request->user()->id)->where('is_sample', false)->first();
        abort_unless($order, 404);
        $location = $order->status === 'out_for_delivery' ? DB::table('delivery_locations')->where('order_id', $order->id)->where('delivery_partner_id', $order->delivery_partner_id)->whereExists(fn ($q) => $q->selectRaw('1')->from('delivery_partners')->whereColumn('delivery_partners.id', 'delivery_locations.delivery_partner_id')->where('active', true)->where('is_online', true))->first() : null;
        $fresh = $location && Carbon::parse($location->recorded_at)->greaterThanOrEqualTo(now()->subMinutes(2));

        return response()->json(['data' => ['status' => $order->status, 'sharing' => (bool) $fresh, 'last_updated_at' => $location ? Carbon::parse($location->recorded_at)->toISOString() : null, 'location' => $fresh ? ['latitude' => (float) $location->latitude, 'longitude' => (float) $location->longitude, 'accuracy_meters' => $location->accuracy_meters] : null, 'estimate' => DeliveryEstimates::present($order), 'message' => $fresh ? 'Delivery partner location updates while they share from the delivery screen.' : 'Live location is not currently available. Your order status remains available.']])->header('Cache-Control', 'private, no-store');
    }
}
