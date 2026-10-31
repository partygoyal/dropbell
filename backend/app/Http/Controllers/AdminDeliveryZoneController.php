<?php

namespace App\Http\Controllers;

use App\Services\DeliveryZones;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDeliveryZoneController extends Controller
{
    public function index(): View
    {
        return $this->screen();
    }

    public function edit(int $id): View
    {
        return $this->screen($id);
    }

    private function screen(?int $id = null): View
    {
        $zone = $id ? DB::table('delivery_zones')->find($id) : null;
        abort_if($id && ! $zone, 404);

        return view('admin.delivery-zones', ['zone' => $zone, 'cities' => DB::table('delivery_cities')->orderBy('name')->get(), 'zones' => DB::table('delivery_zones')->join('delivery_cities', 'delivery_cities.id', '=', 'delivery_zones.city_id')->select('delivery_zones.*', 'delivery_cities.name as city_name')->orderBy('delivery_cities.name')->orderBy('delivery_zones.name')->get(), 'partners' => DB::table('delivery_partners')->orderBy('name')->get(), 'memberships' => DB::table('delivery_zone_partner')->get()->groupBy('zone_id'), 'enabled' => DeliveryZones::enabled(), 'routesConfigured' => (bool) config('services.google_routes.key'), 'pushConfigured' => (bool) config('services.firebase.credentials')]);
    }

    public function saveCity(Request $request, ?int $id = null): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('delivery_cities')->where('state', $request->input('state'))->ignore($id)], 'state' => 'required|string|max:120', 'hub_latitude' => 'required|numeric|between:-90,90', 'hub_longitude' => 'required|numeric|between:-180,180', 'active' => 'required|boolean']);
        $row = $input + ['updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('delivery_cities')->where('id', $id)->exists(), 404);
            DB::table('delivery_cities')->where('id', $id)->update($row);
        } else {
            DB::table('delivery_cities')->insert($row + ['created_at' => now()]);
        }

        return redirect()->route('admin.delivery-zones.index')->with('status', 'City saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->persist($request);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return $this->persist($request, $id);
    }

    private function persist(Request $request, ?int $id = null): RedirectResponse
    {
        $input = $request->validate(['city_id' => 'required|integer|exists:delivery_cities,id', 'name' => ['required', 'string', 'max:120', Rule::unique('delivery_zones')->where('city_id', $request->input('city_id'))->ignore($id)], 'center_latitude' => 'required|numeric|between:-90,90', 'center_longitude' => 'required|numeric|between:-180,180', 'radius_km' => 'required|numeric|between:0.1,100|decimal:0,3', 'rate' => ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'], 'active' => 'required|boolean', 'revision' => 'required|integer|min:0', 'partner_ids' => 'sometimes|array|max:500', 'partner_ids.*' => 'required|integer|distinct|exists:delivery_partners,id']);
        $rate = explode('.', (string) $input['rate']);
        DB::transaction(function () use ($input, $rate, $id): void {
            $current = $id ? DB::table('delivery_zones')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $current, 404);
            if ($current && $current->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'This zone changed. Reload before saving.']);
            }
            $row = collect($input)->only(['city_id', 'name', 'center_latitude', 'center_longitude', 'active'])->all() + ['radius_meters' => (int) round((float) $input['radius_km'] * 1000), 'rate_paise_per_km' => (int) $rate[0] * 100 + (int) str_pad($rate[1] ?? '', 2, '0'), 'revision' => ($current?->revision ?? -1) + 1, 'updated_at' => now()];
            if ($id) {
                DB::table('delivery_zones')->where('id', $id)->update($row);
                $zoneId = $id;
            } else {
                $zoneId = DB::table('delivery_zones')->insertGetId($row + ['created_at' => now()]);
            }
            DB::table('delivery_zone_partner')->where('zone_id', $zoneId)->delete();
            foreach ($input['partner_ids'] ?? [] as $partnerId) {
                DB::table('delivery_zone_partner')->insert(['zone_id' => $zoneId, 'delivery_partner_id' => $partnerId]);
            }
        }, 3);

        return redirect()->route('admin.delivery-zones.index')->with('status', 'Zone and rider assignments saved. Existing orders keep their accepted charges.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $input = $request->validate(['per_km_enabled' => 'required|boolean']);
        if ($input['per_km_enabled']) {
            if (! config('services.google_routes.key') || ! DB::table('delivery_zones')->join('delivery_cities', 'delivery_cities.id', '=', 'delivery_zones.city_id')->where('delivery_zones.active', true)->where('delivery_cities.active', true)->exists()) {
                throw ValidationException::withMessages(['per_km_enabled' => 'Configure Google Routes and at least one active city and zone before enabling per-km charges.']);
            }
        }
        DB::table('delivery_settings')->where('id', 1)->update($input + ['updated_at' => now()]);

        return redirect()->route('admin.delivery-zones.index')->with('status', 'Delivery pricing mode saved.');
    }
}
