<?php

namespace Tests\Feature;

use Database\Seeders\DailyEssentialsSeeder;
use Database\Seeders\FmcgProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyEssentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_categories_have_five_products_and_reseeding_does_not_duplicate_or_invent_inventory(): void
    {
        $this->seed(DailyEssentialsSeeder::class);
        $this->seed(DailyEssentialsSeeder::class);
        $this->assertDatabaseCount('products', 60);
        $this->assertSame(12, DB::table('categories')->where('active', true)->count());
        foreach (array_keys(DailyEssentialsSeeder::CATALOGUE) as $category) {
            $this->assertSame(5, DB::table('products')->where('category', $category)->count());
        }
        $this->assertDatabaseHas('products', ['name' => 'White sugar', 'pack_size' => '1 kg']);
        $this->assertDatabaseHas('products', ['name' => 'Medium garbage bags', 'pack_size' => '30 pcs']);
        $this->assertSame(0, DB::table('products')->where('stock_quantity', '!=', 0)->count());
        $this->assertSame(0, DB::table('products')->where('price_paise', '!=', 0)->count());
        $this->assertDatabaseCount('vendor_products', 0);
    }

    public function test_existing_products_offers_and_admin_edits_are_preserved_while_non_daily_categories_are_hidden(): void
    {
        $this->seed(FmcgProductSeeder::class);
        $id = DB::table('products')->where('name', 'Assam black tea')->value('id');
        DB::table('products')->where('id', $id)->update(['description' => 'Admin edited tea', 'active' => false]);
        $offer = $this->publishProduct($id, 8500);
        $this->seed(DailyEssentialsSeeder::class);
        $this->assertDatabaseCount('products', 336);
        $this->assertDatabaseHas('products', ['id' => $id, 'description' => 'Admin edited tea', 'active' => false]);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'price_paise' => 8500, 'available' => true]);
        $names = $this->getJson('/api/products')->assertOk()->json('categories.*.name');
        $this->assertCount(12, $names);
        foreach (['Pet Care', 'Baby Care', 'Frozen Foods & Ice Cream', 'Beauty & Grooming', 'Fresh', 'Home'] as $name) {
            $this->assertNotContains($name, $names);
        }
    }
}
