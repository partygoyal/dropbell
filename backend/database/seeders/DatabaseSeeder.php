<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [1, 'Sweet ripe bananas', 'Fresh', '6 pcs', 4800],
            [2, 'Fresh whole milk', 'Breakfast', '500 ml', 3000],
            [3, 'Whole wheat bread', 'Breakfast', '400 g', 4500],
            [4, 'Farm fresh eggs', 'Breakfast', '6 pcs', 6000],
            [5, 'Juicy red tomatoes', 'Fresh', '500 g', 2500],
            [6, 'Classic salted popcorn', 'Snacks', '100 g', 6500],
            [7, 'Fresh orange juice', 'Snacks', '1 litre', 11000],
            [8, 'Gentle hand wash', 'Home', '250 ml', 8500],
            [9, 'Everyday cleaning spray', 'Home', '500 ml', 12000],
        ];
        foreach ($products as [$id, $name, $category, $pack, $price]) {
            DB::table('products')->insertOrIgnore([
                'id' => $id, 'name' => $name, 'category' => $category,
                'pack_size' => $pack, 'price_paise' => $price, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
