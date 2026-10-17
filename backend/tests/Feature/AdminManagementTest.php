<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(array $changes = []): int
    {
        $id = DB::table('products')->insertGetId($changes + ['name' => 'Milk', 'category' => 'Fresh', 'pack_size' => '1 litre', 'price_paise' => 6000, 'active' => true, 'stock_quantity' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $this->publishProduct($id);

        return $id;
    }

    private function order(): array
    {
        DB::table('delivery_areas')->insertOrIgnore(['name' => 'Test area', 'postal_code' => '560001', 'fee_paise' => 2500, 'minimum_paise' => 0, 'free_above_paise' => 30000, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $productId = $this->product();
        $address = ['name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Garden Road', 'city' => 'Bengaluru', 'pin' => '560001'];
        $id = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'delivery_address' => json_encode($address), 'status' => 'received', 'payment_status' => 'pending', 'subtotal_paise' => 12000, 'discount_paise' => 1200, 'delivery_paise' => 2500, 'total_paise' => 13300, 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('order_items')->insertGetId(['order_id' => $id, 'product_id' => $productId, 'product_name' => 'Milk', 'pack_size' => '1 litre', 'unit_price_paise' => 6000, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        return [$id, $productId, ['revision' => 0, 'address' => $address, 'quantities' => [$itemId => 3], 'delivery_preference' => 'evening', 'admin_notes' => 'Call before delivery', 'reason' => 'Customer requested another pack']];
    }

    public function test_management_requires_admin(): void
    {
        foreach (['categories', 'sub-categories', 'delivery-partners'] as $section) {
            $this->get('/admin/'.$section)->assertRedirect('/admin/login');
            $this->post('/admin/'.$section, [])->assertRedirect('/admin/login');
        }
        $this->actingAs(User::factory()->create());
        foreach (['categories', 'sub-categories', 'delivery-partners'] as $section) {
            $this->get('/admin/'.$section)->assertForbidden();
            $this->post('/admin/'.$section, [])->assertForbidden();
        }
        $this->putJson('/admin/orders/1', [])->assertForbidden();
    }

    public function test_categories_can_be_created_renamed_and_hidden_without_losing_products(): void
    {
        $this->admin();
        $this->product();
        $id = DB::table('categories')->where('name', 'Fresh')->value('id');
        $this->post('/admin/categories', ['name' => 'Pantry', 'active' => 1])->assertRedirect('/admin/categories');
        $this->assertDatabaseHas('categories', ['name' => 'Pantry']);
        $this->put('/admin/categories/'.$id, ['name' => 'Vegetables', 'active' => 0])->assertRedirect('/admin/categories');
        $this->assertDatabaseHas('products', ['category' => 'Vegetables']);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/admin/categories', ['name' => 'Pantry', 'active' => 1])->assertUnprocessable();
        $this->get('/admin/categories')->assertOk()->assertSee('Vegetables');
        $this->get('/admin/categories/'.$id.'/edit')->assertOk();
    }

    public function test_sub_categories_enforce_parent_and_visibility(): void
    {
        $this->admin();
        $fresh = DB::table('categories')->where('name', 'Fresh')->value('id');
        $home = DB::table('categories')->where('name', 'Home')->value('id');
        $this->post('/admin/sub-categories', ['name' => 'Fruit', 'category_id' => $fresh, 'active' => 1])->assertRedirect('/admin/sub-categories');
        $sub = DB::table('sub_categories')->value('id');
        $this->postJson('/admin/products', ['name' => 'Apple', 'category' => 'Home', 'sub_category_id' => $sub, 'pack_size' => '1 kg', 'price' => '100', 'stock_quantity' => 4, 'active' => 1])->assertUnprocessable();
        $this->post('/admin/products', ['name' => 'Apple', 'category' => 'Fresh', 'sub_category_id' => $sub, 'pack_size' => '1 kg', 'price' => '100', 'stock_quantity' => 4, 'active' => 1])->assertRedirect('/admin/products');
        $this->assertDatabaseHas('products', ['name' => 'Apple', 'sub_category_id' => $sub]);
        $this->putJson('/admin/sub-categories/'.$sub, ['name' => 'Fruit', 'category_id' => $home, 'active' => 1])->assertUnprocessable();
        $this->put('/admin/sub-categories/'.$sub, ['name' => 'Fruit', 'category_id' => $fresh, 'active' => 0])->assertRedirect();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/admin/sub-categories')->assertOk();
        $this->get('/admin/sub-categories/'.$sub.'/edit')->assertOk();
    }

    public function test_delivery_partners_can_be_edited_and_assigned_to_order(): void
    {
        $this->admin();
        [$id,$productId,$payload] = $this->order();
        $this->post('/admin/delivery-partners', ['name' => 'Asha', 'phone' => '9123456780', 'vehicle' => 'Scooter', 'active' => 1])->assertRedirect('/admin/delivery-partners');
        $partner = DB::table('delivery_partners')->value('id');
        DB::table('delivery_partner_kycs')->insert(['delivery_partner_id' => $partner, 'legal_name' => 'Asha', 'address' => 'Test address', 'city' => 'Bengaluru', 'postal_code' => '560001', 'document_type' => 'passport', 'identity_path' => 'test-id.jpg', 'address_path' => 'test-address.jpg', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->put('/admin/orders/'.$id, $payload + ['delivery_partner_id' => $partner])->assertRedirect('/admin/orders/'.$id);
        $this->assertDatabaseHas('orders', ['id' => $id, 'delivery_partner_id' => $partner]);
        $this->get('/admin/delivery-partners')->assertOk()->assertSee('Asha');
        $this->get('/admin/delivery-partners/'.$partner.'/edit')->assertOk();
        $this->put('/admin/delivery-partners/'.$partner, ['name' => 'Asha Updated', 'phone' => '9123456780', 'vehicle' => 'Bike', 'active' => 0])->assertRedirect();
        $this->assertDatabaseHas('delivery_partners', ['id' => $partner, 'name' => 'Asha Updated', 'active' => false]);
        [$second,,$secondPayload] = $this->order();
        $this->putJson('/admin/orders/'.$second, $secondPayload + ['delivery_partner_id' => $partner])->assertUnprocessable();
    }

    public function test_order_edit_recalculates_stock_totals_and_records_admin_changes(): void
    {
        $this->admin();
        [$id,$productId,$payload] = $this->order();
        $this->get('/admin/orders/'.$id.'/edit')->assertOk()->assertSee('Edit order');
        $this->put('/admin/orders/'.$id, $payload)->assertRedirect('/admin/orders/'.$id);
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 7]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'total_paise' => 18700, 'revision' => 1, 'delivery_preference' => 'evening']);
        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'quantity' => 3, 'unit_price_paise' => 6000]);
        $this->assertDatabaseHas('order_events', ['order_id' => $id, 'action' => 'Order modified']);
        $this->putJson('/admin/orders/'.$id, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 7]);
        $this->get('/admin/orders/'.$id)->assertOk()->assertSee('Customer requested another pack');
    }

    public function test_order_edit_can_replace_items_and_restore_removed_stock(): void
    {
        $this->admin();
        [$id,$productId,$payload] = $this->order();
        $newId = $this->product(['name' => 'Rice', 'price_paise' => 20000, 'stock_quantity' => 4]);
        $payload['quantities'] = array_map(fn () => 0, $payload['quantities']);
        $payload['add_product_id'] = $newId;
        $payload['add_quantity'] = 1;
        $this->put('/admin/orders/'.$id, $payload)->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 10]);
        $this->assertDatabaseHas('products', ['id' => $newId, 'stock_quantity' => 3]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'total_paise' => 20500]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $id, 'product_id' => $productId]);
    }

    public function test_insufficient_stock_empty_items_and_final_orders_reject_edits(): void
    {
        $this->admin();
        [$id,$productId,$payload] = $this->order();
        $payload['quantities'] = array_map(fn () => 11, $payload['quantities']);
        $this->putJson('/admin/orders/'.$id, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 8]);
        $payload['quantities'] = array_map(fn () => 0, $payload['quantities']);
        $this->putJson('/admin/orders/'.$id, $payload)->assertUnprocessable();
        DB::table('orders')->where('id', $id)->update(['status' => 'delivered']);
        $this->putJson('/admin/orders/'.$id, $payload)->assertUnprocessable();
        $this->get('/admin/orders/'.$id.'/edit')->assertUnprocessable();
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_order_edits_keep_coupon_when_subtotal_temporarily_drops_below_minimum(): void
    {
        $this->admin();
        [$id, $productId, $payload] = $this->order();
        $payload['quantities'] = array_map(fn () => 1, $payload['quantities']);
        $this->put('/admin/orders/'.$id, $payload)->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $id, 'coupon_code' => 'DROP10', 'discount_paise' => 0, 'total_paise' => 8500]);
        $itemId = DB::table('order_items')->where('order_id', $id)->value('id');
        $payload['revision'] = 1;
        $payload['quantities'] = [$itemId => 2];
        $this->put('/admin/orders/'.$id, $payload)->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $id, 'discount_paise' => 1200, 'total_paise' => 13300]);
        $this->assertDatabaseHas('products', ['id' => $productId, 'stock_quantity' => 8]);
    }
}
