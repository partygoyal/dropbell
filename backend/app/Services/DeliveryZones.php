<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class DeliveryZones
{
    public static function enabled(): bool
    {
        return (bool) DB::table('delivery_settings')->where('id', 1)->value('per_km_enabled');
    }

    public static function matching(array $address, bool $lock = false): object
    {
        if (! isset($address['latitude'], $address['longitude'])) {
            throw ValidationException::withMessages(['address' => 'Set a delivery pin on your address to calculate delivery charges.']);
        }
        $query = DB::table('delivery_zones')->join('delivery_cities', 'delivery_cities.id', '=', 'delivery_zones.city_id')->where('delivery_zones.active', true)->where('delivery_cities.active', true)->select('delivery_zones.*', 'delivery_cities.name as city_name', 'delivery_cities.hub_latitude', 'delivery_cities.hub_longitude')->orderBy('radius_meters')->orderBy('delivery_zones.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        foreach ($query->get() as $zone) {
            if (self::meters((float) $zone->center_latitude, (float) $zone->center_longitude, (float) $address['latitude'], (float) $address['longitude']) <= $zone->radius_meters) {
                if (isset($address['city']) && mb_strtolower(trim($address['city'])) !== mb_strtolower(trim($zone->city_name))) {
                    throw ValidationException::withMessages(['address.city' => 'This delivery pin belongs to '.$zone->city_name.'. Update the city or move the pin.']);
                }

                return $zone;
            }
        }
        throw ValidationException::withMessages(['address' => 'This delivery pin is outside our active delivery zones.']);
    }

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
    }

    public static function rules(array $address, bool $lock = false): array
    {
        $zone = self::matching($address, $lock);
        $key = config('services.google_routes.key');
        abort_unless($key, 503, 'Road-distance delivery pricing is not configured yet. Please try again later.');
        $origin = ['latitude' => (float) $zone->hub_latitude, 'longitude' => (float) $zone->hub_longitude];
        $destination = ['latitude' => (float) $address['latitude'], 'longitude' => (float) $address['longitude']];
        $cacheKey = 'road-distance:'.hash('sha256', json_encode([$origin, $destination]));
        try {
            $meters = Cache::remember($cacheKey, 300, function () use ($key, $origin, $destination): int {
                $response = Http::timeout(10)->connectTimeout(5)->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters'])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => ['location' => ['latLng' => $origin]], 'destination' => ['location' => ['latLng' => $destination]], 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'computeAlternativeRoutes' => false,
                ]);
                $distance = $response->json('routes.0.distanceMeters');
                abort_unless($response->successful() && is_int($distance) && $distance >= 0 && $distance <= 2000000, 503, 'We could not calculate a road route for this address. Check the delivery pin and try again.');

                return $distance;
            });
        } catch (ConnectionException $exception) {
            abort(503, 'Road-distance service is temporarily unavailable. Please try again.');
        }
        $fee = (int) ceil($meters * (int) $zone->rate_paise_per_km / 1000);

        return ['mode' => 'per_km', 'id' => null, 'zone_id' => $zone->id, 'zone_revision' => $zone->revision, 'name' => $zone->name, 'city' => $zone->city_name, 'postal_code' => $address['pin'], 'distance_meters' => $meters, 'rate_paise_per_km' => $zone->rate_paise_per_km, 'hub' => $origin, 'destination' => $destination, 'fee_paise' => $fee, 'minimum_paise' => 0, 'free_above_paise' => null];
    }

    public static function eligible(Builder $partners, ?int $zoneId): Builder
    {
        if ($zoneId) {
            $partners->whereExists(fn ($query) => $query->selectRaw('1')->from('delivery_zone_partner')->whereColumn('delivery_zone_partner.delivery_partner_id', 'delivery_partners.id')->where('zone_id', $zoneId));
        }

        return $partners;
    }

    public static function assertPartner(?int $partnerId, ?int $zoneId): void
    {
        if ($partnerId && $zoneId && ! DB::table('delivery_zone_partner')->where('zone_id', $zoneId)->where('delivery_partner_id', $partnerId)->exists()) {
            throw ValidationException::withMessages(['delivery_partner_id' => 'Choose a delivery partner assigned to this order’s delivery zone.']);
        }
    }
}
