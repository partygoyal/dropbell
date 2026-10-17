<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryPricing
{
    public static function rules(string $postalCode, bool $lock = false): array
    {
        $query = DB::table('delivery_areas')->where('postal_code', $postalCode)->where('active', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $area = $query->first();
        if (! $area) {
            throw ValidationException::withMessages(['postal_code' => 'We do not deliver to this postal code yet. Choose a serviceable delivery address.']);
        }

        return ['id' => $area->id, 'name' => $area->name, 'postal_code' => $area->postal_code, 'fee_paise' => $area->fee_paise, 'minimum_paise' => $area->minimum_paise, 'free_above_paise' => $area->free_above_paise];
    }

    public static function fee(array $rules, int $subtotal): int
    {
        if ($subtotal < $rules['minimum_paise']) {
            throw ValidationException::withMessages(['items' => 'The minimum items subtotal for '.$rules['name'].' is ₹'.number_format($rules['minimum_paise'] / 100, 2).'. Add more items to continue.']);
        }

        return $rules['free_above_paise'] !== null && $subtotal >= $rules['free_above_paise'] ? 0 : $rules['fee_paise'];
    }
}
