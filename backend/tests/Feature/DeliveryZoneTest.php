<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    private function zone(array $changes = []): int
    {
        $city = DB::table('delivery_cities')->where('name', 'Bengaluru')->value('id') ?? DB::table('delivery_cities')->insertGetId(['name' => 'Bengaluru', 'state' => 'Karnataka', 'hub_latitude' => 12.97, 'hub_longitude' => 77.59, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('delivery_zones')->insertGetId($changes + ['city_id' => $city, 'name' => 'Central', 'center_latitude' => 12.97, 'center_longitude' => 77.59, 'radius_meters' => 5000, 'rate_paise_per_km' => 1000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function customer(): void
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'zone-customer'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('zone-customer');
    }

    private function payload(): array
    {
        $product = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 L', 'price_paise' => 10000, 'stock_quantity' => 10, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->publishProduct($product);

        return ['request_key' => (string) Str::uuid(), 'items' => [['id' => $product, 'quantity' => 1]], 'address' => ['name' => 'Customer', 'phone' => '9123456780', 'street' => '12 Road', 'city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.60], 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => 14251];
    }

    private function routing(): void
    {
        config(['services.google_routes.key' => 'fake-test-key']);
        DB::table('delivery_settings')->where('id', 1)->update(['per_km_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://routes.googleapis.com/directions/v2:computeRoutes' => Http::response(['routes' => [['distanceMeters' => 4251]]])]);
    }

    private function partner(string $phone = '9234567890'): int
    {
        $id = DB::table('delivery_partners')->insertGetId(['name' => 'Rider', 'phone' => $phone, 'active' => true, 'is_online' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $id, 'legal_name' => 'Rider', 'address' => 'Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'document_type' => 'passport', 'identity_path' => 'identity.jpg', 'address_path' => 'address.jpg', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_legacy_route_service_still_uses_fixed_hub_for_historical_support(): void
    {
        $this->zone(['center_latitude' => 12.98, 'center_longitude' => 77.60]);
        $this->routing();
        $address = ['city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.60];
        $rules = DeliveryZones::rules($address);
        $this->assertSame(4251, $rules['distance_meters']);
        $this->assertSame(4251, $rules['fee_paise']);
        Http::assertSent(fn ($r) => $r['origin']['location']['latLng'] === ['latitude' => 12.97, 'longitude' => 77.59]);
    }

    public function test_legacy_rate_calculation_rounds_to_paise(): void
    {
        $zone = $this->zone();
        $this->routing();
        DB::table('delivery_zones')->where('id', $zone)->update(['rate_paise_per_km' => 1250]);
        $rules = DeliveryZones::rules(['city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.6]);
        $this->assertSame(5314, $rules['fee_paise']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_pin_outside_zone_and_wrong_city_are_rejected_without_fallback(): void
    {
        $this->zone();
        $this->routing();
        foreach ([['city' => 'Bengaluru', 'pin' => '560001'], ['city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 28.6, 'longitude' => 77.6], ['city' => 'Delhi', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.6]] as $address) {
            try {
                DeliveryZones::rules($address);
                $this->fail('Unsupported geometry must fail');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        Http::assertNothingSent();
    }

    public function test_unavailable_routes_or_missing_credentials_never_produce_an_estimated_bill(): void
    {
        $this->zone();
        config(['services.google_routes.key' => null]);
        $this->expectException(HttpException::class);
        DeliveryZones::rules(['city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.6]);
    }

    public function test_smallest_matching_zone_is_selected_and_inactive_zones_are_excluded(): void
    {
        $this->zone(['name' => 'Large', 'radius_meters' => 10000]);
        $small = $this->zone(['name' => 'Small', 'radius_meters' => 3000]);
        $address = ['city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.6];
        $this->assertSame($small, DeliveryZones::matching($address)->id);
        DB::table('delivery_zones')->where('id', $small)->update(['active' => false]);
        $this->assertNotSame($small, DeliveryZones::matching($address)->id);
    }

    public function test_admin_can_manage_cities_rates_and_many_to_many_zone_memberships(): void
    {
        $this->get('/admin/delivery-zones')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->post('/admin/delivery-cities', [])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/delivery-cities', ['name' => 'Bengaluru', 'state' => 'Karnataka', 'hub_latitude' => 12.97, 'hub_longitude' => 77.59, 'active' => 1])->assertRedirect();
        $city = DB::table('delivery_cities')->value('id');
        $one = $this->partner();
        $two = $this->partner('9345678901');
        $input = ['city_id' => $city, 'name' => 'Zone A', 'center_latitude' => 12.97, 'center_longitude' => 77.59, 'radius_km' => '5.125', 'rate' => '8.25', 'active' => true, 'revision' => 0, 'partner_ids' => [$one, $two]];
        $this->post('/admin/delivery-zones', $input)->assertRedirect();
        $zone = DB::table('delivery_zones')->value('id');
        $this->assertDatabaseHas('delivery_zones', ['id' => $zone, 'rate_paise_per_km' => 825, 'radius_meters' => 5125]);
        $input['name'] = 'Zone B';
        $input['partner_ids'] = [$one];
        $this->post('/admin/delivery-zones', $input)->assertRedirect();
        $this->assertDatabaseCount('delivery_zone_partner', 3);
        $input['name'] = 'Zone A';
        $input['partner_ids'] = [$two];
        $this->put('/admin/delivery-zones/'.$zone, $input)->assertRedirect();
        $this->assertDatabaseMissing('delivery_zone_partner', ['zone_id' => $zone, 'delivery_partner_id' => $one]);
        $this->putJson('/admin/delivery-zones/'.$zone, $input)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->get('/admin/delivery-zones')->assertOk()->assertSee('Zone A')->assertSee('Zone B');
        $this->putJson('/admin/delivery-zone-settings', ['per_km_enabled' => true])->assertUnprocessable();
        config(['services.google_routes.key' => 'test']);
        $this->put('/admin/delivery-zone-settings', ['per_km_enabled' => true])->assertRedirect();
        $this->assertDatabaseHas('delivery_settings', ['id' => 1, 'per_km_enabled' => true]);
    }

    public function test_riders_must_belong_to_the_orders_zone_and_never_receive_pricing_breakdowns(): void
    {
        $zone = $this->serviceLocality();
        DB::table('service_localities')->where('id', $zone)->update(['fee_paise' => 4251]);
        $this->routing();
        $this->customer();
        $input = $this->payload();
        $input['address']['locality_id'] = $zone;
        $this->postJson('/api/customer/orders', $input)->assertCreated();
        $order = DB::table('orders')->first();
        $rider = $this->partner();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patchJson('/admin/orders/'.$order->id.'/partner', ['revision' => 0, 'delivery_partner_id' => $rider, 'reason' => 'Assign zone rider'])->assertUnprocessable()->assertJsonValidationErrors('delivery_partner_id');
        DB::table('service_locality_partner')->insert(['locality_id' => $zone, 'delivery_partner_id' => $rider]);
        $this->patch('/admin/orders/'.$order->id.'/partner', ['revision' => 0, 'delivery_partner_id' => $rider, 'reason' => 'Assign zone rider'])->assertRedirect();
        DB::table('partner_tokens')->insert(['delivery_partner_id' => $rider, 'phone' => '9234567890', 'token_hash' => hash('sha256', 'zone-rider'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->withToken('zone-rider')->getJson('/api/partner/orders/'.$order->reference)->assertOk()->assertJsonPath('data.cod_paise', 14251);
        $this->getJson('/api/partner/me')->assertOk()->assertJsonCount(1, 'data.locations')->assertJsonMissingPath('data.locations.0.fee_paise');
        $response->assertJsonMissingPath('data.deliveryPricing')->assertJsonMissingPath('data.rate_paise_per_km')->assertJsonMissingPath('data.delivery_paise')->assertJsonMissingPath('data.earnings');
        $this->assertDatabaseHas('app_notifications', ['owner_type' => 'partner', 'owner_id' => $rider, 'title' => 'New delivery assigned']);
    }

    public function test_address_coordinates_round_trip_and_are_validated(): void
    {
        $this->customer();
        $input = ['locality_id' => $this->serviceLocality(), 'label' => 'Home', 'name' => 'Customer', 'phone' => '9123456780', 'street' => '12 Road', 'city' => 'Bengaluru', 'pin' => '560001', 'latitude' => 12.98, 'longitude' => 77.60];
        $this->postJson('/api/customer/addresses', $input)->assertOk()->assertJsonPath('data.addresses.0.latitude', null)->assertJsonPath('data.addresses.0.locality_id', $input['locality_id']);
        $input['latitude'] = 91;
        $this->postJson('/api/customer/addresses', $input)->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->assertDatabaseCount('addresses', 1);
    }
}
