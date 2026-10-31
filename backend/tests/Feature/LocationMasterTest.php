<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ServiceLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationMasterTest extends TestCase
{
    use RefreshDatabase;

    private function locality(): int
    {
        $s = DB::table('service_states')->insertGetId(['name' => 'Uttarakhand', 'active' => true]);
        $c = DB::table('service_cities')->insertGetId(['state_id' => $s, 'name' => 'Dehradun', 'active' => true]);

        return DB::table('service_localities')->insertGetId(['city_id' => $c, 'name' => 'Rajpur', 'postal_code' => '248001', 'fee_paise' => 2500, 'active' => true]);
    }

    private function customer(): void
    {
        $user = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'location-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('location-token');
    }

    public function test_admin_master_and_many_to_many_assignments(): void
    {
        $this->get('/admin/locations')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/locations')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/locations/states', ['name' => 'Uttarakhand', 'active' => 1])->assertRedirect('/admin/locations');
        $state = DB::table('service_states')->value('id');
        $this->post('/admin/locations/cities', ['state_id' => $state, 'name' => 'Dehradun', 'active' => 1])->assertRedirect('/admin/locations');
        $city = DB::table('service_cities')->value('id');
        $p = DB::table('delivery_partners')->insertGetId(['name' => 'Rider', 'phone' => '9123456789', 'active' => true]);
        foreach (['Rajpur', 'Clement Town'] as $name) {
            $this->post('/admin/locations/localities', ['city_id' => $city, 'name' => $name, 'postal_code' => '248001', 'fee' => '25.50', 'partner_ids' => [$p], 'active' => 1])->assertRedirect('/admin/locations');
        }
        $this->assertDatabaseCount('service_locality_partner', 2);
        $this->get('/admin/locations')->assertOk()->assertSee('Clement Town');
        $this->getJson('/api/locations')->assertOk()->assertJsonPath('data.0.cities.0.localities.0.fee_paise', 2550);
        $this->post('/admin/locations/states/'.$state, ['name' => 'Uttarakhand', 'active' => 0])->assertRedirect();
        $this->getJson('/api/locations')->assertJsonCount(0, 'data');
    }

    public function test_quote_checkout_and_retries_need_no_external_api(): void
    {
        Http::preventStrayRequests();
        $locality = $this->locality();
        $this->customer();
        $p = DB::table('products')->insertGetId(['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 L', 'price_paise' => 10000, 'stock_quantity' => 10, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->publishProduct($p, 10000, ['248001']);
        $address = ['locality_id' => $locality, 'name' => 'Customer', 'phone' => '9123456780', 'street' => '12 Lane', 'city' => 'Fake city', 'pin' => '110001'];
        $items = [['id' => $p, 'quantity' => 1]];
        $this->postJson('/api/customer/quote', ['items' => $items, 'postal_code' => '110001', 'address' => $address])->assertOk()->assertJsonPath('data.bill.total', 12500)->assertJsonPath('data.rules.mode', 'locality');
        $input = ['request_key' => (string) Str::uuid(), 'address' => $address, 'items' => $items, 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => 12500];
        $ref = $this->postJson('/api/customer/orders', $input)->assertCreated()->json('data.id');
        $order = DB::table('orders')->first();
        $this->assertSame('Dehradun', json_decode($order->delivery_address, true)['city']);
        DB::table('service_localities')->where('id', $locality)->update(['active' => false]);
        $this->postJson('/api/customer/orders', $input)->assertCreated()->assertJsonPath('data.id', $ref);
        $input['request_key'] = (string) Str::uuid();
        $this->postJson('/api/customer/orders', $input)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_addresses_reject_missing_mismatched_and_inactive_locations(): void
    {
        $id = $this->locality();
        $this->customer();
        $address = ['label' => 'Home', 'name' => 'Customer', 'phone' => '9123456780', 'street' => '12 Lane'];
        $this->postJson('/api/customer/addresses', $address)->assertUnprocessable();
        $this->postJson('/api/customer/addresses', $address + ['locality_id' => $id, 'state_id' => 999])->assertUnprocessable();
        $this->postJson('/api/customer/addresses', $address + ['locality_id' => $id])->assertOk()->assertJsonPath('data.addresses.0.city', 'Dehradun')->assertJsonPath('data.addresses.0.locality', 'Rajpur');
        DB::table('service_cities')->update(['active' => false]);
        $this->postJson('/api/customer/addresses', $address + ['locality_id' => $id])->assertUnprocessable();
    }

    public function test_email_registration_login_role_isolation_and_logout(): void
    {
        $data = ['name' => 'Customer', 'email' => 'New@Example.test', 'phone' => '9123456780', 'password' => 'good-password', 'password_confirmation' => 'good-password', 'is_admin' => true];
        $token = $this->postJson('/api/customer/auth/register', $data)->assertOk()->json('token');
        $user = User::where('email', 'new@example.test')->first();
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('good-password', $user->password));
        $this->withToken($token)->getJson('/api/customer/me')->assertOk();
        $this->postJson('/api/customer/logout')->assertOk();
        $this->getJson('/api/customer/me')->assertUnauthorized();
        $this->postJson('/api/customer/auth/login', ['email' => 'new@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/customer/auth/login', ['email' => 'NEW@example.test', 'password' => 'good-password'])->assertOk()->assertJsonStructure(['token']);
        User::factory()->create(['email' => 'admin@example.test', 'password' => 'good-password', 'is_admin' => true]);
        $this->postJson('/api/customer/auth/login', ['email' => 'admin@example.test', 'password' => 'good-password'])->assertUnprocessable();
    }

    public function test_rider_must_be_assigned_to_locality(): void
    {
        $id = $this->locality();
        $p = DB::table('delivery_partners')->insertGetId(['name' => 'Rider', 'phone' => '9123456789', 'active' => true]);
        $this->assertSame(0, ServiceLocations::eligible(DB::table('delivery_partners'), $id)->count());
        DB::table('service_locality_partner')->insert(['locality_id' => $id, 'delivery_partner_id' => $p]);
        ServiceLocations::assertPartner($p, $id);
        $this->assertSame(1, ServiceLocations::eligible(DB::table('delivery_partners'), $id)->count());
        DB::table('service_locality_partner')->delete();
        $this->expectException(ValidationException::class);
        ServiceLocations::assertPartner($p, $id);
    }
}
