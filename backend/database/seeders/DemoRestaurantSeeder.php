<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoRestaurantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo restaurant menus can only be added locally.');
        }
        $menus = json_decode(file_get_contents(database_path('data/demo-restaurant-menu.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($menus as $menu) {
            foreach ($menu['dishes'] as $dish) {
                $file = public_path('assets/food/demo-dishes/'.$dish['image_file']);
                if (! is_file($file) || ! hash_equals($dish['sha256'], hash_file('sha256', $file))) {
                    throw new RuntimeException('Missing or changed dish photograph: '.$dish['image_file']);
                }
            }
        }
        $this->call(FoodDiscoverySeeder::class);
        DB::transaction(function () use ($menus): void {
            DB::table('service_states')->insertOrIgnore(['name' => 'Uttarakhand', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $state = DB::table('service_states')->where('name', 'Uttarakhand')->first();
            DB::table('service_cities')->insertOrIgnore(['state_id' => $state->id, 'name' => 'Dehradun', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $city = DB::table('service_cities')->where('state_id', $state->id)->where('name', 'Dehradun')->first();
            if (! $state->active || ! $city->active) {
                throw new RuntimeException('Enable the local Dehradun delivery city before importing demo restaurants.');
            }
            DB::table('service_localities')->insertOrIgnore(['city_id' => $city->id, 'name' => 'Demo · Restaurant Test Area', 'postal_code' => '248001', 'pricing_mode' => 'flat', 'fee_paise' => 2500, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $locality = DB::table('service_localities')->where('city_id', $city->id)->where('name', 'Demo · Restaurant Test Area')->first();
            $pins = DB::table('service_localities')->where('city_id', $city->id)->where('active', true)->pluck('postal_code')->push('248001')->unique()->values()->all();
            $password = Hash::make('FoodDemo@1234');
            foreach ($menus as $menu) {
                $userId = $this->account($menu['email'], $menu['name'].' owner', $menu['phone'], true, $password);
                DB::table('vendors')->insertOrIgnore(['user_id' => $userId, 'business_name' => $menu['name'], 'pickup_address' => 'Local demo kitchen · Restaurant Test Area', 'city' => 'Dehradun', 'postal_code' => '248001', 'service_postal_codes' => json_encode($pins), 'status' => 'approved', 'review_note' => 'LOCAL DEMO RESTAURANT. Test dishes, prices and inventory; not a real business.', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $vendor = DB::table('vendors')->where('user_id', $userId)->first();
                foreach ($menu['dishes'] as $dish) {
                    $category = DB::table('categories')->where('name', $dish['category'])->where('shopping_section', 'food')->first();
                    if (! $category) {
                        throw new RuntimeException('The demo menu category must belong to Food.');
                    }
                    DB::table('sub_categories')->insertOrIgnore(['category_id' => $category->id, 'name' => $dish['subcategory'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    $subCategoryId = DB::table('sub_categories')->where('category_id', $category->id)->where('name', $dish['subcategory'])->value('id');
                    $path = 'food-demo-dishes/'.$dish['image_file'];
                    if (! Storage::disk('public')->exists($path) && ! Storage::disk('public')->put($path, file_get_contents(public_path('assets/food/demo-dishes/'.$dish['image_file'])))) {
                        throw new RuntimeException('Unable to save demo dish photograph.');
                    }
                    $identity = ['name' => $dish['name'], 'category' => $dish['category'], 'pack_size' => $dish['pack_size']];
                    $productId = DB::table('products')->where($identity)->value('id');
                    if (! $productId) {
                        $productId = DB::table('products')->insertGetId($identity + ['sub_category_id' => $subCategoryId, 'price_paise' => 0, 'stock_quantity' => 0, 'is_sample' => false, 'active' => true, 'image_path' => $path, 'description' => 'LOCAL DEMO MENU · '.$menu['name'].'. Illustrative portion and price for testing. Real dish photograph; see photo credits.', 'care_instructions' => 'Prepared-food demo item. Confirm ingredients and allergens with the restaurant before ordering from a real menu.', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('vendor_products')->insertOrIgnore(['vendor_id' => $vendor->id, 'product_id' => $productId, 'price_paise' => $dish['price_paise'], 'stock_quantity' => 30, 'available' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $customerId = $this->account('food.customer@demo.invalid', 'Demo · Food Test Customer', '9000091100', false, $password);
            if (! DB::table('addresses')->where('user_id', $customerId)->where('label', 'Food demo home')->exists()) {
                DB::table('addresses')->insert(['user_id' => $customerId, 'label' => 'Food demo home', 'name' => 'Demo Food Customer', 'phone' => '9000091100', 'street' => 'Demo residence, Restaurant Test Area', 'city' => 'Dehradun', 'postal_code' => '248001', 'locality_id' => $locality->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function account(string $email, string $name, string $phone, bool $vendor, string $password): int
    {
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            if ($existing->is_admin || (bool) $existing->is_vendor !== $vendor || $existing->is_sample_guest) {
                throw new RuntimeException('A demo email is already used by an incompatible account.');
            }

            return $existing->id;
        }

        return DB::table('users')->insertGetId(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password, 'is_admin' => false, 'is_vendor' => $vendor, 'is_sample_guest' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
}
