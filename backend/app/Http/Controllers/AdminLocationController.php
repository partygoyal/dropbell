<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminLocationController extends Controller
{
    public function index(): View
    {
        return view('admin.locations', ['states' => DB::table('service_states')->orderBy('name')->get(), 'cities' => DB::table('service_cities')->orderBy('name')->get(), 'localities' => DB::table('service_localities')->orderBy('name')->get(), 'partners' => DB::table('delivery_partners')->orderBy('name')->get(), 'assignments' => DB::table('service_locality_partner')->get()->groupBy('locality_id')]);
    }

    public function save(Request $request, string $kind, ?int $id = null): RedirectResponse
    {
        abort_unless(in_array($kind, ['states', 'cities', 'localities'], true), 404);
        $table = 'service_'.$kind;
        if ($id) {
            abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
        }
        $rules = ['name' => ['required', 'string', 'max:120', Rule::unique($table)->ignore($id)->where(fn ($q) => $kind === 'cities' ? $q->where('state_id', $request->input('state_id')) : ($kind === 'localities' ? $q->where('city_id', $request->input('city_id')) : $q))], 'active' => 'sometimes|boolean'];
        if ($kind === 'cities') {
            $rules['state_id'] = 'required|integer|exists:service_states,id';
        }
        if ($kind === 'localities') {
            $rules += ['city_id' => 'required|integer|exists:service_cities,id', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/'], 'pricing_mode' => 'sometimes|in:flat,locality_per_km', 'fee' => 'nullable|required_unless:pricing_mode,locality_per_km|numeric|min:0|max:100000|decimal:0,2', 'distance_km' => 'nullable|required_if:pricing_mode,locality_per_km|numeric|min:0|max:500|decimal:0,3', 'rate' => 'nullable|required_if:pricing_mode,locality_per_km|numeric|min:0|max:100000|decimal:0,2', 'partner_ids' => 'sometimes|array', 'partner_ids.*' => 'integer|distinct|exists:delivery_partners,id'];
        }
        $input = $request->validate($rules);
        DB::transaction(function () use ($input, $kind, $table, $id) {
            $row = collect($input)->except(['partner_ids', 'fee', 'distance_km', 'rate'])->all();
            $row['active'] = (bool) ($input['active'] ?? false);
            $row['updated_at'] = now();
            if ($kind === 'localities') {
                if (($input['pricing_mode'] ?? 'flat') === 'locality_per_km') {
                    $row['rate_paise_per_km'] = (int) round((float) $input['rate'] * 100);
                    $row['distance_meters'] = (int) round((float) $input['distance_km'] * 1000);
                    $row['fee_paise'] = (int) ceil($row['rate_paise_per_km'] * $row['distance_meters'] / 1000);
                } else {
                    $row['fee_paise'] = (int) round((float) $input['fee'] * 100);
                }
            }
            $recordId = $id;
            if ($id) {
                $existing = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                if ($kind === 'cities') {
                    $row['state_id'] = $existing->state_id;
                } if ($kind === 'localities') {
                    $row['city_id'] = $existing->city_id;
                } DB::table($table)->where('id', $id)->update($row);
            } else {
                $recordId = DB::table($table)->insertGetId($row + ['created_at' => now()]);
            }
            if ($kind === 'localities') {
                DB::table('service_locality_partner')->where('locality_id', $recordId)->delete();
                foreach ($input['partner_ids'] ?? [] as $partnerId) {
                    DB::table('service_locality_partner')->insert(['locality_id' => $recordId, 'delivery_partner_id' => $partnerId]);
                }
            }
        });

        return redirect()->route('admin.locations')->with('success', 'Location saved.');
    }
}
