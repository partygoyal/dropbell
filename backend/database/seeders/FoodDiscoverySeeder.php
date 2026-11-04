<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FoodDiscoverySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Biryani', 'biryani', ['Veg Biryani', 'Chicken Biryani', 'Mutton Biryani']],
            ['Pizza', 'pizza', ['Veg Pizza', 'Non-Veg Pizza']],
            ['Burgers', 'burgers', ['Veg Burgers', 'Chicken Burgers']],
            ['Momos', 'momos', ['Steamed Momos', 'Fried Momos']],
            ['North Indian', 'north-indian', ['Paneer & Curries', 'Thalis', 'Indian Breads']],
            ['South Indian', 'south-indian', ['Dosa', 'Idli & Vada']],
            ['Chinese', 'chinese', ['Noodles', 'Fried Rice', 'Chinese Starters']],
            ['Rolls & Wraps', 'rolls-wraps', ['Veg Rolls', 'Chicken Rolls']],
            ['Desserts', 'desserts', ['Cakes & Brownies', 'Indian Desserts']],
            ['Ice Cream', 'ice-cream', ['Scoops & Sundaes', 'Kulfi']],
            ['Drinks & Shakes', 'drinks-shakes', ['Milkshakes', 'Fresh Juices', 'Cold Coffee']],
            ['Healthy Bowls', 'healthy-bowls', ['Salads', 'Protein Bowls']],
            ['Breakfast & Snacks', 'breakfast-snacks', ['Parathas', 'Poha & Upma']],
            ['Sandwiches', 'sandwiches', ['Grilled Sandwiches', 'Club Sandwiches']],
            ['Pasta', 'pasta', ['White Sauce Pasta', 'Red Sauce Pasta']],
            ['Street Food', 'street-food', ['Chaat', 'Samosas & Pakoras']],
        ];
        $promotions = [
            ['BITE10', 'Big flavour. A little less.', '10% off your items subtotal, up to ₹50, on orders of ₹199 or more. One coupon per order.', 'percent', 10, 19900, 5000, 'biryani'],
            ['FEAST50', 'Turn tonight into a feast.', 'Flat ₹50 off your items subtotal on orders of ₹399 or more. One coupon per order.', 'fixed', 5000, 39900, 5000, 'combo'],
            ['TREAT15', 'Make room for something sweet.', '15% off your items subtotal, up to ₹75, on orders of ₹299 or more. One coupon per order.', 'percent', 15, 29900, 7500, 'desserts'],
        ];
        DB::transaction(function () use ($categories, $promotions): void {
            foreach ($categories as [$name, $asset, $children]) {
                $path = $this->installAsset('categories/'.$asset.'.webp', 'food-categories/'.$asset.'.webp');
                DB::table('categories')->insertOrIgnore(['name' => $name, 'shopping_section' => 'food', 'description' => 'Explore '.$name.' from local restaurants and kitchens.', 'banner_path' => $path, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $category = DB::table('categories')->where('name', $name)->first();
                if ($category->shopping_section !== 'food') {
                    continue;
                }
                foreach ($children as $child) {
                    DB::table('sub_categories')->insertOrIgnore(['category_id' => $category->id, 'name' => $child, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach ($promotions as $position => [$code, $title, $description, $type, $discount, $minimum, $maximum, $asset]) {
                $path = $this->installAsset('promotions/'.$asset.'.webp', 'food-promotions/'.$asset.'.webp');
                DB::table('promotions')->insertOrIgnore(['code' => $code, 'title' => $title, 'description' => $description, 'discount_type' => $type, 'discount_value' => $discount, 'minimum_paise' => $minimum, 'maximum_discount_paise' => $maximum, 'banner_path' => $path, 'banner_section' => 'food', 'show_banner' => true, 'active' => true, 'sort_order' => $position, 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function installAsset(string $asset, string $destination): string
    {
        if (! Storage::disk('public')->exists($destination)) {
            if (! Storage::disk('public')->put($destination, file_get_contents(public_path('assets/food/'.$asset)))) {
                throw new \RuntimeException('Unable to save food discovery artwork.');
            }
        }

        return $destination;
    }
}
