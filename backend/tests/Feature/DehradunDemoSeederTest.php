<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DehradunDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DehradunDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_local_idempotent_and_links_customers_and_partners_to_dehradun(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DehradunDemoSeeder::class);
        $this->seed(DehradunDemoSeeder::class);
        $this->assertDatabaseCount('vendors', 4);
        $this->assertDatabaseCount('delivery_partners', 5);
        $this->assertDatabaseCount('users', 24);
        $this->assertSame(20, DB::table('users')->where('is_vendor', false)->where('is_admin', false)->count());
        $this->assertDatabaseCount('addresses', 20);
        $this->assertDatabaseCount('service_localities', 6);
        $this->assertDatabaseCount('service_locality_partner', 12);
        $this->assertDatabaseHas('service_cities', ['name' => 'Dehradun', 'active' => true]);
        $this->assertDatabaseHas('service_states', ['name' => 'Uttarakhand']);
        $this->assertDatabaseCount('delivery_partner_kycs', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_explicit_production_preview_import_preserves_existing_data_and_disables_demo_access(): void
    {
        $existing = User::factory()->create(['is_admin' => true]);
        $originalPassword = $existing->password;
        $this->app['env'] = 'production';
        $this->artisan('demo:import-dehradun')->assertFailed();
        $this->assertDatabaseCount('vendors', 0);
        $this->artisan('demo:import-dehradun --confirm-production')->assertSuccessful();
        $this->artisan('demo:import-dehradun --confirm-production')->assertSuccessful();
        $this->assertDatabaseCount('vendors', 4);
        $this->assertDatabaseCount('addresses', 20);
        $this->assertSame(0, DB::table('vendors')->where('status', 'approved')->count());
        $this->assertSame(0, DB::table('service_localities')->where('active', true)->count());
        $this->assertSame(0, DB::table('delivery_partners')->where('active', true)->count());
        $this->assertSame(0, DB::table('delivery_partners')->whereNotNull('password')->count());
        $customer = DB::table('users')->where('email', 'dehradun.customer1@demo.invalid')->first();
        $this->assertFalse(Hash::check('DemoLocal@1234', $customer->password));
        $this->assertSame($originalPassword, $existing->fresh()->password);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        (new DehradunDemoSeeder)->run();
    }
}
