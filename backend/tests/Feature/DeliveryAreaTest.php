<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_area_list_and_add_form_have_separate_admin_pages(): void
    {
        $this->get('/admin/delivery-areas/create')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/delivery-areas/create')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->area();
        $this->get('/admin/delivery-areas')->assertOk()->assertSee('Central')->assertSee(route('admin.delivery-areas.create'))->assertDontSee('name="postal_code"', false);
        $this->get('/admin/delivery-areas/create')->assertOk()->assertSee('name="postal_code"', false)->assertSee('Cancel');
        $this->from('/admin/delivery-areas/create')->post('/admin/delivery-areas', [])->assertRedirect('/admin/delivery-areas/create')->assertSessionHasErrors('name');
    }

    public function test_legacy_rules_remain_available_for_pre_master_orders(): void
    {
        $this->area();
        $rules = DeliveryPricing::rules('560001');
        $this->assertSame(4000, DeliveryPricing::fee($rules, 20000));
        $this->assertSame('Central', $rules['name']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quotes_require_authentication_and_valid_items(): void
    {
        $this->postJson('/api/customer/quote', [])->assertUnauthorized();
        $this->customer();
        $this->postJson('/api/customer/quote', ['items' => [], 'postal_code' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['items', 'postal_code']);
    }

    public function test_legacy_minimum_rejects_insufficient_subtotal(): void
    {
        $this->area(['minimum_paise' => 25000]);
        $this->expectException(ValidationException::class);
        DeliveryPricing::fee(DeliveryPricing::rules('560001'), 20000);
    }

    public function test_free_delivery_threshold_uses_subtotal_before_discount(): void
    {
        $this->area();
        $rules = DeliveryPricing::rules('560001');
        $this->assertSame(0, DeliveryPricing::fee($rules, 30000));
        $this->assertSame(4000, DeliveryPricing::fee($rules, 29999));
    }

    public function test_legacy_fee_snapshot_does_not_change_with_current_rules(): void
    {
        $id = $this->area();
        $snapshot = DeliveryPricing::rules('560001');
        DB::table('delivery_areas')->where('id', $id)->update(['fee_paise' => 5000]);
        $this->assertSame(4000, DeliveryPricing::fee($snapshot, 20000));
        $this->assertSame(5000, DeliveryPricing::fee(DeliveryPricing::rules('560001'), 20000));
    }

    public function test_legacy_inactive_postal_area_cannot_be_requoted(): void
    {
        $this->area(['active' => false]);
        $this->expectException(ValidationException::class);
        DeliveryPricing::rules('560001');
    }
}
