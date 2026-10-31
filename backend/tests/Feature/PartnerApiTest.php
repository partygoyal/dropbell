<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private function partner(string $phone = '9123456780'): int
    {
        $id = DB::table('delivery_partners')->insertGetId(['name' => 'Asha', 'phone' => $phone, 'vehicle' => 'Scooter', 'active' => true, 'is_online' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $id, 'legal_name' => 'Asha Kumar', 'address' => '12 Road', 'city' => 'Dehradun', 'postal_code' => '248001', 'document_type' => 'passport', 'identity_path' => 'identity.jpg', 'address_path' => 'address.jpg', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function signIn(int $id): void
    {
        DB::table('partner_tokens')->insert(['delivery_partner_id' => $id, 'phone' => DB::table('delivery_partners')->where('id', $id)->value('phone'), 'token_hash' => hash('sha256', 'rider-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('rider-token');
    }

    private function order(int $partner): object
    {
        $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'delivery_partner_id' => $partner, 'delivery_address' => json_encode(['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Garden Road', 'city' => 'Dehradun', 'pin' => '248001']), 'subtotal_paise' => 10000, 'delivery_paise' => 2500, 'total_paise' => 12500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $id, 'product_name' => 'Milk', 'pack_size' => '1 litre', 'unit_price_paise' => 5000, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('orders')->find($id);
    }

    public function test_local_otp_is_single_use_and_logout_revokes_token(): void
    {
        $this->app['env'] = 'local';
        config(['partner.local_otp' => true]);
        $id = $this->partner();
        $challenge = $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertOk()->json();
        $token = $this->postJson('/api/partner/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']])->assertOk()->json('token');
        $this->assertDatabaseHas('partner_tokens', ['delivery_partner_id' => $id, 'token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/partner/me')->assertOk()->assertJsonPath('data.name', 'Asha');
        $this->patchJson('/api/partner/availability', ['is_online' => false, 'active' => false])->assertOk()->assertJsonPath('data.is_online', false);
        $this->assertDatabaseHas('delivery_partners', ['id' => $id, 'active' => true, 'is_online' => false]);
        $this->postJson('/api/partner/logout')->assertOk();
        $this->getJson('/api/partner/me')->assertUnauthorized();
    }

    public function test_local_otp_cannot_be_enabled_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['partner.local_otp' => true]);
        $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertStatus(503);
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => (string) Str::uuid(), 'code' => '123456'])->assertStatus(503);
        $this->assertDatabaseCount('partner_tokens', 0);
    }

    public function test_expired_and_exhausted_otps_cannot_sign_in(): void
    {
        $this->freezeTime();
        $this->app['env'] = 'local';
        config(['partner.local_otp' => true]);
        $this->partner();
        $challenge = $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->json();
        DB::table('partner_otps')->where('id', $challenge['challenge_id'])->update(['attempts' => 5]);
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']])->assertUnprocessable();
        DB::table('partner_otps')->where('id', $challenge['challenge_id'])->update(['attempts' => 0, 'expires_at' => now()->subSecond()]);
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']])->assertUnprocessable();
        $this->assertDatabaseCount('partner_tokens', 0);
    }

    public function test_riders_cannot_access_other_riders_or_sample_orders(): void
    {
        $this->getJson('/api/partner/orders')->assertUnauthorized();
        $partner = $this->partner();
        $own = $this->order($partner);
        $other = $this->order($this->partner('9234567890'));
        $sample = $this->order($partner);
        DB::table('orders')->where('id', $sample->id)->update(['is_sample' => true]);
        $this->signIn($partner);
        $this->getJson('/api/partner/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $own->reference);
        foreach ([$other, $sample] as $order) {
            $this->getJson('/api/partner/orders/'.$order->reference)->assertNotFound();
            $this->postJson('/api/partner/orders/'.$order->reference.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertNotFound();
            $this->postJson('/api/partner/orders/'.$order->reference.'/complete', ['revision' => 0, 'payment_collected' => true, 'receipt' => 'CASH'])->assertNotFound();
        }
        $this->assertDatabaseCount('partner_delivery_events', 0);
    }

    public function test_account_kyc_phone_and_token_expiry_are_rechecked(): void
    {
        $id = $this->partner();
        $this->signIn($id);
        DB::table('delivery_partners')->where('id', $id)->update(['active' => false]);
        $this->getJson('/api/partner/me')->assertUnauthorized();
        DB::table('delivery_partners')->where('id', $id)->update(['active' => true]);
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->update(['status' => 'pending']);
        $this->getJson('/api/partner/orders')->assertUnauthorized();
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $id)->update(['status' => 'approved']);
        DB::table('delivery_partners')->where('id', $id)->update(['phone' => '9234567890']);
        $this->getJson('/api/partner/me')->assertUnauthorized();
        DB::table('delivery_partners')->where('id', $id)->update(['phone' => '9123456780']);
        DB::table('partner_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/partner/me')->assertUnauthorized();
    }

    public function test_pickup_and_cod_completion_are_persisted_and_retries_do_not_duplicate_payment(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        $this->signIn($partner);
        $base = '/api/partner/orders/'.$order->reference;
        $this->postJson($base.'/complete', ['revision' => 0, 'payment_collected' => true, 'receipt' => 'CASH-1'])->assertConflict();
        $this->postJson($base.'/pickup', ['revision' => 9, 'stop_id' => 'hub'])->assertConflict();
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertOk()->assertJsonPath('data.status', 'out_for_delivery')->assertJsonPath('data.revision', 1);
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertOk();
        $this->postJson($base.'/complete', ['revision' => 0, 'payment_collected' => true, 'receipt' => 'CASH-1'])->assertConflict();
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => false, 'receipt' => 'CASH-1'])->assertUnprocessable();
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => true, 'receipt' => ' '])->assertUnprocessable();
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => true, 'receipt' => ' CASH-1 '])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => true, 'receipt' => 'CASH-1'])->assertOk();
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => true, 'receipt' => 'OTHER'])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered', 'payment_status' => 'paid', 'revision' => 2]);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'status' => 'completed', 'amount_paise' => 12500, 'reference' => 'CASH-1']);
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('partner_delivery_events', 2);
        $this->getJson($base)->assertJsonPath('data.stops.0.picked_up', true)->assertJsonPath('data.receipt', 'CASH-1');
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/orders/'.$order->id)->assertOk()->assertSee('Rider activity')->assertSee('Pickup confirmed')->assertSee('Delivery confirmed')->assertSee('CASH-1');
    }

    public function test_offline_cancelled_and_reassigned_deliveries_cannot_be_updated(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        $this->signIn($partner);
        $base = '/api/partner/orders/'.$order->reference;
        DB::table('delivery_partners')->where('id', $partner)->update(['is_online' => false]);
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertConflict();
        DB::table('delivery_partners')->where('id', $partner)->update(['is_online' => true]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertConflict();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'received', 'delivery_partner_id' => null]);
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertNotFound();
        $this->assertDatabaseCount('partner_delivery_events', 0);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'picked_up_at' => null]);
    }

    public function test_prepaid_order_does_not_collect_cod_again(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'paid']);
        $this->signIn($partner);
        $base = '/api/partner/orders/'.$order->reference;
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertOk()->assertJsonPath('data.cod_paise', 0);
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => false])->assertOk();
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'delivered']);
    }

    public function test_admin_completed_order_is_not_treated_as_successful_rider_collection(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered']);
        $this->signIn($partner);
        $this->postJson('/api/partner/orders/'.$order->reference.'/complete', ['revision' => 0, 'payment_collected' => true, 'receipt' => 'CASH'])->assertConflict();
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('partner_delivery_events', 0);
    }

    public function test_multiple_pickups_must_be_collected_in_sequence(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        $vendor = DB::table('vendors')->insertGetId(['user_id' => User::factory()->create(['is_vendor' => true])->id, 'business_name' => 'Fresh Store', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $order->id, 'vendor_id' => $vendor, 'vendor_name' => 'Fresh Store', 'pickup_address' => json_encode(['street' => 'Market Road', 'city' => 'Dehradun', 'pin' => '248001']), 'product_name' => 'Apples', 'pack_size' => '1 kg', 'unit_price_paise' => 1000, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($partner);
        $base = '/api/partner/orders/'.$order->reference;
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'vendor-'.$vendor])->assertConflict();
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'missing'])->assertUnprocessable();
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertOk()->assertJsonPath('data.status', 'picking');
        $this->postJson($base.'/complete', ['revision' => 1, 'payment_collected' => true, 'receipt' => 'CASH'])->assertConflict();
        $this->postJson($base.'/pickup', ['revision' => 1, 'stop_id' => 'vendor-'.$vendor])->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $this->assertDatabaseCount('partner_delivery_events', 2);
    }

    public function test_vendor_suspension_and_expired_items_block_pickup(): void
    {
        $partner = $this->partner();
        $order = $this->order($partner);
        $product = DB::table('products')->insertGetId(['name' => 'Expired milk', 'category' => 'Fresh', 'pack_size' => '1 L', 'price_paise' => 5000, 'expires_on' => today('Asia/Kolkata')->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->where('order_id', $order->id)->update(['product_id' => $product]);
        $this->signIn($partner);
        $base = '/api/partner/orders/'.$order->reference;
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'hub'])->assertConflict();
        $vendor = DB::table('vendors')->insertGetId(['user_id' => User::factory()->create(['is_vendor' => true])->id, 'status' => 'rejected', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->where('order_id', $order->id)->update(['vendor_id' => $vendor]);
        $this->postJson($base.'/pickup', ['revision' => 0, 'stop_id' => 'vendor-'.$vendor])->assertConflict();
        $this->assertDatabaseCount('partner_delivery_events', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'received', 'revision' => 0]);
    }

    public function test_new_otp_invalidates_previous_code_and_wrong_codes_use_attempts(): void
    {
        $this->app['env'] = 'local';
        config(['partner.local_otp' => true]);
        $partner = $this->partner();
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $partner)->update(['status' => 'pending']);
        $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertForbidden();
        DB::table('delivery_partner_kycs')->where('delivery_partner_id', $partner)->update(['status' => 'approved']);
        $old = $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertOk()->json();
        $fresh = $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertOk()->json();
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => $old['challenge_id'], 'code' => $old['local_code']])->assertUnprocessable();
        $this->postJson('/api/partner/auth/verify', ['challenge_id' => $fresh['challenge_id'], 'code' => '000000'])->assertUnprocessable();
        $this->assertDatabaseHas('partner_otps', ['id' => $fresh['challenge_id'], 'attempts' => 1]);
        $this->postJson('/api/partner/auth/request', ['phone' => '9123456780'])->assertTooManyRequests();
        $this->assertDatabaseCount('partner_tokens', 0);
    }
}
