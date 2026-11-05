<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_choices_are_priced_on_server_validated_and_snapshotted(): void
    {
        Storage::fake('public');
        $this->seed(DemoRestaurantSeeder::class);
        $offer = DB::table('vendor_products')->first();
        $vendor = DB::table('vendors')->find($offer->vendor_id);
        $groups = [['name' => 'Size', 'min' => 1, 'max' => 1, 'choices' => [['id' => 'regular', 'name' => 'Regular', 'price_paise' => 0], ['id' => 'large', 'name' => 'Large', 'price_paise' => 5000]]], ['name' => 'Extras', 'min' => 0, 'max' => 1, 'choices' => [['id' => 'paneer', 'name' => 'Extra paneer', 'price_paise' => 2000]]]];
        DB::table('vendor_products')->where('id', $offer->id)->update(['option_groups' => json_encode($groups), 'menu_revision' => 1, 'dietary_label' => 'vegetarian', 'allergens' => '["milk"]']);
        $this->getJson('/api/products?postal_code=248001&vendor_id='.$vendor->id)->assertOk()->assertJsonPath('data.0.option_groups.0.name', 'Size')->assertJsonPath('data.0.dietary_label', 'vegetarian')->assertJsonPath('data.0.allergens', ['milk']);
        $token = $this->postJson('/api/customer/auth/login', ['email' => 'food.customer@demo.invalid', 'password' => 'FoodDemo@1234'])->assertOk()->json('token');
        $locality = DB::table('service_localities')->where('name', 'Demo · Restaurant Test Area')->value('id');
        $base = ['vendor_id' => $vendor->id, 'postal_code' => '248001', 'address' => ['locality_id' => $locality, 'name' => 'Test Customer', 'phone' => '9000091100', 'street' => 'Test Road']];
        $line = ['id' => $offer->product_id, 'quantity' => 2, 'options' => ['large', 'paneer'], 'menu_revision' => 1];
        $bill = $this->withToken($token)->postJson('/api/customer/quote', $base + ['items' => [$line]])->assertOk()->assertJsonPath('data.bill.subtotal', ($offer->price_paise + 7000) * 2)->json('data.bill');
        foreach ([[], ['unknown'], ['regular', 'large']] as $invalid) {
            $this->postJson('/api/customer/quote', $base + ['items' => [array_replace($line, ['options' => $invalid])]])->assertUnprocessable();
        }
        $payload = $base + ['items' => [$line], 'payment' => 'cod', 'delivery' => 'standard', 'request_key' => (string) Str::uuid(), 'expected_total' => $bill['total']];
        $this->postJson('/api/customer/orders', $payload)->assertCreated();
        $snapshot = json_decode(DB::table('order_items')->first()->selected_options, true);
        $this->assertSame('Large', $snapshot[0]['name']);
        $this->assertSame(5000, $snapshot[0]['price_paise']);
        DB::table('vendor_products')->where('id', $offer->id)->update(['menu_revision' => 2]);
        $this->postJson('/api/customer/orders', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('vendor_products', ['id' => $offer->id, 'stock_quantity' => 28]);
        $this->actingAs(User::find($vendor->user_id))->get('/vendor/menu/'.$offer->product_id)->assertOk()->assertSee('Dietary label');
        $other = User::find(DB::table('vendors')->where('id', '!=', $vendor->id)->value('user_id'));
        $this->actingAs($other)->get('/vendor/menu/'.$offer->product_id)->assertNotFound();
    }
}
