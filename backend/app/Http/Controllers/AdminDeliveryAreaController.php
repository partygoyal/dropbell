<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDeliveryAreaController extends Controller
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
        $area = $id ? DB::table('delivery_areas')->find($id) : null;
        if ($id) {
            abort_unless($area, 404);
        }

        return view('admin.delivery-areas', ['area' => $area, 'areas' => $id ? null : DB::table('delivery_areas')->orderBy('postal_code')->paginate(20)]);
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
        $money = ['numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'];
        $input = $request->validate(['name' => 'required|string|max:120', 'postal_code' => ['required', 'regex:/^[1-9]\d{5}$/', Rule::unique('delivery_areas')->ignore($id)], 'fee' => ['required', ...$money], 'minimum' => ['required', ...$money], 'free_above' => ['nullable', ...$money, 'gte:minimum'], 'active' => 'required|boolean']);
        $row = ['name' => $input['name'], 'postal_code' => $input['postal_code'], 'fee_paise' => (int) round((float) $input['fee'] * 100), 'minimum_paise' => (int) round((float) $input['minimum'] * 100), 'free_above_paise' => isset($input['free_above']) ? (int) round((float) $input['free_above'] * 100) : null, 'active' => $input['active'], 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('delivery_areas')->where('id', $id)->exists(), 404);
            DB::table('delivery_areas')->where('id', $id)->update($row);
        } else {
            DB::table('delivery_areas')->insert($row + ['created_at' => now()]);
        }

        return redirect()->route('admin.delivery-areas.index')->with('status', 'Delivery area saved. New orders use these settings; existing orders keep their delivery rules.');
    }
}
