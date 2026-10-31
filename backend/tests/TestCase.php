<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function serviceLocality(string $pin = '560001'): int
    {
        $existing = DB::table('service_localities')->where('postal_code', $pin)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $state = DB::table('service_states')->insertGetId(['name' => 'State '.$pin, 'active' => true]);
        $city = DB::table('service_cities')->insertGetId(['state_id' => $state, 'name' => 'City '.$pin, 'active' => true]);
        $area = DB::table('delivery_areas')->where('postal_code', $pin)->first();

        return DB::table('service_localities')->insertGetId(['city_id' => $city, 'name' => $area->name ?? 'Locality '.$pin, 'postal_code' => $pin, 'fee_paise' => $area->fee_paise ?? 0, 'active' => true]);
    }

    protected function publishProduct(int $productId, ?int $price = null, array $pins = ['560001', '110001']): int
    {
        $vendorId = DB::table('vendors')->insertGetId([
            'user_id' => User::factory()->create(['is_vendor' => true])->id,
            'business_name' => 'Fixture vendor', 'pickup_address' => '10 Market Road', 'city' => 'Bengaluru', 'postal_code' => '560001',
            'service_postal_codes' => json_encode($pins), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('vendor_products')->insertGetId([
            'vendor_id' => $vendorId, 'product_id' => $productId,
            'price_paise' => $price ?? DB::table('products')->where('id', $productId)->value('price_paise'),
            'available' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
