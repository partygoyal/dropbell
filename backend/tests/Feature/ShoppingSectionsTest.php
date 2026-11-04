<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShoppingSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $category): int
    {
        return DB::table('products')->insertGetId(['name' => $name, 'category' => $category, 'pack_size' => '1 pack', 'price_paise' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_food_and_grocery_products_vendors_and_categories_are_separate_and_location_aware(): void
    {
        DB::table('categories')->insert(['name' => 'Restaurant Meals', 'active' => true, 'shopping_section' => 'food']);
        $food = $this->product('Paneer biryani', 'Restaurant Meals');
        $grocery = $this->product('Milk', 'Fresh');
        $foodOffer = $this->publishProduct($food, 18000, ['560001']);
        $this->publishProduct($grocery, 5000, ['560001', '110001']);
        $this->getJson('/api/products?section=food&postal_code=560001')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'vendors')->assertJsonCount(1, 'categories')->assertJsonPath('data.0.name', 'Paneer biryani')->assertJsonPath('data.0.shopping_section', 'food')->assertJsonPath('vendors.0.sections', ['food'])->assertJsonPath('vendors.0.section_counts.food', 1);
        $this->getJson('/api/products?section=groceries&postal_code=560001')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'vendors')->assertJsonPath('data.0.name', 'Milk')->assertJsonPath('data.0.shopping_section', 'groceries');
        $this->getJson('/api/products?section=food&postal_code=110001')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
        $this->getJson('/api/products')->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(2, 'vendors');
        $this->getJson('/api/products?section=invalid')->assertUnprocessable();
        DB::table('vendor_products')->where('id', $foodOffer)->update(['available' => false]);
        $this->getJson('/api/products?section=food')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
    }

    public function test_a_vendor_selling_both_sections_has_separate_menu_and_grocery_counts(): void
    {
        DB::table('categories')->insert(['name' => 'Restaurant Meals', 'active' => true, 'shopping_section' => 'food']);
        $food = $this->product('Dal and rice', 'Restaurant Meals');
        $grocery = $this->product('Eggs', 'Fresh');
        $offer = $this->publishProduct($food, 14000);
        $vendor = DB::table('vendor_products')->where('id', $offer)->value('vendor_id');
        DB::table('vendor_products')->insert(['product_id' => $grocery, 'vendor_id' => $vendor, 'price_paise' => 6500, 'available' => true]);
        $response = $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'vendors')->assertJsonPath('vendors.0.section_counts.food', 1)->assertJsonPath('vendors.0.section_counts.groceries', 1);
        $this->assertEqualsCanonicalizing(['food', 'groceries'], $response->json('vendors.0.sections'));
    }

    public function test_admin_can_classify_categories_and_banner_placement(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/categories', ['name' => 'Biryani', 'active' => true, 'shopping_section' => 'food'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['name' => 'Biryani', 'shopping_section' => 'food']);
        $this->postJson('/admin/categories', ['name' => 'Bad section', 'active' => true, 'shopping_section' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('shopping_section');
        $this->post('/admin/promotions', ['title' => 'Lunch specials', 'discount_type' => 'none', 'minimum' => 0, 'active' => true, 'show_banner' => true, 'sort_order' => 0, 'banner_section' => 'food'])->assertRedirect();
        $this->getJson('/api/promotions')->assertOk()->assertJsonPath('data.0.banner_section', 'food');
        $this->get('/admin/categories')->assertOk()->assertSee('Shopping section');
        $this->get('/admin/promotions/create')->assertOk()->assertSee('Banner appears in');
    }
}
