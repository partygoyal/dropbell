<?php

namespace Tests\Feature;

use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DemoRestaurantTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_menus_have_real_photos_and_are_available_only_in_their_delivery_area(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $response = $this->getJson('/api/products?section=food&postal_code=248001')->assertOk()->assertJsonCount(18, 'data')->assertJsonCount(3, 'vendors');
        $this->assertEqualsCanonicalizing(['North Indian', 'South Indian', 'Chinese'], collect($response->json('vendors'))->flatMap(fn ($vendor) => $vendor['cuisines'])->all());
        foreach ($response->json('vendors') as $vendor) {
            $this->assertSame(6, $vendor['section_counts']['food']);
            $this->assertStringContainsString('/storage/food-demo-dishes/', $vendor['image_url']);
        }
        foreach (DB::table('products')->get() as $product) {
            Storage::disk('public')->assertExists($product->image_path);
        }
        $this->getJson('/api/products?section=food&postal_code=110001')->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
        $this->getJson('/api/products?section=groceries&postal_code=248001')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_demo_customer_can_order_a_dish_with_a_coupon_and_vendor_inventory_is_reduced(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $token = $this->postJson('/api/customer/auth/login', ['email' => 'food.customer@demo.invalid', 'password' => 'FoodDemo@1234'])->assertOk()->json('token');
        $product = DB::table('products')->where('name', 'Demo · Paneer Butter Masala')->first();
        $offer = DB::table('vendor_products')->where('product_id', $product->id)->first();
        $locality = DB::table('service_localities')->where('name', 'Demo · Restaurant Test Area')->value('id');
        $payload = ['vendor_id' => $offer->vendor_id, 'items' => [['id' => $product->id, 'quantity' => 2]], 'coupon' => 'BITE10', 'postal_code' => '248001', 'address' => ['name' => 'Demo Customer', 'phone' => '9000091100', 'street' => 'Demo residence, Restaurant Test Area', 'city' => 'Dehradun', 'pin' => '248001', 'locality_id' => $locality]];
        $bill = $this->withToken($token)->postJson('/api/customer/quote', $payload)->assertOk()->assertJsonPath('data.bill.discount', 4400)->json('data.bill');
        $this->postJson('/api/customer/orders', $payload + ['request_key' => (string) Str::uuid(), 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => $bill['total']])->assertCreated();
        $this->assertDatabaseHas('vendor_products', ['id' => $offer->id, 'stock_quantity' => 28]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 0]);
        $this->assertDatabaseHas('orders', ['coupon_code' => 'BITE10']);
    }

    public function test_reseeding_preserves_admin_edits_and_existing_grocery_data(): void
    {
        Storage::fake('public');
        $grocery = DB::table('products')->insertGetId(['name' => 'Existing milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 5500, 'stock_quantity' => 12, 'active' => true]);
        $this->seed(DemoRestaurantSeeder::class);
        $offer = DB::table('vendor_products')->first();
        DB::table('vendor_products')->where('id', $offer->id)->update(['price_paise' => 33300, 'available' => false]);
        $this->seed(DemoRestaurantSeeder::class);
        $this->assertDatabaseCount('vendors', 3);
        $this->assertDatabaseCount('products', 19);
        $this->assertDatabaseCount('vendor_products', 18);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer->id, 'price_paise' => 33300, 'available' => false]);
        $this->assertDatabaseHas('products', ['id' => $grocery, 'price_paise' => 5500, 'stock_quantity' => 12]);
    }

    public function test_demo_data_cannot_be_seeded_in_production(): void
    {
        $categories = DB::table('categories')->count();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->app->make(DemoRestaurantSeeder::class)->run();
            $this->fail('Production must reject demo menus.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Demo restaurant menus can only be added locally.', $exception->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('categories', $categories);
    }
}
