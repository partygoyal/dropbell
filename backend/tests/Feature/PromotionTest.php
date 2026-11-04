<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Promotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $changes = []): array
    {
        return $changes + ['title' => 'Weekend offer', 'description' => 'Save on essentials', 'code' => 'WEEKEND20', 'discount_type' => 'percent', 'discount_value' => 20, 'minimum' => 100, 'maximum_discount' => 50, 'active' => 1, 'show_banner' => 1, 'sort_order' => 0, 'starts_at' => '2026-01-01T00:00', 'ends_at' => '2027-01-01T00:00'];
    }

    public function test_only_admins_manage_promotions_and_active_banners_are_public(): void
    {
        $this->get('/admin/promotions')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->post('/admin/promotions', $this->input())->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Storage::fake('public');
        $this->post('/admin/promotions', $this->input(['banner' => UploadedFile::fake()->image('offer.jpg')]))->assertRedirect('/admin/promotions');
        $promotion = DB::table('promotions')->where('code', 'WEEKEND20')->first();
        Storage::disk('public')->assertExists($promotion->banner_path);
        $this->get('/admin/promotions')->assertOk()->assertSee('Weekend offer');
        $this->get('/admin/promotions/'.$promotion->id.'/edit')->assertOk();
        $this->getJson('/api/promotions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'WEEKEND20')->assertJsonPath('data.0.offer.value', '20%')->assertJsonPath('data.0.offer.minimum', 'On items ₹100+')->assertJsonMissingPath('data.0.discount_value');
        $this->put('/admin/promotions/'.$promotion->id, $this->input(['active' => 0, 'remove_banner' => 1]))->assertRedirect();
        Storage::disk('public')->assertMissing($promotion->banner_path);
        $this->getJson('/api/promotions')->assertJsonCount(0, 'data');
    }

    public function test_dates_coupons_and_uploads_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/admin/promotions', $this->input(['discount_value' => 101, 'ends_at' => '2025-01-01T00:00', 'banner' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')]))->assertUnprocessable()->assertJsonValidationErrors(['discount_value', 'ends_at', 'banner']);
        $this->post('/admin/promotions', $this->input(['code' => 'weekend20', 'discount_type' => 'fixed', 'discount_value' => 30]))->assertRedirect();
        $this->assertDatabaseHas('promotions', ['code' => 'WEEKEND20', 'discount_value' => 3000]);
        $this->postJson('/admin/promotions', $this->input())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->post('/admin/promotions', $this->input(['code' => null, 'discount_type' => 'none', 'discount_value' => null]))->assertRedirect();
    }

    public function test_coupon_minimum_cap_expiry_and_schedule_are_enforced(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/promotions', $this->input())->assertRedirect();
        $this->postJson('/api/promotions/validate', ['code' => 'weekend20', 'subtotal' => 40000])->assertOk()->assertJsonPath('data.discount', 5000);
        $this->postJson('/api/promotions/validate', ['code' => 'WEEKEND20', 'subtotal' => 9000])->assertUnprocessable();
        $this->assertSame(5000, Promotions::discount(Promotions::resolve('WEEKEND20'), 40000));
        DB::table('promotions')->where('code', 'WEEKEND20')->update(['ends_at' => now()->subMinute()]);
        $this->postJson('/api/promotions/validate', ['code' => 'WEEKEND20', 'subtotal' => 40000])->assertUnprocessable();
        $this->getJson('/api/promotions')->assertJsonCount(0, 'data');
        DB::table('promotions')->where('code', 'WEEKEND20')->update(['ends_at' => null, 'starts_at' => now()->addDay()]);
        $this->getJson('/api/promotions')->assertJsonCount(0, 'data');
    }

    public function test_custom_coupon_is_used_by_checkout_and_snapshotted_for_order_edits(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/admin/promotions', $this->input(['discount_type' => 'fixed', 'discount_value' => 30]))->assertRedirect();
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'promotion-test'), 'expires_at' => now()->addDay()]);
        $locality = $this->serviceLocality();
        $product = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 10000, 'active' => true, 'stock_quantity' => 10]);
        $this->publishProduct($product, 10000);
        $address = ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Road', 'city' => 'City', 'pin' => '560001', 'locality_id' => $locality];
        $payload = ['items' => [['id' => $product, 'quantity' => 2]], 'coupon' => 'WEEKEND20', 'address' => $address, 'postal_code' => '560001'];
        $bill = $this->withToken('promotion-test')->postJson('/api/customer/quote', $payload)->assertOk()->assertJsonPath('data.bill.discount', 3000)->json('data.bill');
        $this->postJson('/api/customer/orders', $payload + ['request_key' => (string) Str::uuid(), 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => $bill['total']])->assertCreated();
        $order = DB::table('orders')->first();
        $rules = json_decode($order->coupon_rules, true);
        $this->assertSame(3000, $rules['discount_value']);
        DB::table('promotions')->where('code', 'WEEKEND20')->update(['discount_value' => 9000, 'active' => false]);
        $this->assertSame(3000, Promotions::discount(Promotions::orderRules($order), 20000, false));
        $this->assertSame('WEEKEND20', $order->coupon_code);
    }
}
