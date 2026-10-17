<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_users_and_details_require_admin(): void
    {
        $customer = User::factory()->create();
        $this->get('/admin/customers')->assertRedirect('/admin/login');
        $this->get('/admin/customers/'.$customer->id)->assertRedirect('/admin/login');
        $this->actingAs($customer)->get('/admin/customers')->assertForbidden();
        $this->get('/admin/customers/'.$customer->id)->assertForbidden();
    }

    public function test_dashboard_and_search_show_customers_and_exclude_admin_accounts(): void
    {
        $admin = User::factory()->create(['name' => 'Hidden Administrator', 'is_admin' => true]);
        $customer = User::factory()->create(['name' => 'Anika Customer', 'phone' => '9876543210']);
        User::factory()->create(['name' => 'Other Customer', 'phone' => '9123456789']);
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Registered users')->assertSee('Anika Customer')->assertSee('9876543210');
        $this->get('/admin/customers?q=987654')->assertOk()->assertSee('Anika Customer')->assertDontSee('Other Customer');
        $this->get('/admin/customers?q=Hidden')->assertOk()->assertSee('No registered users match this search.');
        $this->get('/admin/customers/'.$admin->id)->assertNotFound();
        $this->get('/admin/customers/999')->assertNotFound();
        $this->get('/admin/customers/'.$customer->id)->assertOk()->assertSee('No orders placed yet.');
    }

    public function test_customer_details_show_only_their_addresses_and_orders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create(['name' => 'Anika']);
        $other = User::factory()->create();
        DB::table('addresses')->insert(['user_id' => $customer->id, 'label' => 'Home', 'name' => 'Anika', 'phone' => '9876543210', 'street' => 'Customer Garden Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('addresses')->insert(['user_id' => $other->id, 'label' => 'Home', 'name' => 'Other', 'phone' => '9123456789', 'street' => 'Other Private Street', 'city' => 'Delhi', 'postal_code' => '110001', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $reference = (string) Str::uuid();
        $otherReference = (string) Str::uuid();
        foreach ([[$customer->id, $reference], [$other->id, $otherReference]] as [$userId,$ref]) {
            DB::table('orders')->insert(['reference' => $ref, 'user_id' => $userId, 'delivery_address' => '{}', 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal_paise' => 10000, 'discount_paise' => 0, 'delivery_paise' => 0, 'total_paise' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($admin)->get('/admin/customers/'.$customer->id)->assertOk()->assertSee('Customer Garden Road')->assertSee($reference)->assertDontSee('Other Private Street')->assertDontSee($otherReference)->assertSee('100.00');
    }
}
