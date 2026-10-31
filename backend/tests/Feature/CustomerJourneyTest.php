<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ServiceLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_locality_distance_cod_delivery_and_support_resolution(): void
    {
        Http::preventStrayRequests();
        $this->artisan('app:setup-dehradun')->assertSuccessful();
        $this->artisan('app:setup-dehradun')->assertSuccessful();
        $this->assertDatabaseCount('service_localities', 5);
        $this->getJson('/api/locations')->assertJsonCount(0, 'data');
        $locality = DB::table('service_localities')->where('name', 'Dalanwala')->first();
        $rider = DB::table('delivery_partners')->insertGetId(['name' => 'Test rider', 'phone' => '9234567890', 'active' => true, 'is_online' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $rider, 'legal_name' => 'Test rider', 'address' => 'Test Road', 'city' => 'Dehradun', 'postal_code' => '248001', 'document_type' => 'passport', 'identity_path' => 'test-identity.jpg', 'address_path' => 'test-address.jpg', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true]);
        $settings = ['city_id' => $locality->city_id, 'name' => 'Dalanwala', 'postal_code' => '248001', 'pricing_mode' => 'locality_per_km', 'distance_km' => '2.5', 'rate' => '10.00', 'active' => 1, 'partner_ids' => [$rider]];
        $this->actingAs($admin)->post('/admin/locations/localities/'.$locality->id, $settings)->assertRedirect();
        $this->get('/admin/locations')->assertOk()->assertSee('Hub-to-locality distance');
        $this->getJson('/api/locations')->assertJsonCount(1, 'data.0.cities.0.localities');
        $product = DB::table('products')->insertGetId(['name' => 'Fresh milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 10000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $offer = $this->publishProduct($product, 10000, ['248001']);
        DB::table('vendor_products')->where('id', $offer)->update(['stock_quantity' => 4]);
        $customerToken = $this->postJson('/api/customer/auth/register', ['name' => 'Test customer', 'email' => 'journey@example.test', 'phone' => '9123456780', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertOk()->json('token');
        $address = ['locality_id' => $locality->id, 'label' => 'Home', 'name' => 'Test customer', 'phone' => '9123456780', 'street' => '12 Test Road'];
        $this->withToken($customerToken)->postJson('/api/customer/addresses', $address)->assertOk()->assertJsonPath('data.addresses.0.city', 'Dehradun');
        $this->getJson('/api/products?locality_id='.$locality->id)->assertJsonCount(1, 'data');
        $items = [['id' => $product, 'quantity' => 1]];
        $quote = $this->postJson('/api/customer/quote', ['items' => $items, 'postal_code' => '248001', 'address' => $address])->assertOk()->assertJsonPath('data.rules.mode', 'locality_per_km')->assertJsonPath('data.rules.rate_paise_per_km', 1000)->assertJsonPath('data.rules.distance_meters', 2500)->assertJsonPath('data.bill.delivery', 2500)->json('data');
        $input = ['request_key' => (string) Str::uuid(), 'items' => $items, 'address' => $address, 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => $quote['bill']['total']];
        $reference = $this->postJson('/api/customer/orders', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/customer/orders', $input)->assertCreated()->assertJsonPath('data.id', $reference);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer, 'stock_quantity' => 3]);
        $order = DB::table('orders')->where('reference', $reference)->first();
        $this->patch('/admin/orders/'.$order->id.'/partner', ['revision' => 0, 'delivery_partner_id' => $rider, 'reason' => 'Assign to test area rider'])->assertRedirect();
        $this->app['env'] = 'local';
        config(['partner.local_otp' => true]);
        $challenge = $this->postJson('/api/partner/auth/request', ['phone' => '9234567890'])->assertOk()->json();
        $riderToken = $this->postJson('/api/partner/auth/verify', ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['local_code']])->assertOk()->json('token');
        $riderOrder = $this->withToken($riderToken)->getJson('/api/partner/orders/'.$reference)->assertOk()->assertJsonMissingPath('data.delivery_paise')->json('data');
        $picked = $this->postJson('/api/partner/orders/'.$reference.'/pickup', ['revision' => $riderOrder['revision'], 'stop_id' => $riderOrder['stops'][0]['id']])->assertOk()->json('data');
        $this->postJson('/api/partner/orders/'.$reference.'/complete', ['revision' => $picked['revision'], 'payment_collected' => true, 'receipt' => 'TEST-COD-001'])->assertOk();
        $this->withToken($customerToken)->getJson('/api/customer/orders/'.$reference)->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.paymentStatus', 'paid');
        $this->get('/api/customer/orders/'.$reference.'/invoice', ['Accept' => 'text/html'])->assertOk();
        $ticket = $this->postJson('/api/customer/support/tickets', ['request_key' => (string) Str::uuid(), 'subject' => 'Question about delivered order', 'body' => 'Please confirm the delivery details.', 'order_reference' => $reference])->assertCreated()->json('data.id');
        $this->app['env'] = 'testing';
        $this->post('/admin/support/'.$ticket.'/messages', ['request_key' => (string) Str::uuid(), 'body' => 'Your delivery and COD receipt are confirmed.', 'status' => 'resolved'])->assertRedirect();
        $this->getJson('/api/customer/support/tickets/'.$ticket)->assertJsonPath('data.ticket.status', 'resolved')->assertJsonCount(2, 'data.messages');
        Http::assertNothingSent();
    }

    public function test_distance_required_for_per_km_and_saved_order_fee_does_not_change(): void
    {
        $this->artisan('app:setup-dehradun')->assertSuccessful();
        $area = DB::table('service_localities')->first();
        $admin = User::factory()->create(['is_admin' => true]);
        $input = ['city_id' => $area->city_id, 'name' => $area->name, 'postal_code' => '248001', 'pricing_mode' => 'locality_per_km', 'rate' => '10', 'active' => 1];
        $this->actingAs($admin)->postJson('/admin/locations/localities/'.$area->id, $input)->assertUnprocessable()->assertJsonValidationErrors('distance_km');
        $this->assertDatabaseHas('service_localities', ['id' => $area->id, 'active' => false, 'distance_meters' => null]);
        $this->post('/admin/locations/localities/'.$area->id, $input + ['distance_km' => '2.501'])->assertRedirect();
        $rules = ServiceLocations::rules(['locality_id' => $area->id]);
        $this->assertSame(2501, $rules['fee_paise']);
        $this->post('/admin/locations/localities/'.$area->id, array_replace($input, ['distance_km' => '3.000', 'rate' => '12']))->assertRedirect();
        $this->assertSame(2501, $rules['fee_paise']);
        $this->assertSame(3600, ServiceLocations::rules(['locality_id' => $area->id])['fee_paise']);
    }
}
