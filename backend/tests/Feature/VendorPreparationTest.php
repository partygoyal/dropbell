<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_order_alert_acceptance_preparation_pickup_delivery_and_cod_are_consistent(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $product = DB::table('products')->where('name', 'Demo · Paneer Butter Masala')->first();
        $offer = DB::table('vendor_products')->where('product_id', $product->id)->first();
        $vendor = DB::table('vendors')->find($offer->vendor_id);
        $owner = User::find($vendor->user_id);
        $locality = DB::table('service_localities')->where('name', 'Demo · Restaurant Test Area')->value('id');
        $customerToken = $this->postJson('/api/customer/auth/login', ['email' => 'food.customer@demo.invalid', 'password' => 'FoodDemo@1234'])->assertOk()->json('token');
        $payload = ['vendor_id' => $vendor->id, 'items' => [['id' => $product->id, 'quantity' => 1]], 'address' => ['locality_id' => $locality, 'name' => 'Food customer', 'phone' => '9000091100', 'street' => 'Test Road'], 'payment' => 'cod', 'delivery' => 'standard', 'request_key' => (string) Str::uuid()];
        $bill = $this->withToken($customerToken)->postJson('/api/customer/quote', $payload + ['postal_code' => '248001'])->assertOk()->json('data.bill');
        $this->postJson('/api/customer/orders', $payload + ['expected_total' => $bill['total']])->assertCreated();
        $this->postJson('/api/customer/orders', $payload + ['expected_total' => $bill['total']])->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $order = DB::table('orders')->first();
        $this->actingAs($owner)->getJson('/vendor/order-alerts?after=0')->assertOk()->assertJsonPath('orders.0.id', $order->id);
        $this->get('/vendor')->assertOk()->assertSee('Accept order');
        $other = User::find(DB::table('vendors')->where('id', '!=', $vendor->id)->value('user_id'));
        $this->actingAs($other)->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'accept'])->assertNotFound();
        $this->actingAs($owner)->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'ready'])->assertUnprocessable();
        $this->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'accept'])->assertRedirect();
        $this->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'accept'])->assertRedirect();
        $this->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => 0, 'action' => 'ready'])->assertConflict();
        $rider = DB::table('delivery_partners')->insertGetId(['name' => 'Food rider', 'phone' => '9000091199', 'password' => Hash::make('TestRider@123'), 'active' => true, 'is_online' => true]);
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $rider, 'status' => 'approved']);
        DB::table('service_locality_partner')->insert(['locality_id' => $locality, 'delivery_partner_id' => $rider]);
        $admin = User::factory()->create(['is_admin' => true]);
        $revision = DB::table('orders')->where('id', $order->id)->value('revision');
        $this->actingAs($admin)->patch('/admin/orders/'.$order->id.'/partner', ['revision' => $revision, 'delivery_partner_id' => $rider, 'reason' => 'Food journey test'])->assertRedirect()->assertSessionHasNoErrors();
        $riderToken = $this->postJson('/api/partner/auth/login', ['phone' => '9000091199', 'password' => 'TestRider@123'])->assertOk()->json('token');
        $delivery = $this->withToken($riderToken)->getJson('/api/partner/orders/'.$order->reference)->assertOk()->assertJsonPath('data.stops.0.preparation_status', 'preparing')->assertJsonPath('data.stops.0.pickup_ready', false)->json('data');
        $this->postJson('/api/partner/orders/'.$order->reference.'/pickup', ['revision' => $delivery['revision'], 'stop_id' => $delivery['stops'][0]['id']])->assertConflict();
        $revision = DB::table('orders')->where('id', $order->id)->value('revision');
        $this->actingAs($owner)->post('/vendor/orders/'.$order->id.'/preparation', ['revision' => $revision, 'action' => 'ready'])->assertRedirect();
        $delivery = $this->withToken($riderToken)->getJson('/api/partner/orders/'.$order->reference)->assertOk()->assertJsonPath('data.stops.0.pickup_ready', true)->json('data');
        $picked = $this->postJson('/api/partner/orders/'.$order->reference.'/pickup', ['revision' => $delivery['revision'], 'stop_id' => $delivery['stops'][0]['id']])->assertOk()->json('data');
        $completion = ['revision' => $picked['revision'], 'payment_collected' => true, 'receipt' => 'FOOD-COD-001'];
        $this->postJson('/api/partner/orders/'.$order->reference.'/complete', $completion)->assertOk();
        $this->postJson('/api/partner/orders/'.$order->reference.'/complete', $completion)->assertOk();
        $this->withToken($customerToken)->getJson('/api/customer/orders/'.$order->reference)->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.paymentStatus', 'paid');
        $this->assertDatabaseHas('vendor_products', ['id' => $offer->id, 'stock_quantity' => 29]);
        $this->assertSame(1, DB::table('order_transactions')->where('order_id', $order->id)->where('type', 'payment')->count());
        $this->assertSame(1, DB::table('app_notifications')->where('owner_type', 'partner')->where('event_type', 'like', 'vendor-ready:%')->count());
        $this->assertSame(2, DB::table('order_events')->where('order_id', $order->id)->where('action', 'like', 'Vendor%')->count());
    }
}
