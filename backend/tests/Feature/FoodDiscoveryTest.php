<?php

namespace Tests\Feature;

use App\Services\Promotions;
use Database\Seeders\FoodDiscoverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FoodDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_discovery_adds_photo_categories_and_real_offers_without_inventing_restaurants_or_menus(): void
    {
        Storage::fake('public');
        $groceryCategories = DB::table('categories')->where('shopping_section', 'groceries')->count();
        $this->seed(FoodDiscoverySeeder::class);
        $this->assertSame(16, DB::table('categories')->where('shopping_section', 'food')->count());
        $this->assertSame(36, DB::table('sub_categories')->whereIn('category_id', DB::table('categories')->where('shopping_section', 'food')->select('id'))->count());
        $this->assertSame($groceryCategories, DB::table('categories')->where('shopping_section', 'groceries')->count());
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('products', 0);
        foreach (DB::table('categories')->where('shopping_section', 'food')->get() as $category) {
            Storage::disk('public')->assertExists($category->banner_path);
        }
        $this->getJson('/api/products?section=food')->assertOk()->assertJsonCount(16, 'categories')->assertJsonCount(36, 'sub_categories')->assertJsonCount(0, 'vendors')->assertJsonCount(0, 'data')->assertJsonPath('categories.0.name', 'Biryani');
        $this->assertNotEmpty($this->getJson('/api/products?section=food')->json('categories.0.image_url'));
        $this->getJson('/api/products?section=groceries')->assertOk()->assertJsonCount($groceryCategories, 'categories');
        $banners = $this->getJson('/api/promotions')->assertOk()->json('data');
        $foodBanners = collect($banners)->where('banner_section', 'food');
        $this->assertCount(3, $foodBanners);
        $this->assertEqualsCanonicalizing(['BITE10', 'FEAST50', 'TREAT15'], $foodBanners->pluck('code')->all());
        $this->assertSame(5000, Promotions::discount(Promotions::resolve('BITE10'), 100000));
        $this->assertSame(5000, Promotions::discount(Promotions::resolve('FEAST50'), 39900));
        $this->assertSame(7500, Promotions::discount(Promotions::resolve('TREAT15'), 100000));
        $this->postJson('/api/promotions/validate', ['code' => 'FEAST50', 'subtotal' => 39899])->assertUnprocessable();
    }

    public function test_reseeding_preserves_admin_changes_to_categories_and_promotions(): void
    {
        Storage::fake('public');
        $this->seed(FoodDiscoverySeeder::class);
        DB::table('categories')->where('name', 'Biryani')->update(['description' => 'Admin description', 'active' => false, 'banner_path' => 'custom-food.jpg']);
        DB::table('promotions')->where('code', 'BITE10')->update(['title' => 'Admin campaign title', 'active' => false, 'discount_value' => 5]);
        $this->seed(FoodDiscoverySeeder::class);
        $this->assertSame(16, DB::table('categories')->where('shopping_section', 'food')->count());
        $this->assertSame(3, DB::table('promotions')->where('banner_section', 'food')->count());
        $this->assertDatabaseHas('categories', ['name' => 'Biryani', 'description' => 'Admin description', 'active' => false, 'banner_path' => 'custom-food.jpg']);
        $this->assertDatabaseHas('promotions', ['code' => 'BITE10', 'title' => 'Admin campaign title', 'active' => false, 'discount_value' => 5]);
    }
}
