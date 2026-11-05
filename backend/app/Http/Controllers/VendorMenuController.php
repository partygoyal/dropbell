<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorMenuController extends Controller
{
    private function offer(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('vendor_products')->where('vendor_id', $request->attributes->get('vendor')->id)->where('product_id', $id);
        $offer = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($offer, 404);

        return $offer;
    }

    public function edit(Request $request, int $id): View
    {
        return view('vendor.menu-item', ['vendor' => $request->attributes->get('vendor'), 'offer' => $this->offer($request, $id), 'product' => DB::table('products')->find($id)]);
    }

    public function save(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['revision' => 'required|integer|min:0', 'dietary_label' => 'required|in:unspecified,vegetarian,vegan,non_vegetarian', 'allergens' => 'nullable|string|max:500', 'groups' => 'nullable|array|max:3', 'groups.*.name' => 'nullable|string|max:60', 'groups.*.required' => 'sometimes|boolean', 'groups.*.multiple' => 'sometimes|boolean', 'groups.*.choices' => 'nullable|array|max:6', 'groups.*.choices.*.name' => 'nullable|string|max:60', 'groups.*.choices.*.price' => 'nullable|numeric|min:0|max:10000|decimal:0,2']);
        $groups = [];
        foreach ($input['groups'] ?? [] as $groupIndex => $group) {
            if (! trim($group['name'] ?? '')) {
                continue;
            }
            if (collect($groups)->contains('name', trim($group['name']))) {
                throw ValidationException::withMessages(['groups' => 'Use a different name for each option group.']);
            }
            $choices = [];
            foreach ($group['choices'] ?? [] as $choiceIndex => $choice) {
                if (trim($choice['name'] ?? '') !== '') {
                    $choices[] = ['id' => 'g'.$groupIndex.'-o'.$choiceIndex, 'name' => trim($choice['name']), 'price_paise' => (int) round(((float) ($choice['price'] ?? 0)) * 100)];
                }
            }
            if (! $choices) {
                throw ValidationException::withMessages(['groups' => 'Every named option group needs at least one choice.']);
            }
            $groups[] = ['name' => trim($group['name']), 'min' => ! empty($group['required']) ? 1 : 0, 'max' => ! empty($group['multiple']) ? count($choices) : 1, 'choices' => $choices];
        }
        DB::transaction(function () use ($request, $id, $input, $groups): void {
            $offer = $this->offer($request, $id, true);
            abort_if($request->attributes->get('vendor')->status === 'suspended', 403);
            abort_unless($offer->revision === (int) $input['revision'], 409, 'The menu changed. Refresh before saving.');
            DB::table('vendor_products')->where('id', $offer->id)->update(['option_groups' => json_encode($groups), 'dietary_label' => $input['dietary_label'], 'allergens' => json_encode(array_values(array_filter(array_map('trim', explode(',', $input['allergens'] ?? ''))))), 'revision' => $offer->revision + 1, 'menu_revision' => $offer->menu_revision + 1, 'updated_at' => now()]);
        });

        return back()->with('status', 'Dish choices and dietary information saved.');
    }
}
