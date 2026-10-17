<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'test-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('test-token');
    }

    private function address(): array
    {
        return ['label' => 'Home', 'name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Garden Road', 'city' => 'Bengaluru', 'pin' => '560001'];
    }

    private function payload(): array
    {
        DB::table('delivery_areas')->insertOrIgnore(['name' => 'Test area', 'postal_code' => '560001', 'fee_paise' => 2500, 'minimum_paise' => 0, 'free_above_paise' => 30000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 6000, 'active' => true, 'stock_quantity' => 4, 'created_at' => now(), 'updated_at' => now()]);

        $this->publishProduct($id);

        return ['request_key' => (string) Str::uuid(), 'items' => [['id' => $id, 'quantity' => 2]], 'address' => $this->address(), 'coupon' => 'DROP10', 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => 13300];
    }

    public function test_otp_and_logout(): void
    {
        $this->app['env'] = 'local';
        config(['customer.local_otp' => true]);
        $challenge = $this->postJson('/api/customer/auth/request', ['phone' => '9123456789'])->assertOk()->json();
        $input = ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']];
        $token = $this->postJson('/api/customer/auth/verify', $input)->assertOk()->json('token');
        $this->postJson('/api/customer/auth/verify', $input)->assertUnprocessable();
        $this->withToken($token)->getJson('/api/customer/me')->assertOk()->assertJsonPath('data.phone', '9123456789');
        $this->postJson('/api/customer/logout')->assertOk();
        $this->getJson('/api/customer/me')->assertUnauthorized();
    }

    public function test_unconfigured_login_and_unauthenticated_access(): void
    {
        config(['customer.local_otp' => false]);
        $this->postJson('/api/customer/auth/request', ['phone' => '9123456789'])->assertStatus(503);
        $this->getJson('/api/customer/me')->assertUnauthorized();
    }

    public function test_address_defaults_and_ownership(): void
    {
        $this->customer();
        $first = $this->postJson('/api/customer/addresses', $this->address())->assertOk()->json('data.addresses.0.id');
        $this->postJson('/api/customer/addresses', array_replace($this->address(), ['label' => 'Work']))->assertOk();
        $this->deleteJson('/api/customer/addresses/'.$first)->assertOk()->assertJsonPath('data.addresses.0.isDefault', true);
        $other = User::factory()->create();
        $row = $this->address();
        unset($row['pin']);
        $id = DB::table('addresses')->insertGetId($row + ['user_id' => $other->id, 'postal_code' => '560001', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson('/api/customer/addresses/'.$id, $this->address())->assertNotFound();
        $this->deleteJson('/api/customer/addresses/'.$id)->assertNotFound();
    }

    public function test_order_stock_totals_idempotency_and_ownership(): void
    {
        $this->customer();
        $payload = $this->payload();
        $reference = $this->postJson('/api/customer/orders', $payload)->assertCreated()->assertJsonPath('data.bill.total', 13300)->json('data.id');
        $this->postJson('/api/customer/orders', $payload)->assertCreated()->assertJsonPath('data.id', $reference);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 4]);
        $this->getJson('/api/customer/orders/'.$reference)->assertOk();
        DB::table('orders')->update(['user_id' => User::factory()->create()->id]);
        $this->getJson('/api/customer/orders/'.$reference)->assertNotFound();
    }

    public function test_invalid_orders_do_not_reserve_stock(): void
    {
        $this->customer();
        $payload = $this->payload();
        $this->postJson('/api/customer/orders', array_replace($payload, ['expected_total' => 1]))->assertUnprocessable();
        DB::table('vendor_products')->update(['available' => false]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_admin_cancellation_restores_stock_once(): void
    {
        $this->customer();
        $payload = $this->payload();
        $this->postJson('/api/customer/orders', $payload)->assertCreated();
        $id = DB::table('orders')->value('id');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patch('/admin/orders/'.$id, ['status' => 'cancelled'])->assertRedirect();
        $this->patch('/admin/orders/'.$id, ['status' => 'cancelled'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 4]);
        $this->patchJson('/admin/orders/'.$id, ['status' => 'received'])->assertUnprocessable();
        $this->get('/admin/orders/'.$id)->assertOk();
    }

    public function test_order_notifications_require_admin_and_link_only_new_orders(): void
    {
        $this->getJson('/admin/orders/notifications')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->getJson('/admin/orders/notifications')->assertForbidden();
        $this->customer();
        $this->postJson('/api/customer/orders', $this->payload())->assertCreated();
        $id = DB::table('orders')->value('id');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/admin/orders/notifications')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('orders.0.url', route('admin.orders.show', $id));
        $this->patch('/admin/orders/'.$id, ['status' => 'picking'])->assertRedirect();
        $this->getJson('/admin/orders/notifications')->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'orders');
    }

    public function test_checkout_rejects_products_in_inactive_categories_and_sub_categories(): void
    {
        $this->customer();
        $payload = $this->payload();
        $categoryId = DB::table('categories')->where('name', 'Fresh')->value('id');
        DB::table('categories')->where('id', $categoryId)->update(['active' => false]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable();
        DB::table('categories')->where('id', $categoryId)->update(['active' => true]);
        $subCategoryId = DB::table('sub_categories')->insertGetId(['category_id' => $categoryId, 'name' => 'Dairy', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $payload['items'][0]['id'])->update(['sub_category_id' => $subCategoryId]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 4]);
    }
}
