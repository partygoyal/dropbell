<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceLocations
{
    public static function query(): Builder
    {
        return DB::table('service_localities as l')->join('service_cities as c', 'c.id', '=', 'l.city_id')->join('service_states as s', 's.id', '=', 'c.state_id')->where('l.active', true)->where('c.active', true)->where('s.active', true)->where(fn ($q) => $q->where('l.pricing_mode', 'flat')->orWhereNotNull('l.distance_meters'))->select('l.*', 'c.name as city', 'c.state_id', 's.name as state');
    }

    public static function address(array $address, bool $lock = false): array
    {
        $query = self::query()->where('l.id', $address['locality_id'] ?? 0);
        if ($lock) {
            $query->lockForUpdate();
        }
        $locality = $query->first();
        if (! $locality) {
            throw ValidationException::withMessages(['locality_id' => 'Select an active delivery locality from State, City and Locality. We only deliver in listed areas.']);
        }
        foreach (['state_id' => $locality->state_id, 'city_id' => $locality->city_id] as $key => $expected) {
            if (isset($address[$key]) && (int) $address[$key] !== (int) $expected) {
                throw ValidationException::withMessages([$key => 'The selected location does not belong to this State and City.']);
            }
        }

        return array_merge($address, ['locality_id' => $locality->id, 'state_id' => $locality->state_id, 'city_id' => $locality->city_id, 'state' => $locality->state, 'city' => $locality->city, 'locality' => $locality->name, 'pin' => $locality->postal_code, 'latitude' => null, 'longitude' => null]);
    }

    public static function rules(array $address, bool $lock = false): array
    {
        $canonical = self::address($address, $lock);
        $area = self::query()->where('l.id', $canonical['locality_id'])->first();

        if ($area->pricing_mode === 'locality_per_km') {
            if ($area->distance_meters === null) {
                throw ValidationException::withMessages(['locality_id' => 'The delivery distance for this locality is not configured yet.']);
            }

            return ['mode' => 'locality_per_km', 'locality_id' => $area->id, 'id' => null, 'name' => $area->name, 'city' => $area->city, 'state' => $area->state, 'postal_code' => $area->postal_code, 'distance_meters' => (int) $area->distance_meters, 'rate_paise_per_km' => (int) $area->rate_paise_per_km, 'fee_paise' => (int) ceil($area->distance_meters * $area->rate_paise_per_km / 1000), 'minimum_paise' => 0, 'free_above_paise' => null];
        }

        return ['mode' => 'locality', 'locality_id' => $area->id, 'id' => null, 'name' => $area->name, 'city' => $area->city, 'state' => $area->state, 'postal_code' => $area->postal_code, 'fee_paise' => $area->fee_paise, 'minimum_paise' => 0, 'free_above_paise' => null];
    }

    public static function eligible(Builder $query, ?int $localityId): Builder
    {
        if ($localityId) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('service_locality_partner')->whereColumn('service_locality_partner.delivery_partner_id', 'delivery_partners.id')->where('locality_id', $localityId));
        }

        return $query;
    }

    public static function assertPartner(?int $partnerId, ?int $localityId): void
    {
        if ($partnerId && $localityId && ! DB::table('service_locality_partner')->where('locality_id', $localityId)->where('delivery_partner_id', $partnerId)->exists()) {
            throw ValidationException::withMessages(['delivery_partner_id' => 'Assign a delivery partner approved by admin for this locality.']);
        }
    }

    public static function tree(): array
    {
        $rows = self::query()->orderBy('s.name')->orderBy('c.name')->orderBy('l.name')->get();

        return $rows->groupBy('state_id')->map(fn ($states) => ['id' => $states->first()->state_id, 'name' => $states->first()->state, 'cities' => $states->groupBy('city_id')->map(fn ($cities) => ['id' => $cities->first()->city_id, 'name' => $cities->first()->city, 'localities' => $cities->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'postal_code' => $l->postal_code, 'fee_paise' => $l->fee_paise])->values()->all()])->values()->all()])->values()->all();
    }
}
