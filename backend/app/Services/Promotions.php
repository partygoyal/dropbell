<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Promotions
{
    public static function active(): Builder
    {
        return DB::table('promotions')->where('active', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public static function resolve(?string $code, bool $lock = false): ?array
    {
        if (! $code) {
            return null;
        }
        $query = self::active()->where('code', strtoupper(trim($code)))->whereIn('discount_type', ['percent', 'fixed']);
        $promotion = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $promotion) {
            throw ValidationException::withMessages(['coupon' => 'This coupon is invalid, inactive or expired.']);
        }

        return collect((array) $promotion)->only(['code', 'discount_type', 'discount_value', 'minimum_paise', 'maximum_discount_paise'])->all();
    }

    public static function discount(?array $rules, int $subtotal, bool $strict = true): int
    {
        if (! $rules) {
            return 0;
        }
        if ($subtotal < $rules['minimum_paise']) {
            if ($strict) {
                throw ValidationException::withMessages(['coupon' => $rules['code'].' requires an items subtotal of ₹'.number_format($rules['minimum_paise'] / 100, 2).'.']);
            }

            return 0;
        }
        $discount = $rules['discount_type'] === 'percent' ? (int) round($subtotal * $rules['discount_value'] / 100) : $rules['discount_value'];

        return min($subtotal, $discount, $rules['maximum_discount_paise'] ?? PHP_INT_MAX);
    }

    public static function orderRules(object $order): ?array
    {
        if ($order->coupon_rules) {
            return json_decode($order->coupon_rules, true);
        }

        return $order->coupon_code === 'DROP10' || $order->discount_paise > 0 ? ['code' => 'DROP10', 'discount_type' => 'percent', 'discount_value' => 10, 'minimum_paise' => 10000, 'maximum_discount_paise' => 5000] : null;
    }

    private static function bannerOffer(object $promotion): ?array
    {
        if ($promotion->discount_type === 'none') {
            return null;
        }
        $money = fn (int $paise): string => '₹'.number_format($paise / 100, $paise % 100 === 0 ? 0 : 2);

        return [
            'value' => $promotion->discount_type === 'percent' ? $promotion->discount_value.'%' : $money($promotion->discount_value),
            'label' => 'OFF',
            'kind' => $promotion->discount_type,
            'minimum' => $promotion->minimum_paise > 0 ? 'On items '.$money($promotion->minimum_paise).'+' : 'On your items subtotal',
            'maximum' => $promotion->maximum_discount_paise ? 'Save up to '.$money($promotion->maximum_discount_paise) : null,
        ];
    }

    public static function banners(): array
    {
        return self::active()->where('show_banner', true)->orderBy('sort_order')->orderByDesc('id')->limit(20)->get()->map(fn ($row) => ['id' => $row->id, 'banner_section' => $row->banner_section, 'title' => $row->title, 'description' => $row->description, 'code' => $row->code, 'offer' => self::bannerOffer($row), 'image_url' => $row->banner_path ? Storage::disk('public')->url($row->banner_path) : null, 'ends_at' => $row->ends_at])->all();
    }
}
