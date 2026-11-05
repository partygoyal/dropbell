<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_requires_current_assignment_consent_and_active_delivery_and_is_hidden_when_stale_or_finished(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        foreach ([[$customer, 'customer'], [$other, 'other']] as [$user,$token]) {
            DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        }
        $rider = DB::table('delivery_partners')->insertGetId(['name' => 'Track rider', 'phone' => '9000091177', 'active' => true, 'is_online' => true]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $rider, 'status' => 'approved']);
        DB::table('partner_tokens')->insert(['delivery_partner_id' => $rider, 'phone' => '9000091177', 'token_hash' => hash('sha256', 'rider'), 'expires_at' => now()->addDay()]);
        $id = DB::table('orders')->insertGetId(['reference' => 'tracking-order', 'user_id' => $customer->id, 'delivery_partner_id' => $rider, 'status' => 'received', 'delivery_address' => json_encode(['latitude' => 30.32, 'longitude' => 78.04]), 'subtotal_paise' => 10000, 'total_paise' => 10000]);
        $this->withToken('rider')->postJson('/api/partner/orders/tracking-order/location/start')->assertConflict();
        DB::table('orders')->where('id', $id)->update(['status' => 'out_for_delivery']);
        $session = $this->postJson('/api/partner/orders/tracking-order/location/start')->assertOk()->json('sharing_session');
        $point = ['sharing_session' => $session, 'latitude' => 30.31, 'longitude' => 78.03, 'accuracy_meters' => 20];
        $this->postJson('/api/partner/orders/tracking-order/location', $point)->assertOk();
        $this->withToken('other')->getJson('/api/customer/orders/tracking-order/tracking')->assertNotFound();
        $this->withToken('customer')->getJson('/api/customer/orders/tracking-order/tracking')->assertOk()->assertJsonPath('data.sharing', true)->assertJsonPath('data.location.latitude', 30.31)->assertJsonPath('data.estimate.basis', 'rider_distance');
        DB::table('delivery_locations')->where('order_id', $id)->update(['recorded_at' => now()->subMinutes(3)]);
        $this->getJson('/api/customer/orders/tracking-order/tracking')->assertJsonPath('data.sharing', false)->assertJsonPath('data.location', null);
        $this->withToken('rider')->deleteJson('/api/partner/orders/tracking-order/location')->assertOk();
        $this->postJson('/api/partner/orders/tracking-order/location', $point)->assertConflict();
        $newSession = $this->postJson('/api/partner/orders/tracking-order/location/start')->assertOk()->json('sharing_session');
        $this->postJson('/api/partner/orders/tracking-order/location', array_replace($point, ['sharing_session' => $newSession, 'accuracy_meters' => 201]))->assertUnprocessable();
        DB::table('orders')->where('id', $id)->update(['status' => 'delivered']);
        $this->postJson('/api/partner/orders/tracking-order/location', array_replace($point, ['sharing_session' => $newSession]))->assertConflict();
        $this->withToken('customer')->getJson('/api/customer/orders/tracking-order/tracking')->assertJsonPath('data.location', null)->assertJsonPath('data.estimate', null);
    }
}
