<?php

namespace Tests\Feature;

use Database\Seeders\FmcgProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FmcgProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_fmcg_sub_category_has_a_template_without_vendor_price_or_platform_stock(): void
    {
        $this->seed(FmcgProductSeeder::class);
        $this->assertDatabaseCount('sub_categories', 112);
        $this->assertDatabaseCount('products', 336);
        $this->assertEquals(112, DB::table('products')->distinct()->count('sub_category_id'));
        $counts = DB::table('products')->selectRaw('sub_category_id, COUNT(*) as total')->groupBy('sub_category_id')->get();
        foreach ($counts as $count) {
            $this->assertEquals(3, $count->total);
        }
        $this->assertSame(0, DB::table('products')->where('price_paise', '!=', 0)->count());
        $this->assertSame(0, DB::table('products')->where('stock_quantity', '!=', 0)->count());
        $this->assertSame(0, DB::table('products')->join('sub_categories', 'products.sub_category_id', '=', 'sub_categories.id')->join('categories', 'categories.id', '=', 'sub_categories.category_id')->whereColumn('products.category', '!=', 'categories.name')->count());
        $this->assertDatabaseCount('vendor_products', 0);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reseeding_preserves_template_edits_and_vendor_offers(): void
    {
        $this->seed(FmcgProductSeeder::class);
        $id = DB::table('products')->where('name', 'Assam black tea')->value('id');
        DB::table('products')->where('id', $id)->update(['description' => 'Custom admin description', 'active' => false]);
        $offerId = $this->publishProduct($id, 15000);
        $this->seed(FmcgProductSeeder::class);
        $this->assertDatabaseCount('products', 336);
        $this->assertDatabaseHas('products', ['id' => $id, 'description' => 'Custom admin description', 'active' => false]);
        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'price_paise' => 15000, 'available' => true]);
    }
}
