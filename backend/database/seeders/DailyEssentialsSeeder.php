<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DailyEssentialsSeeder extends Seeder
{
    public const CATALOGUE = [
        'Fruits & Vegetables' => [
            ['Fresh Fruits', 'Fresh apples', '1 kg'],
            ['Fresh Fruits', 'Fresh bananas', '6 pcs'],
            ['Fresh Vegetables', 'Fresh potatoes', '1 kg'],
            ['Fresh Vegetables', 'Fresh onions', '1 kg'],
            ['Fresh Vegetables', 'Fresh tomatoes', '500 g'],
        ],
        'Dairy, Bread & Eggs' => [
            ['Milk', 'Full cream milk', '1 litre'],
            ['Curd & Yogurt', 'Plain curd', '400 g'],
            ['Paneer & Cheese', 'Fresh paneer', '200 g'],
            ['Bread & Buns', 'Whole wheat sandwich bread', '400 g'],
            ['Eggs', 'White eggs', '6 pcs'],
        ],
        'Atta, Rice & Pulses' => [
            ['Atta & Whole Wheat Flour', 'Whole wheat atta', '5 kg'],
            ['Rice & Rice Products', 'Basmati rice', '1 kg'],
            ['Dals & Pulses', 'Toor dal', '1 kg'],
            ['Dals & Pulses', 'Masoor dal', '1 kg'],
            ['Besan, Maida & Other Flours', 'Gram flour besan', '500 g'],
        ],
        'Oils, Spices & Cooking Essentials' => [
            ['Cooking Oils', 'Sunflower cooking oil', '1 litre'],
            ['Ghee', 'Cow ghee', '500 ml'],
            ['Ground & Blended Spices', 'Turmeric powder', '100 g'],
            ['Salt & Sugar', 'Iodised salt', '1 kg'],
            ['Salt & Sugar', 'White sugar', '1 kg'],
        ],
        'Beverages' => [
            ['Tea', 'Assam black tea', '250 g'],
            ['Coffee', 'Instant coffee', '100 g'],
            ['Water & Soda', 'Packaged drinking water', '1 litre'],
            ['Juices & Fruit Drinks', 'Orange fruit juice', '1 litre'],
            ['Soft Drinks', 'Lemon lime soft drink', '750 ml'],
        ],
        'Snacks & Namkeen' => [
            ['Potato Chips', 'Classic salted potato chips', '90 g'],
            ['Namkeen & Mixtures', 'Aloo bhujia', '200 g'],
            ['Popcorn', 'Ready-to-eat salted popcorn', '60 g'],
            ['Roasted & Healthy Snacks', 'Roasted chana', '200 g'],
            ['Papad & Fryums', 'Urad dal papad', '200 g'],
        ],
        'Biscuits, Chocolates & Sweets' => [
            ['Glucose & Marie Biscuits', 'Marie biscuits', '250 g'],
            ['Cream Biscuits', 'Vanilla cream biscuits', '150 g'],
            ['Cookies & Digestives', 'Oat digestive cookies', '200 g'],
            ['Chocolates', 'Milk chocolate bar', '100 g'],
            ['Candies & Chewing Gum', 'Mixed fruit candies', '100 g'],
        ],
        'Breakfast & Spreads' => [
            ['Oats', 'Rolled oats', '500 g'],
            ['Corn Flakes & Cereals', 'Plain corn flakes', '250 g'],
            ['Peanut & Nut Butters', 'Creamy peanut butter', '340 g'],
            ['Jams & Fruit Spreads', 'Mixed fruit jam', '200 g'],
            ['Honey', 'Pure honey', '250 g'],
        ],
        'Instant & Packaged Foods' => [
            ['Instant Noodles', 'Masala instant noodles', '280 g'],
            ['Pasta & Vermicelli', 'Penne pasta', '500 g'],
            ['Ready-to-Eat Meals', 'Ready-to-eat rajma', '300 g'],
            ['Ready-to-Cook Mixes', 'Instant dhokla mix', '200 g'],
            ['Packaged Batters & Dough', 'Idli and dosa batter', '1 kg'],
        ],
        'Sauces, Pickles & Condiments' => [
            ['Tomato Ketchup', 'Classic tomato ketchup', '500 g'],
            ['Pickles', 'Mango pickle', '400 g'],
            ['Chutneys', 'Mint chutney', '200 g'],
            ['Vinegar & Soy Sauce', 'Light soy sauce', '200 ml'],
            ['Cooking Pastes', 'Ginger garlic paste', '200 g'],
        ],
        'Personal Care & Hygiene' => [
            ['Soaps & Body Wash', 'Bathing soap', '125 g'],
            ['Shampoo & Conditioner', 'Daily care shampoo', '180 ml'],
            ['Toothpaste & Toothbrushes', 'Fluoride toothpaste', '150 g'],
            ['Hand Wash & Sanitisers', 'Liquid hand wash', '250 ml'],
            ['Menstrual Hygiene', 'Sanitary pads regular', '10 pcs'],
        ],
        'Cleaning & Household Essentials' => [
            ['Laundry Detergents', 'Laundry detergent powder', '1 kg'],
            ['Dishwashing Products', 'Dishwashing liquid', '500 ml'],
            ['Floor & Surface Cleaners', 'Floor cleaning liquid', '1 litre'],
            ['Toilet & Bathroom Cleaners', 'Toilet cleaning liquid', '500 ml'],
            ['Garbage Bags, Foils & Wraps', 'Medium garbage bags', '30 pcs'],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('categories')->whereIn('name', [
                'Fresh', 'Breakfast', 'Snacks', 'Home',
                'Frozen Foods & Ice Cream', 'Beauty & Grooming', 'Baby Care', 'Pet Care',
            ])->update(['active' => false, 'updated_at' => now()]);

            foreach (self::CATALOGUE as $category => $products) {
                DB::table('categories')->insertOrIgnore(['name' => $category, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $categoryId = DB::table('categories')->where('name', $category)->value('id');
                DB::table('categories')->where('id', $categoryId)->update(['active' => true, 'updated_at' => now()]);
                foreach ($products as [$subCategory, $name, $pack]) {
                    DB::table('sub_categories')->insertOrIgnore(['category_id' => $categoryId, 'name' => $subCategory, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    $subCategoryId = DB::table('sub_categories')->where('category_id', $categoryId)->where('name', $subCategory)->value('id');
                    $exists = DB::table('products')->where('category', $category)->where('name', $name)->where('pack_size', $pack)->exists();
                    if (! $exists) {
                        DB::table('products')->insert([
                            'category' => $category, 'sub_category_id' => $subCategoryId, 'name' => $name, 'pack_size' => $pack,
                            'price_paise' => 0, 'stock_quantity' => 0, 'active' => true,
                            'description' => $name.' · '.$pack.'. Check packaging for ingredients and manufacturer details.',
                            'care_instructions' => 'Follow the storage and usage instructions on the product packaging.',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
        $counts = DB::table('products')->whereIn('category', array_keys(self::CATALOGUE))->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->orderBy('category')->get();
        foreach ($counts as $count) {
            $this->command?->info($count->category.': '.$count->total.' products');
        }
    }
}
