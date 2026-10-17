<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FmcgCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Fruits & Vegetables' => ['Fresh Fruits', 'Fresh Vegetables', 'Leafy Greens', 'Herbs & Seasonings', 'Exotic Fruits & Vegetables', 'Organic Produce', 'Cut & Packed Produce'],
            'Dairy, Bread & Eggs' => ['Milk', 'Curd & Yogurt', 'Paneer & Cheese', 'Butter & Cream', 'Bread & Buns', 'Eggs', 'Flavoured Milk & Dairy Drinks'],
            'Atta, Rice & Pulses' => ['Atta & Whole Wheat Flour', 'Rice & Rice Products', 'Dals & Pulses', 'Besan, Maida & Other Flours', 'Millets & Grains', 'Poha & Sabudana', 'Organic Staples'],
            'Oils, Spices & Cooking Essentials' => ['Cooking Oils', 'Ghee', 'Whole Spices', 'Ground & Blended Spices', 'Salt & Sugar', 'Jaggery & Sweeteners', 'Baking Ingredients'],
            'Snacks & Namkeen' => ['Potato Chips', 'Nachos & Dips', 'Namkeen & Mixtures', 'Popcorn', 'Nuts & Seeds', 'Roasted & Healthy Snacks', 'Papad & Fryums'],
            'Biscuits, Chocolates & Sweets' => ['Glucose & Marie Biscuits', 'Cream Biscuits', 'Cookies & Digestives', 'Chocolates', 'Candies & Chewing Gum', 'Indian Sweets', 'Cakes & Packaged Desserts'],
            'Breakfast & Spreads' => ['Oats', 'Corn Flakes & Cereals', 'Muesli & Granola', 'Peanut & Nut Butters', 'Jams & Fruit Spreads', 'Honey', 'Breakfast Mixes'],
            'Instant & Packaged Foods' => ['Instant Noodles', 'Pasta & Vermicelli', 'Ready-to-Eat Meals', 'Ready-to-Cook Mixes', 'Instant Soups', 'Canned & Tinned Foods', 'Packaged Batters & Dough'],
            'Sauces, Pickles & Condiments' => ['Tomato Ketchup', 'Cooking & Pasta Sauces', 'Mayonnaise & Dressings', 'Pickles', 'Chutneys', 'Vinegar & Soy Sauce', 'Cooking Pastes'],
            'Beverages' => ['Water & Soda', 'Soft Drinks', 'Juices & Fruit Drinks', 'Tea', 'Coffee', 'Energy & Sports Drinks', 'Malted & Nutrition Drinks'],
            'Frozen Foods & Ice Cream' => ['Ice Cream Tubs', 'Ice Cream Cones & Bars', 'Frozen Vegetables', 'Frozen Fries & Potato Snacks', 'Frozen Vegetarian Snacks', 'Frozen Non-Vegetarian Foods', 'Frozen Breads & Meals'],
            'Personal Care & Hygiene' => ['Soaps & Body Wash', 'Shampoo & Conditioner', 'Hair Oils & Treatments', 'Toothpaste & Toothbrushes', 'Hand Wash & Sanitisers', 'Menstrual Hygiene', 'Tissues, Wipes & Adult Care'],
            'Beauty & Grooming' => ['Face Wash & Cleansers', 'Moisturisers & Skin Care', 'Sunscreen', 'Deodorants & Fragrances', 'Shaving & Hair Removal', 'Makeup & Nail Care', 'Hair Styling & Colour'],
            'Cleaning & Household Essentials' => ['Laundry Detergents', 'Fabric Care', 'Dishwashing Products', 'Floor & Surface Cleaners', 'Toilet & Bathroom Cleaners', 'Garbage Bags, Foils & Wraps', 'Cleaning Tools & Pest Control'],
            'Baby Care' => ['Diapers & Nappies', 'Baby Wipes', 'Baby Bath & Skin Care', 'Baby Oils & Powders', 'Infant Formula', 'Baby Cereals & Food', 'Feeding & Hygiene Essentials'],
            'Pet Care' => ['Dog Food', 'Cat Food', 'Pet Treats', 'Pet Litter & Waste Bags', 'Pet Shampoo & Grooming', 'Pet Hygiene', 'Food for Birds & Small Pets'],
        ];

        DB::transaction(function () use ($categories): void {
            foreach ($categories as $name => $subCategories) {
                DB::table('categories')->insertOrIgnore(['name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $categoryId = DB::table('categories')->where('name', $name)->value('id');
                foreach ($subCategories as $subCategory) {
                    DB::table('sub_categories')->insertOrIgnore(['category_id' => $categoryId, 'name' => $subCategory, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
