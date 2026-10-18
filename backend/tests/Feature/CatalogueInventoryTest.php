<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SampleCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogueInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(int $productId, int $quantity = 2): array
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'inventory-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('inventory-token');
        DB::table('delivery_areas')->insert(['name' => 'Test area', 'postal_code' => '248001', 'fee_paise' => 0, 'minimum_paise' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return ['request_key' => (string) Str::uuid(), 'items' => [['id' => $productId, 'quantity' => $quantity]], 'address' => ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Road', 'city' => 'Dehradun', 'pin' => '248001'], 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => 6000 * $quantity];
    }

    private function stockedProduct(int $stock = 5): array
    {
        $id = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'active' => true, 'price_paise' => 6000, 'created_at' => now(), 'updated_at' => now()]);
        $offerId = $this->publishProduct($id, 6000, ['248001']);
        DB::table('vendor_products')->where('id', $offerId)->update(['stock_quantity' => $stock]);

        return [$id, $offerId];
    }

    public function test_sample_catalogue_publishes_three_per_subcategory_without_fake_orderable_stock(): void
    {
        $this->seed(SampleCatalogueSeeder::class);
        $data = $this->getJson('/api/products')->assertOk()->assertJsonCount(336, 'data')->json('data');
        foreach (collect($data)->groupBy('sub_category_id') as $products) {
            $this->assertCount(3, $products);
        }
        $this->assertCount(112, collect($data)->groupBy('sub_category_id'));
        $this->assertTrue($data[0]['is_sample']);
        $this->assertFalse($data[0]['available']);
        $this->assertSame(0, $data[0]['max_order_quantity']);
        $this->assertSame(20, $data[0]['stock_quantity']);
        $payload = $this->checkout($data[0]['id']);

        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('vendors', 0);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin')->assertOk()->assertSee('336 sample previews')->assertSee('6720')->assertSee('Illustrative packs; not physical inventory');
        $this->get('/admin/products')->assertOk()->assertSee('20 sample')->assertSee('Sample preview');
    }

    public function test_reseeding_keeps_edited_sample_stock_and_real_inventory(): void
    {
        [$real, $offer] = $this->stockedProduct();
        $this->seed(SampleCatalogueSeeder::class);
        $sample = DB::table('products')->where('is_sample', true)->value('id');
        DB::table('products')->where('id', $sample)->update(['stock_quantity' => 7, 'price_paise' => 9000]);

        $this->seed(SampleCatalogueSeeder::class);

        $this->assertDatabaseCount('products', 337);
        $this->assertDatabaseHas('products', ['id' => $sample, 'stock_quantity' => 7, 'price_paise' => 9000]);
        $this->assertDatabaseHas('products', ['id' => $real, 'is_sample' => false]);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 5]);
    }

    public function test_checkout_reserves_vendor_stock_once_and_cancellation_restores_it_once(): void
    {
        [$id, $offer] = $this->stockedProduct(2);
        $payload = $this->checkout($id);
        $reference = $this->postJson('/api/customer/orders', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/customer/orders', $payload)->assertCreated()->assertJsonPath('data.id', $reference);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 0, 'revision' => 2]);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $orderId = DB::table('orders')->value('id');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patch('/admin/orders/'.$orderId, ['status' => 'cancelled', 'revision' => 0, 'reason' => 'Customer requested cancellation'])->assertRedirect();
        $this->patch('/admin/orders/'.$orderId, ['status' => 'cancelled', 'revision' => 1, 'reason' => 'Customer requested cancellation'])->assertRedirect();

        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 2, 'revision' => 3]);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.stock_quantity', 2)->assertJsonPath('data.0.max_order_quantity', 2);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_insufficient_stock_and_changed_totals_do_not_consume_inventory(): void
    {
        [$id, $offer] = $this->stockedProduct(1);
        $payload = $this->checkout($id);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $payload['items'][0]['quantity'] = 1;
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_total');

        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 1, 'revision' => 1]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_vendor_stock_edit_honours_revision_and_is_visible_to_admin(): void
    {
        [$id, $offerId] = $this->stockedProduct();
        $offer = DB::table('vendor_products')->find($offerId);
        $vendor = DB::table('vendors')->find($offer->vendor_id);
        $this->actingAs(User::find($vendor->user_id));
        $this->put('/vendor/products/'.$id, ['revision' => 1, 'available' => 1, 'price' => '60', 'stock_quantity' => 8])->assertRedirect();
        $this->putJson('/vendor/products/'.$id, ['revision' => 1, 'available' => 1, 'price' => '60', 'stock_quantity' => 9])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->putJson('/vendor/products/'.$id, ['revision' => 2, 'available' => 1, 'price' => '60', 'stock_quantity' => -1])->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');

        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'stock_quantity' => 8, 'revision' => 2]);
        $this->get('/vendor/products')->assertOk()->assertSee('Stock (packs)');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin')->assertOk()->assertSee('1 orderable')->assertSee('Real vendor stock');
        $this->get('/admin/vendors/'.$vendor->id)->assertOk()->assertSee('Stock (packs)');
    }

    public function test_admin_quantity_changes_reserve_and_release_original_vendor_stock(): void
    {
        [$id, $offerId] = $this->stockedProduct(5);
        $payload = $this->checkout($id);
        $this->postJson('/api/customer/orders', $payload)->assertCreated();
        $orderId = DB::table('orders')->value('id');
        $itemId = DB::table('order_items')->value('id');
        $edit = ['revision' => 0, 'address' => $payload['address'], 'quantities' => [$itemId => 4], 'delivery_preference' => 'standard', 'reason' => 'Customer requested more milk'];
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->put('/admin/orders/'.$orderId, $edit)->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'stock_quantity' => 1]);
        $itemId = DB::table('order_items')->value('id');
        $edit['revision'] = 1;
        $edit['quantities'] = [$itemId => 6];
        $this->putJson('/admin/orders/'.$orderId, $edit)->assertUnprocessable();
        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'stock_quantity' => 1]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 4]);
        $edit['quantities'] = [$itemId => 1];
        $this->put('/admin/orders/'.$orderId, $edit)->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['id' => $offerId, 'stock_quantity' => 4]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'revision' => 2, 'total_paise' => 6000]);
    }

    public function test_real_offer_replaces_sample_preview_and_is_filtered_by_postal_code(): void
    {
        $this->seed(SampleCatalogueSeeder::class);
        $id = DB::table('products')->where('is_sample', true)->value('id');
        $offer = $this->publishProduct($id, 9000, ['248001']);
        DB::table('vendor_products')->where('id', $offer)->update(['stock_quantity' => 3]);

        $this->getJson('/api/products/'.$id.'?postal_code=248001')->assertOk()->assertJsonPath('data.is_sample', false)->assertJsonPath('data.available', true)->assertJsonPath('data.price_paise', 9000)->assertJsonPath('data.stock_quantity', 3);
        $this->getJson('/api/products/'.$id.'?postal_code=248002')->assertOk()->assertJsonPath('data.is_sample', true)->assertJsonPath('data.available', false);
        DB::table('vendor_products')->where('id', $offer)->update(['stock_quantity' => 0]);
        $this->getJson('/api/products/'.$id.'?postal_code=248001')->assertOk()->assertJsonPath('data.is_sample', true)->assertJsonPath('data.available', false);
    }

    public function test_admin_can_edit_sample_price_and_quantity_without_changing_vendor_stock(): void
    {
        $this->seed(SampleCatalogueSeeder::class);
        $product = DB::table('products')->where('is_sample', true)->first();
        $offer = $this->publishProduct($product->id, 9000, ['248001']);
        DB::table('vendor_products')->where('id', $offer)->update(['stock_quantity' => 3]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertSee('Sample stock (packs)');

        $this->put('/admin/products/'.$product->id, ['name' => $product->name, 'category' => $product->category, 'sub_category_id' => $product->sub_category_id, 'pack_size' => $product->pack_size, 'price' => '75', 'sample_stock_quantity' => 8, 'active' => 1])->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_sample' => true, 'stock_quantity' => 8, 'price_paise' => 7500]);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 3, 'price_paise' => 9000]);
    }
}
