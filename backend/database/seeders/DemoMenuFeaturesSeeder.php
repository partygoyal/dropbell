<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoMenuFeaturesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo menu choices are local test data only.');
        }
        $this->call(DemoRestaurantSeeder::class);
        foreach (['Demo · Paneer Butter Masala' => ['milk', 'wheat if adding naan'], 'Demo · Masala Dosa' => ['milk if choosing butter']] as $name => $allergens) {
            $product = DB::table('products')->where('name', $name)->first();
            $offer = DB::table('vendor_products')->where('product_id', $product->id)->first();
            if ($offer->option_groups !== null) {
                continue;
            }
            $groups = [
                ['name' => 'Portion size', 'min' => 1, 'max' => 1, 'choices' => [['id' => 'demo-regular', 'name' => 'Regular', 'price_paise' => 0], ['id' => 'demo-large', 'name' => 'Large', 'price_paise' => 6000]]],
                ['name' => 'Spice level', 'min' => 0, 'max' => 1, 'choices' => [['id' => 'demo-mild', 'name' => 'Mild', 'price_paise' => 0], ['id' => 'demo-spicy', 'name' => 'Spicy', 'price_paise' => 0]]],
                ['name' => 'Extras', 'min' => 0, 'max' => 2, 'choices' => $name === 'Demo · Paneer Butter Masala' ? [['id' => 'demo-paneer', 'name' => 'Extra paneer', 'price_paise' => 4000], ['id' => 'demo-naan', 'name' => 'Naan', 'price_paise' => 4500]] : [['id' => 'demo-butter', 'name' => 'Butter', 'price_paise' => 2000], ['id' => 'demo-chutney', 'name' => 'Extra chutney', 'price_paise' => 1500]]],
            ];
            DB::table('vendor_products')->where('id', $offer->id)->whereNull('option_groups')->update(['option_groups' => json_encode($groups), 'dietary_label' => 'vegetarian', 'allergens' => json_encode($allergens), 'menu_revision' => $offer->menu_revision + 1, 'revision' => $offer->revision + 1, 'updated_at' => now()]);
        }
    }
}
