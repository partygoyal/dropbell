<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $existingIds = DB::table('products')->pluck('id')->all();
        DB::transaction(function () use ($existingIds): void {
            $this->call(FmcgProductSeeder::class);
            $prices = ['Fruits & Vegetables' => 6000, 'Dairy, Bread & Eggs' => 6500, 'Atta, Rice & Pulses' => 12000, 'Oils, Spices & Cooking Essentials' => 11000, 'Snacks & Namkeen' => 5000, 'Biscuits, Chocolates & Sweets' => 8000, 'Breakfast & Spreads' => 15000, 'Instant & Packaged Foods' => 9000, 'Sauces, Pickles & Condiments' => 11000, 'Beverages' => 7500, 'Frozen Foods & Ice Cream' => 16000, 'Personal Care & Hygiene' => 12000, 'Beauty & Grooming' => 19000, 'Cleaning & Household Essentials' => 13000, 'Baby Care' => 25000, 'Pet Care' => 22000];
            foreach (DB::table('products')->whereNotIn('id', $existingIds)->get() as $product) {
                DB::table('products')->where('id', $product->id)->update(['is_sample' => true, 'price_paise' => $prices[$product->category] ?? 10000, 'stock_quantity' => 20, 'updated_at' => now()]);
            }
        });
    }
}
