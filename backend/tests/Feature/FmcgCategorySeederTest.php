<?php

namespace Tests\Feature;

use Database\Seeders\FmcgCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FmcgCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fmcg_categories_have_seven_children_and_seeding_is_repeatable(): void
    {
        $existingCategories = DB::table('categories')->count();
        $this->seed(FmcgCategorySeeder::class);
        $this->seed(FmcgCategorySeeder::class);
        $this->assertDatabaseCount('categories', $existingCategories + 16);
        $this->assertDatabaseCount('sub_categories', 112);
        $counts = DB::table('sub_categories')->select('category_id')->selectRaw('COUNT(*) as total')->groupBy('category_id')->get();
        $this->assertCount(16, $counts);
        foreach ($counts as $count) {
            $this->assertEquals(7, $count->total);
        }
        $this->assertDatabaseHas('categories', ['name' => 'Baby Care', 'active' => true]);
        $this->assertDatabaseHas('sub_categories', ['category_id' => DB::table('categories')->where('name', 'Beverages')->value('id'), 'name' => 'Tea']);
    }

    public function test_seeding_preserves_existing_assignments_and_disabled_categories(): void
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Beverages', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $subCategoryId = DB::table('sub_categories')->insertGetId(['category_id' => $categoryId, 'name' => 'Tea', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId(['name' => 'Existing tea', 'category' => 'Beverages', 'sub_category_id' => $subCategoryId, 'pack_size' => '100 g', 'price_paise' => 5000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(FmcgCategorySeeder::class);
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'active' => false]);
        $this->assertDatabaseHas('sub_categories', ['id' => $subCategoryId, 'active' => false]);
        $this->assertDatabaseHas('products', ['id' => $productId, 'category' => 'Beverages', 'sub_category_id' => $subCategoryId]);
    }
}
