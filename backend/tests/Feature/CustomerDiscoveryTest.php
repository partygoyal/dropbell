<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_favourites_are_owned_idempotent_and_reviews_require_delivered_purchases_and_moderation(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $user = User::where('email', 'food.customer@demo.invalid')->first();
        $product = DB::table('products')->first();
        $vendor = DB::table('vendors')->first();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'discovery'), 'expires_at' => now()->addDay()]);
        $this->getJson('/api/customer/favourites')->assertUnauthorized();
        $this->withToken('discovery')->putJson('/api/customer/favourites', ['kind' => 'product', 'target_id' => $product->id, 'saved' => true])->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/customer/favourites', ['kind' => 'product', 'target_id' => $product->id, 'saved' => true])->assertOk()->assertJsonCount(1, 'data');
        $order = DB::table('orders')->insertGetId(['reference' => 'verified-order', 'user_id' => $user->id, 'status' => 'received', 'delivery_address' => '{}', 'subtotal_paise' => 10000, 'total_paise' => 10000]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product->id, 'vendor_id' => $vendor->id, 'product_name' => $product->name, 'pack_size' => '1 portion', 'quantity' => 1, 'unit_price_paise' => 10000]);
        $review = ['product_id' => $product->id, 'rating' => 5, 'comment' => 'A very good meal'];
        $this->postJson('/api/customer/orders/verified-order/reviews', $review)->assertUnprocessable();
        DB::table('orders')->where('id', $order)->update(['status' => 'delivered']);
        $this->postJson('/api/customer/orders/verified-order/reviews', $review)->assertOk();
        $this->getJson('/api/products/'.$product->id.'/reviews')->assertJsonCount(0, 'data');
        $row = DB::table('product_reviews')->first();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patch('/admin/reviews/'.$row->id, ['status' => 'published', 'revision' => $row->revision])->assertRedirect();
        $this->getJson('/api/products/'.$product->id.'/reviews')->assertOk()->assertJsonPath('data.0.verified_purchase', true)->assertJsonPath('data.0.rating', 5)->assertJsonMissing(['email' => $user->email]);
        $this->withToken('discovery')->postJson('/api/customer/orders/verified-order/reviews', array_replace($review, ['comment' => 'Updated after a second look']))->assertOk();
        $this->getJson('/api/products/'.$product->id.'/reviews')->assertJsonCount(0, 'data');
        $this->putJson('/api/customer/favourites', ['kind' => 'product', 'target_id' => $product->id, 'saved' => false])->assertJsonCount(0, 'data');
    }

    public function test_reordering_rechecks_the_original_shop_price_stock_and_current_location(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $user = User::where('email', 'food.customer@demo.invalid')->first();
        $offer = DB::table('vendor_products')->first();
        $product = DB::table('products')->find($offer->product_id);
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'again'), 'expires_at' => now()->addDay()]);
        $id = DB::table('orders')->insertGetId(['reference' => 'past-order', 'user_id' => $user->id, 'status' => 'delivered', 'delivery_address' => '{}', 'subtotal_paise' => 10000, 'total_paise' => 10000]);
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $product->id, 'vendor_id' => $offer->vendor_id, 'product_name' => $product->name, 'pack_size' => '1 portion', 'quantity' => 2, 'unit_price_paise' => 10000]);
        $locality = DB::table('service_localities')->where('name', 'Demo · Restaurant Test Area')->value('id');
        DB::table('vendor_products')->where('id', $offer->id)->update(['price_paise' => 33300]);
        $this->withToken('again')->postJson('/api/customer/orders/past-order/reorder', ['locality_id' => $locality])->assertOk()->assertJsonPath('data.items.0.product.price_paise', 33300)->assertJsonPath('data.items.0.vendor_id', $offer->vendor_id);
        DB::table('vendor_products')->where('id', $offer->id)->update(['available' => false]);
        $this->postJson('/api/customer/orders/past-order/reorder', ['locality_id' => $locality])->assertOk()->assertJsonCount(0, 'data.items')->assertJsonCount(1, 'data.unavailable');
        $this->assertDatabaseCount('orders', 1);
    }
}
