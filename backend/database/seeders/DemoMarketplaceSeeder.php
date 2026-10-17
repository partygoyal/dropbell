<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Marketplace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Demo offers can only be seeded in the local environment.');
        }
        DB::transaction(function (): void {
            $user = User::where('email', 'vendor.demo@dropbell.local')->where('is_vendor', true)->where('is_admin', false)->first();
            if (! $user) {
                throw new RuntimeException('Create the local demo vendor account before seeding offers.');
            }
            $vendor = DB::table('vendors')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $vendor || ! in_array($vendor->status, ['draft', 'approved'], true)) {
                throw new RuntimeException('This demo vendor needs an admin review before offers can be seeded.');
            }
            DB::table('delivery_areas')->insertOrIgnore(['name' => 'Demo delivery area — Bengaluru', 'postal_code' => '560001', 'fee_paise' => 2500, 'minimum_paise' => 0, 'free_above_paise' => 30000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            if ($vendor->status === 'draft') {
                DB::table('vendors')->where('id', $vendor->id)->update([
                    'business_name' => 'Dropbell Demo Store', 'pickup_address' => 'Demo pickup address, Market Road', 'city' => 'Bengaluru', 'postal_code' => '560001',
                    'service_postal_codes' => json_encode(DB::table('delivery_areas')->where('active', true)->pluck('postal_code')->all()),
                    'status' => 'approved', 'revision' => $vendor->revision + 1,
                    'review_note' => 'Local demonstration account with sample products and prices.', 'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            }
            $prices = [
                'Fruits & Vegetables' => 6000, 'Dairy, Bread & Eggs' => 6500,
                'Atta, Rice & Pulses' => 12000, 'Oils, Spices & Cooking Essentials' => 11000,
                'Snacks & Namkeen' => 5000, 'Biscuits, Chocolates & Sweets' => 8000,
                'Breakfast & Spreads' => 15000, 'Instant & Packaged Foods' => 9000,
                'Sauces, Pickles & Condiments' => 11000, 'Beverages' => 7500,
                'Frozen Foods & Ice Cream' => 16000, 'Personal Care & Hygiene' => 12000,
                'Beauty & Grooming' => 19000, 'Cleaning & Household Essentials' => 13000,
                'Baby Care' => 25000, 'Pet Care' => 22000,
            ];
            $products = Marketplace::catalogue()->whereIn('products.category', array_keys($prices))->whereNotNull('products.sub_category_id')->where('products.care_instructions', 'Follow the storage and usage instructions on the product packaging.')->select('products.*')->get();
            foreach ($products as $product) {
                DB::table('vendor_products')->insertOrIgnore([
                    'vendor_id' => $vendor->id, 'product_id' => $product->id,
                    'price_paise' => $prices[$product->category] + ($product->id % 3) * 1000,
                    'available' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
