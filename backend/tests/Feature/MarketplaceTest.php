<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'Milk'): int
    {
        return DB::table('products')->insertGetId(['name' => $name, 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 99999, 'stock_quantity' => 0, 'expires_on' => '2020-01-01', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function area(): void
    {
        DB::table('delivery_areas')->insert(['name' => 'Central', 'postal_code' => '560001', 'fee_paise' => 2500, 'minimum_paise' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function owner(int $offerId): User
    {
        $vendor = DB::table('vendors')->find(DB::table('vendor_products')->where('id', $offerId)->value('vendor_id'));

        return User::findOrFail($vendor->user_id);
    }

    public function test_registration_onboarding_approval_and_login(): void
    {
        $this->area();
        $this->get('/vendor/register')->assertOk();
        $this->post('/vendor/register', ['name' => 'Shop owner', 'email' => 'owner@example.test', 'phone' => '9876543210', 'password' => 'secure-password', 'password_confirmation' => 'secure-password', 'is_admin' => true])->assertRedirect('/vendor/onboarding');
        $owner = User::where('email', 'owner@example.test')->first();
        $this->assertTrue($owner->is_vendor);
        $this->assertFalse($owner->is_admin);
        $vendorId = DB::table('vendors')->value('id');
        $this->get('/admin/vendors')->assertForbidden();
        $this->put('/vendor/onboarding', ['revision' => 0, 'business_name' => 'Neighbourhood Store', 'pickup_address' => '10 Market Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'service_postal_codes' => ['560001']])->assertRedirect('/vendor');
        $this->assertDatabaseHas('vendors', ['id' => $vendorId, 'status' => 'pending', 'revision' => 1]);
        $this->get('/vendor')->assertOk();
        $this->get('/vendor/products')->assertOk();
        $this->post('/vendor/logout')->assertRedirect('/vendor/login');
        $this->get('/vendor')->assertRedirect('/vendor/login');
        $this->post('/vendor/login', ['email' => 'owner@example.test', 'password' => 'secure-password'])->assertRedirect('/vendor');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/vendors')->assertOk()->assertSee('Neighbourhood Store');
        $this->get('/admin/vendors/'.$vendorId)->assertOk();
        $this->patch('/admin/vendors/'.$vendorId, ['revision' => 1, 'status' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('vendors', ['id' => $vendorId, 'status' => 'approved', 'reviewed_by' => $admin->id]);
        $this->patchJson('/admin/vendors/'.$vendorId, ['revision' => 1, 'status' => 'suspended', 'review_note' => 'Review required'])->assertUnprocessable();
    }

    public function test_new_vendor_selects_existing_products_and_needs_approval_before_publication(): void
    {
        $id = $this->product();
        $owner = User::factory()->create(['is_vendor' => true]);
        $vendorId = DB::table('vendors')->insertGetId(['user_id' => $owner->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->put('/vendor/products/'.$id, ['revision' => 0, 'price' => '45.50', 'available' => 1])->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendorId, 'product_id' => $id, 'price_paise' => 4550, 'available' => true]);
        $this->assertDatabaseCount('products', 1);
        $this->post('/vendor/products', ['name' => 'Custom product'])->assertMethodNotAllowed();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->putJson('/vendor/onboarding', ['revision' => 0, 'business_name' => 'Shop', 'pickup_address' => 'Road', 'city' => 'City', 'postal_code' => '560001', 'service_postal_codes' => ['560001']])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patchJson('/admin/vendors/'.$vendorId, ['revision' => 0, 'status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_checkout_rechecks_changed_vendor_price_and_availability(): void
    {
        $this->area();
        $id = $this->product();
        $offer = $this->publishProduct($id, 6000);
        $customer = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $customer->id, 'token_hash' => hash('sha256', 'price-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $input = ['request_key' => (string) Str::uuid(), 'items' => [['id' => $id, 'quantity' => 1]], 'address' => ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Road', 'city' => 'City', 'pin' => '560001'], 'payment' => 'cod', 'delivery' => 'standard', 'expected_total' => 8500];
        $this->withToken('price-token')->postJson('/api/customer/quote', ['items' => $input['items'], 'postal_code' => '560001'])->assertOk()->assertJsonPath('data.bill.total', 8500);
        DB::table('vendor_products')->where('id', $offer)->update(['price_paise' => 7000]);
        $this->postJson('/api/customer/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        DB::table('vendor_products')->where('id', $offer)->update(['available' => false]);
        $this->postJson('/api/customer/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_vendor_prices_are_isolated_and_stale_or_invalid_changes_rejected(): void
    {
        $id = $this->product();
        $offerA = $this->publishProduct($id, 6000);
        $offerB = $this->publishProduct($id, 7000);
        $owner = $this->owner($offerA);
        $foreignVendor = DB::table('vendor_products')->where('id', $offerB)->value('vendor_id');
        $this->actingAs($owner)->put('/vendor/products/'.$id, ['revision' => 1, 'available' => 1, 'price' => '55.25', 'vendor_id' => $foreignVendor])->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['id' => $offerA, 'price_paise' => 5525, 'revision' => 2]);
        $this->assertDatabaseHas('vendor_products', ['id' => $offerB, 'price_paise' => 7000, 'revision' => 1]);
        $this->putJson('/vendor/products/'.$id, ['revision' => 1, 'available' => 1, 'price' => '50'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->putJson('/vendor/products/'.$id, ['revision' => 2, 'available' => 1, 'price' => '0'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->putJson('/vendor/products/'.$id, ['revision' => 2, 'available' => 1, 'price' => '1.001'])->assertUnprocessable();
        $this->get('/vendor/products?category=Fresh&q=Milk')->assertOk()->assertSee('Milk');
        DB::table('products')->where('id', $id)->update(['active' => false]);
        $this->put('/vendor/products/'.$id, ['revision' => 2, 'available' => 0])->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/vendor/products')->assertForbidden();
        $this->get('/admin/vendors')->assertForbidden();
    }

    public function test_catalogue_deduplicates_products_and_uses_approved_available_local_offers(): void
    {
        $id = $this->product();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $a = $this->publishProduct($id, 6000, ['560001']);
        $b = $this->publishProduct($id, 5000, ['110001']);
        $this->getJson('/api/products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.price_paise', 5000)->assertJsonMissingPath('data.0.vendor_id')->assertJsonPath('data.0.stock_quantity', null);
        $this->getJson('/api/products?postal_code=560001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.price_paise', 6000);
        $this->getJson('/api/products?postal_code=400001')->assertJsonCount(0, 'data');
        DB::table('vendors')->where('id', DB::table('vendor_products')->where('id', $b)->value('vendor_id'))->update(['status' => 'pending']);
        $this->getJson('/api/products')->assertJsonPath('data.0.price_paise', 6000);
        DB::table('vendor_products')->where('id', $a)->update(['available' => false]);
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
        $this->getJson('/api/products/'.$id)->assertNotFound();
    }

    public function test_mixed_vendor_checkout_snapshots_prices_and_admin_edits_do_not_manage_stock(): void
    {
        $this->area();
        $milk = $this->product();
        $rice = $this->product('Rice');
        $milkOffer = $this->publishProduct($milk, 6000);
        $riceOffer = $this->publishProduct($rice, 8000);
        $customer = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $customer->id, 'token_hash' => hash('sha256', 'market-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $address = ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Garden Road', 'city' => 'Bengaluru', 'pin' => '560001'];
        $input = ['request_key' => (string) Str::uuid(), 'items' => [['id' => $milk, 'quantity' => 1], ['id' => $rice, 'quantity' => 1]], 'address' => $address, 'payment' => 'cod', 'delivery' => 'standard', 'expected_total' => 16500];
        $this->withToken('market-token')->postJson('/api/customer/quote', ['items' => $input['items'], 'postal_code' => '560001'])->assertOk();
        $this->postJson('/api/customer/orders', $input)->assertCreated();
        $this->postJson('/api/customer/orders', $input)->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('order_items', ['product_id' => $milk, 'vendor_product_id' => $milkOffer, 'unit_price_paise' => 6000, 'vendor_name' => 'Fixture vendor']);
        $this->assertDatabaseHas('order_items', ['product_id' => $rice, 'vendor_product_id' => $riceOffer, 'unit_price_paise' => 8000]);
        $order = DB::table('orders')->first();
        $items = DB::table('order_items')->pluck('quantity', 'id')->all();
        $this->actingAs($this->owner($milkOffer))->get('/vendor')->assertOk()->assertSee('Milk')->assertDontSee('Rice');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/orders/'.$order->id.'/edit')->assertOk()->assertSee('Vendor available');
        $items[array_key_first($items)] = 2;
        $this->put('/admin/orders/'.$order->id, ['revision' => 0, 'address' => $address, 'quantities' => $items, 'delivery_preference' => 'standard', 'reason' => 'Customer requests another pack'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_paise' => 22500]);
        $this->patch('/admin/orders/'.$order->id, ['status' => 'cancelled', 'revision' => DB::table('orders')->where('id', $order->id)->value('revision'), 'reason' => 'Customer requested cancellation'])->assertRedirect();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('products', ['id' => $milk, 'stock_quantity' => 0]);
        $this->assertDatabaseHas('vendor_products', ['id' => $milkOffer, 'available' => true]);
    }

    public function test_suspension_cannot_be_bypassed_by_resubmitting_onboarding(): void
    {
        $id = $this->product();
        $offer = $this->publishProduct($id, 6000);
        $owner = $this->owner($offer);
        DB::table('vendors')->where('user_id', $owner->id)->update(['status' => 'suspended']);
        $this->area();
        $this->actingAs($owner)->put('/vendor/onboarding', ['revision' => 0, 'business_name' => 'Shop', 'pickup_address' => 'Road', 'city' => 'City', 'postal_code' => '560001', 'service_postal_codes' => ['560001']])->assertForbidden();
        $this->put('/vendor/products/'.$id, ['revision' => 1, 'available' => 1, 'price' => '60'])->assertForbidden();
        $this->getJson('/api/products')->assertJsonCount(0, 'data');
    }
}
