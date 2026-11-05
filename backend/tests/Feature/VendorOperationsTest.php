<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VendorOperations;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function setupMenus(): object
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);

        return DB::table('vendors')->first();
    }

    private function order(object $vendor): object
    {
        $token = $this->postJson('/api/customer/auth/login', ['email' => 'food.customer@demo.invalid', 'password' => 'FoodDemo@1234'])->assertOk()->json('token');
        $product = DB::table('vendor_products')->where('vendor_id', $vendor->id)->value('product_id');
        $locality = DB::table('service_localities')->where('name', 'Demo · Restaurant Test Area')->value('id');
        $payload = ['vendor_id' => $vendor->id, 'items' => [['id' => $product, 'quantity' => 1]], 'postal_code' => '248001', 'address' => ['locality_id' => $locality, 'name' => 'Food customer', 'phone' => '9000091100', 'street' => '12 Test Street'], 'payment' => 'cod', 'delivery' => 'standard', 'request_key' => (string) Str::uuid()];
        $bill = $this->withToken($token)->postJson('/api/customer/quote', $payload)->assertOk()->json('data.bill');
        $reference = $this->postJson('/api/customer/orders', $payload + ['expected_total' => $bill['total']])->assertCreated()->json('data.id');

        return DB::table('orders')->where('reference', $reference)->first();
    }

    public function test_weekly_hours_use_ist_and_overnight_service_includes_the_previous_day(): void
    {
        $hours = array_fill(0, 7, ['enabled' => false, 'opens' => '09:00', 'closes' => '22:00']);
        $hours[6] = ['enabled' => true, 'opens' => '22:00', 'closes' => '02:00'];
        $vendor = (object) ['operating_mode' => 'open', 'opening_hours' => json_encode($hours)];
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 21:59', 'Asia/Kolkata'));
        $this->assertSame('closed', VendorOperations::status($vendor));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 22:00', 'Asia/Kolkata'));
        $this->assertSame('open', VendorOperations::status($vendor));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 01:59', 'Asia/Kolkata'));
        $this->assertSame('open', VendorOperations::status($vendor));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 02:00', 'Asia/Kolkata'));
        $this->assertSame('closed', VendorOperations::status($vendor));
        $vendor->operating_mode = 'busy';
        $this->assertSame('busy', VendorOperations::status($vendor));
    }

    public function test_busy_mode_removes_purchasable_products_but_shows_restaurant_status_and_blocks_checkout(): void
    {
        $vendor = $this->setupMenus();
        $order = $this->order($vendor);
        DB::table('vendors')->where('id', $vendor->id)->update(['operating_mode' => 'busy']);
        $menu = $this->getJson('/api/products?postal_code=248001&vendor_id='.$vendor->id)->assertOk()->assertJsonCount(0, 'data')->json('vendors');
        $this->assertSame('busy', collect($menu)->firstWhere('id', $vendor->id)['ordering_status']);
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->postJson('/api/customer/orders', ['vendor_id' => $vendor->id, 'items' => [['id' => $item->product_id, 'quantity' => 1]], 'address' => json_decode($order->delivery_address, true), 'delivery' => 'standard', 'payment' => 'cod', 'request_key' => (string) Str::uuid(), 'expected_total' => $order->total_paise])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->postJson('/api/customer/quote', ['vendor_id' => $vendor->id, 'items' => [['id' => $item->product_id, 'quantity' => 1]], 'postal_code' => '248001', 'address' => json_decode($order->delivery_address, true)])->assertUnprocessable();
        $this->actingAs(User::find($vendor->user_id))->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'accept', 'preparation_minutes' => 35])->assertRedirect();
        $readyAt = DB::table('order_items')->where('id', $item->id)->value('estimated_ready_at');
        $this->assertNotNull($readyAt);
        $this->assertEqualsWithDelta(35, now()->diffInMinutes(Carbon::parse($readyAt)), 0.1);
    }

    public function test_operating_settings_validate_schedule_and_revisions_without_requiring_reapproval(): void
    {
        $vendor = $this->setupMenus();
        $hours = array_fill(0, 7, ['enabled' => true, 'opens' => '09:00', 'closes' => '22:00']);
        $payload = ['revision' => 0, 'operating_mode' => 'busy', 'preparation_minutes' => 30, 'schedule_enabled' => true, 'hours' => $hours];
        $this->actingAs(User::find($vendor->user_id))->get('/vendor')->assertOk()->assertSee('Hours & order availability', false);
        $this->put('/vendor/operations', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'status' => 'approved', 'operating_mode' => 'busy', 'preparation_minutes' => 30, 'revision' => 1]);
        $this->put('/vendor/operations', $payload)->assertConflict();
        $payload['hours'][0]['closes'] = '09:00';
        $this->putJson('/vendor/operations', $payload + [])->assertUnprocessable();
    }

    public function test_rejection_cancels_only_the_owned_single_shop_order_and_restores_stock_once(): void
    {
        $vendor = $this->setupMenus();
        $order = $this->order($vendor);
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        $owner = User::find($vendor->user_id);
        $other = User::find(DB::table('vendors')->where('id', '!=', $vendor->id)->value('user_id'));
        $input = ['revision' => 0, 'reason' => 'Required ingredients are unavailable'];
        $this->actingAs($other)->post('/vendor/orders/'.$order->id.'/reject', $input)->assertNotFound();
        $this->actingAs($owner)->post('/vendor/orders/'.$order->id.'/reject', $input)->assertRedirect();
        $this->post('/vendor/orders/'.$order->id.'/reject', $input)->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'vendor_rejected_by' => $vendor->id]);
        $this->assertDatabaseHas('vendor_products', ['id' => $item->vendor_product_id, 'stock_quantity' => 30]);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('action', 'Vendor rejected order')->count());
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'type' => 'payment', 'status' => 'voided']);
    }

    public function test_mixed_shop_rejection_requests_admin_review_instead_of_cancelling_other_shops(): void
    {
        $vendor = $this->setupMenus();
        $order = $this->order($vendor);
        $otherVendor = DB::table('vendors')->where('id', '!=', $vendor->id)->first();
        DB::table('order_items')->insert(['order_id' => $order->id, 'vendor_id' => $otherVendor->id, 'product_name' => 'Other shop item', 'pack_size' => '1 portion', 'quantity' => 1, 'unit_price_paise' => 10000]);
        $this->actingAs(User::find($vendor->user_id))->post('/vendor/orders/'.$order->id.'/reject', ['revision' => 0, 'reason' => 'Restaurant equipment is unavailable'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'received']);
        $this->assertDatabaseHas('customer_requests', ['order_id' => $order->id, 'type' => 'cancellation', 'status' => 'requested']);
        $this->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 1, 'action' => 'accept'])->assertConflict();
        $rider = DB::table('delivery_partners')->insertGetId(['name' => 'Review rider', 'phone' => '9000091188', 'active' => true, 'is_online' => true]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $rider, 'status' => 'approved']);
        DB::table('service_locality_partner')->insert(['delivery_partner_id' => $rider, 'locality_id' => $order->locality_id]);
        DB::table('orders')->where('id', $order->id)->update(['delivery_partner_id' => $rider]);
        DB::table('partner_tokens')->insert(['delivery_partner_id' => $rider, 'phone' => '9000091188', 'token_hash' => hash('sha256', 'review-rider'), 'expires_at' => now()->addDay()]);
        $data = $this->withToken('review-rider')->getJson('/api/partner/orders/'.$order->reference)->assertOk()->assertJsonPath('data.vendor_review_required', true)->json('data');
        $this->postJson('/api/partner/orders/'.$order->reference.'/pickup', ['revision' => $data['revision'], 'stop_id' => $data['stops'][0]['id']])->assertConflict();

    }
}
