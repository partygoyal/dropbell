<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorOrderAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $vendor, bool $sample = false): int
    {
        $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'delivery_address' => '{}', 'subtotal_paise' => 1000, 'total_paise' => 1000, 'is_sample' => $sample, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $id, 'vendor_id' => $vendor, 'product_name' => 'Milk', 'pack_size' => '1 litre', 'quantity' => 1, 'unit_price_paise' => 1000]);

        return $id;
    }

    public function test_alert_cursor_is_vendor_scoped_excludes_samples_and_does_not_replay_initial_orders(): void
    {
        $owner = User::factory()->create(['is_vendor' => true]);
        $vendor = DB::table('vendors')->insertGetId(['user_id' => $owner->id]);
        $other = DB::table('vendors')->insertGetId(['user_id' => User::factory()->create(['is_vendor' => true])->id]);
        $old = $this->order($vendor);
        $this->get('/vendor/order-alerts')->assertRedirect('/vendor/login');
        $this->actingAs(User::factory()->create())->getJson('/vendor/order-alerts')->assertForbidden();
        $this->actingAs($owner)->getJson('/vendor/order-alerts')->assertOk()->assertJsonPath('cursor', $old)->assertJsonCount(0, 'orders');
        $this->order($other);
        $this->order($vendor, true);
        $new = $this->order($vendor);
        $this->getJson('/vendor/order-alerts?after='.$old)->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $new)->assertJsonPath('cursor', $new);
        $this->getJson('/vendor/order-alerts?after='.$new)->assertJsonCount(0, 'orders');
        $this->getJson('/vendor/order-alerts?after=-1')->assertUnprocessable();
        $this->get('/vendor')->assertOk()->assertSee('data-vendor-alerts', false);
    }
}
