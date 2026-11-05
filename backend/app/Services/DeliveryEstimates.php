<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveryEstimates
{
    public static function present(object $order): ?array
    {
        if (! in_array($order->status, ['received', 'picking', 'out_for_delivery'], true) || $order->is_sample) {
            return null;
        }
        if ($order->vendor_rejection_reason && DB::table('customer_requests')->where('order_id', $order->id)->where('type', 'cancellation')->where('status', 'requested')->exists()) {
            return null;
        }
        $address = json_decode($order->delivery_address, true);
        $rules = json_decode($order->delivery_rules ?? '{}', true);
        $location = DB::table('delivery_locations')->where('order_id', $order->id)->where('delivery_partner_id', $order->delivery_partner_id)->where('recorded_at', '>=', now()->subMinutes(2))->first();
        if ($order->status === 'out_for_delivery' && $location && isset($address['latitude'], $address['longitude'])) {
            $distance = self::distance((float) $location->latitude, (float) $location->longitude, (float) $address['latitude'], (float) $address['longitude']);
            $minutes = max(5, (int) ceil($distance / 1000 / 20 * 60) + 5);

            return ['arrives_at' => now()->addMinutes($minutes)->toISOString(), 'minutes' => $minutes, 'basis' => 'rider_distance', 'note' => 'Approximate arrival using straight-line distance and typical travel speed. Traffic and road routes may differ.'];
        }
        $hasDistance = ! empty($rules['distance_meters']);
        $travelMinutes = $hasDistance ? max(10, (int) ceil($rules['distance_meters'] / 1000 / 20 * 60) + 10) : 30;
        $items = DB::table('order_items')->where('order_id', $order->id)->get();
        $readyAt = $items->whereNull('vendor_ready_at')->max('estimated_ready_at');
        $prep = DB::table('vendors')->whereIn('id', $items->pluck('vendor_id'))->max('preparation_minutes') ?? 25;
        $start = $readyAt ? Carbon::parse($readyAt) : Carbon::parse($order->created_at)->addMinutes($order->status === 'out_for_delivery' ? 0 : $prep);
        $arrival = $start->addMinutes($travelMinutes);

        return ['arrives_at' => $arrival->toISOString(), 'minutes' => $arrival->isFuture() ? (int) ceil(now()->diffInMinutes($arrival)) : null, 'basis' => $hasDistance ? 'locality_distance' : 'preparation_allowance', 'note' => $arrival->isPast() ? 'The initial estimate has passed. Refresh for an updated delivery status.' : ($hasDistance ? 'Approximate arrival based on preparation and configured locality distance. Traffic may change this estimate.' : 'Approximate arrival using preparation time and a 30-minute delivery allowance, without live traffic data.')];
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
