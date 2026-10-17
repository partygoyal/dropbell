<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryAreaTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): void
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'delivery-test'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('delivery-test');
    }

    private function area(array $changes = []): int
    {
        return DB::table('delivery_areas')->insertGetId($changes + ['name' => 'Central', 'postal_code' => '560001', 'fee_paise' => 4000, 'minimum_paise' => 10000, 'free_above_paise' => 30000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(int $quantity = 2): array
    {
        $id = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 10000, 'stock_quantity' => 20, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->publishProduct($id);

        return ['request_key' => (string) Str::uuid(), 'items' => [['id' => $id, 'quantity' => $quantity]], 'address' => ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Road', 'city' => 'Bengaluru', 'pin' => '560001'], 'coupon' => 'DROP10', 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => 22000];
    }

    public function test_delivery_area_management_is_admin_only_and_validates_money_and_postal_codes(): void
    {
        $input = ['name' => 'Central', 'postal_code' => '560001', 'fee' => '40.50', 'minimum' => '100', 'free_above' => '300', 'active' => 1];
        $this->get('/admin/delivery-areas')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->post('/admin/delivery-areas', $input)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/delivery-areas', $input)->assertRedirect('/admin/delivery-areas');
        $id = DB::table('delivery_areas')->value('id');
        $this->assertDatabaseHas('delivery_areas', ['id' => $id, 'fee_paise' => 4050, 'minimum_paise' => 10000]);
        $this->get('/admin/delivery-areas')->assertOk()->assertSee('Central');
        $this->get('/admin/delivery-areas/'.$id.'/edit')->assertOk();
        $this->postJson('/admin/delivery-areas', $input)->assertUnprocessable()->assertJsonValidationErrors('postal_code');
        $this->put('/admin/delivery-areas/'.$id, array_replace($input, ['active' => 0, 'free_above' => null]))->assertRedirect();
        $this->assertDatabaseHas('delivery_areas', ['id' => $id, 'active' => false, 'free_above_paise' => null]);
        $this->postJson('/admin/delivery-areas', array_replace($input, ['postal_code' => '123', 'fee' => '-1', 'free_above' => '50']))->assertUnprocessable()->assertJsonValidationErrors(['postal_code', 'fee', 'free_above']);
    }

    public function test_quote_uses_catalogue_prices_and_does_not_reserve_stock(): void
    {
        $this->customer();
        $this->area();
        $payload = $this->payload();
        $this->postJson('/api/customer/quote', ['items' => $payload['items'], 'postal_code' => '560001', 'coupon' => 'DROP10', 'subtotal' => 1])->assertOk()->assertJsonPath('data.bill.subtotal', 20000)->assertJsonPath('data.bill.delivery', 4000)->assertJsonPath('data.bill.total', 22000)->assertJsonPath('data.rules.name', 'Central');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 20]);
    }

    public function test_quotes_require_authentication_and_valid_items(): void
    {
        $this->postJson('/api/customer/quote', [])->assertUnauthorized();
        $this->customer();
        $this->postJson('/api/customer/quote', ['items' => [], 'postal_code' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['items', 'postal_code']);
    }

    public function test_unknown_inactive_and_below_minimum_areas_reject_checkout_without_stock_changes(): void
    {
        $this->customer();
        $payload = $this->payload();
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('postal_code');
        $area = $this->area(['active' => false]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable();
        DB::table('delivery_areas')->where('id', $area)->update(['active' => true, 'minimum_paise' => 25000]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 20]);
    }

    public function test_free_delivery_threshold_uses_subtotal_before_discount(): void
    {
        $this->customer();
        $this->area();
        $payload = $this->payload(3);
        $payload['expected_total'] = 27000;
        $this->postJson('/api/customer/orders', $payload)->assertCreated()->assertJsonPath('data.bill.delivery', 0)->assertJsonPath('data.bill.total', 27000);
        $this->assertDatabaseHas('orders', ['subtotal_paise' => 30000, 'discount_paise' => 3000, 'delivery_paise' => 0]);
    }

    public function test_fee_changes_require_a_new_quote_but_existing_order_retries_keep_the_order(): void
    {
        $this->customer();
        $area = $this->area();
        $payload = $this->payload();
        DB::table('delivery_areas')->where('id', $area)->update(['fee_paise' => 5000]);
        $this->postJson('/api/customer/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        $this->assertDatabaseCount('orders', 0);
        $payload['expected_total'] = 23000;
        $reference = $this->postJson('/api/customer/orders', $payload)->assertCreated()->json('data.id');
        $rules = json_decode(DB::table('orders')->value('delivery_rules'), true);
        $this->assertSame(5000, $rules['fee_paise']);
        DB::table('delivery_areas')->where('id', $area)->update(['active' => false]);
        $this->postJson('/api/customer/orders', $payload)->assertCreated()->assertJsonPath('data.id', $reference);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_admin_edits_keep_saved_rules_but_revalidate_a_changed_postal_code(): void
    {
        $this->customer();
        $area = $this->area();
        $payload = $this->payload();
        $this->postJson('/api/customer/orders', $payload)->assertCreated();
        $order = DB::table('orders')->first();
        $item = DB::table('order_items')->first();
        DB::table('delivery_areas')->where('id', $area)->update(['fee_paise' => 9000, 'active' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $edit = ['revision' => 0, 'address' => $payload['address'], 'quantities' => [$item->id => 2], 'delivery_preference' => 'standard', 'reason' => 'Update contact'];
        $this->put('/admin/orders/'.$order->id, $edit)->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'delivery_paise' => 4000, 'total_paise' => 22000]);
        $item = DB::table('order_items')->first();
        $edit['revision'] = 1;
        $edit['quantities'] = [$item->id => 2];
        $edit['address']['pin'] = '110001';
        $this->putJson('/admin/orders/'.$order->id, $edit)->assertUnprocessable()->assertJsonValidationErrors('postal_code');
        $this->area(['name' => 'North', 'postal_code' => '110001', 'fee_paise' => 1000]);
        $this->put('/admin/orders/'.$order->id, $edit)->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'delivery_paise' => 1000, 'total_paise' => 19000]);
    }
}
