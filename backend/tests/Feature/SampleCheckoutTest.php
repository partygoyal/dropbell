<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SampleCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SampleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $this->seed(SampleCatalogueSeeder::class);
        $product = DB::table('products')->where('is_sample', true)->first();

        return ['sample_checkout' => true, 'request_key' => (string) Str::uuid(), 'items' => [['id' => $product->id, 'quantity' => 2]], 'address' => ['name' => 'Test customer', 'phone' => '9876543210', 'street' => 'Test address', 'city' => 'Dehradun', 'pin' => '248001'], 'delivery' => 'standard', 'payment' => 'cod', 'expected_total' => $product->price_paise * 2];
    }

    public function test_guest_sample_cod_checkout_creates_owned_admin_order_once_and_reserves_sample_stock(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/customer/sample-quote', ['items' => $payload['items'], 'postal_code' => '248001'])->assertOk()->assertJsonPath('data.bill.total', $payload['expected_total'])->assertJsonPath('data.rules.name', 'Sample delivery (test only)');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/customer/sample-quote', ['items' => $payload['items'], 'postal_code' => '248001'])->assertOk();
        }
        $response = $this->postJson('/api/customer/sample-orders', $payload)->assertCreated()->assertJsonPath('data.isSample', true)->assertJsonPath('data.status', 'received');
        $reference = $response->json('data.id');
        $token = $response->json('token');
        $this->postJson('/api/customer/sample-orders', $payload)->assertCreated()->assertJsonPath('data.id', $reference);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 18]);
        $this->assertDatabaseHas('order_transactions', ['type' => 'payment', 'status' => 'voided', 'amount_paise' => 0]);
        $this->withToken($token)->getJson('/api/customer/orders/'.$reference)->assertOk()->assertJsonPath('data.isSample', true);
        $this->getJson('/api/customer/orders/not-owned')->assertNotFound();
        $id = DB::table('orders')->value('id');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/orders')->assertOk()->assertSee('TEST COD')->assertSee($reference);
        $this->get('/admin/orders/'.$id)->assertOk()->assertSee('TEST COD ORDER');
        $this->get('/admin')->assertOk()->assertSee('1 test orders')->assertDontSee('Sample checkout guest');
        $this->patch('/admin/orders/'.$id, ['revision' => 0, 'status' => 'cancelled', 'reason' => 'Finished checkout test'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $payload['items'][0]['id'], 'stock_quantity' => 20]);
    }

    public function test_sample_orders_require_explicit_confirmation_and_cannot_charge_or_invoice(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/customer/sample-orders', array_replace($payload, ['sample_checkout' => false]))->assertUnprocessable();
        $response = $this->postJson('/api/customer/sample-orders', $payload)->assertCreated();
        $this->withToken($response->json('token'));
        $id = DB::table('orders')->value('id');
        DB::table('orders')->where('id', $id)->update(['status' => 'delivered']);
        $this->getJson('/api/customer/orders/'.$response->json('data.id').'/invoice')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/admin/orders/'.$id.'/payment', ['reference' => 'not-real-money'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseHas('orders', ['id' => $id, 'payment_status' => 'pending']);
        $this->assertDatabaseMissing('order_transactions', ['status' => 'completed']);
        $this->assertDatabaseCount('order_invoices', 0);
    }

    public function test_guest_checkout_rejects_real_offers_bad_totals_and_excess_stock_without_creating_users(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/customer/sample-orders', array_replace($payload, ['expected_total' => 1]))->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        $tooMany = $payload;
        $tooMany['items'][0]['quantity'] = 21;
        $this->postJson('/api/customer/sample-orders', $tooMany)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
        $productId = $payload['items'][0]['id'];
        $this->publishProduct($productId, 6000, ['248001']);
        DB::table('delivery_areas')->insert(['name' => 'Real area', 'postal_code' => '248001', 'fee_paise' => 0, 'minimum_paise' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/customer/sample-quote', ['items' => $payload['items'], 'postal_code' => '248001'])->assertUnprocessable();
        $this->postJson('/api/customer/sample-orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 20]);
    }
}
