<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MenuChoices
{
    public static function resolve(object $offer, array $ids): array
    {
        $groups = json_decode($offer->option_groups ?? '[]', true) ?: [];
        $known = [];
        $selected = [];
        $extra = 0;
        foreach ($groups as $group) {
            $matches = [];
            foreach ($group['choices'] as $choice) {
                $known[] = $choice['id'];
                if (in_array($choice['id'], $ids, true)) {
                    $matches[] = ['id' => $choice['id'], 'group' => $group['name'], 'name' => $choice['name'], 'price_paise' => $choice['price_paise']];
                }
            }
            if (count($matches) < $group['min'] || count($matches) > $group['max']) {
                throw ValidationException::withMessages(['items' => 'Choose '.$group['name'].' options within the menu limits.']);
            }
            foreach ($matches as $choice) {
                $selected[] = $choice;
                $extra += $choice['price_paise'];
            }
        }
        if (array_diff($ids, $known) || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'A selected dish option is no longer available. Review the menu.']);
        }

        return ['choices' => $selected, 'extra' => $extra];
    }
}
