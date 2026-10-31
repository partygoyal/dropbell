<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorProductSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'Milk', ?int $subCategory = null): int
    {
        return DB::table('products')->insertGetId(['name' => $name, 'category' => 'Fresh', 'sub_category_id' => $subCategory, 'pack_size' => '1 pack', 'price_paise' => 6000, 'stock_quantity' => 10, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vendor(): array
    {
        $owner = User::factory()->create(['is_vendor' => true]);
        $id = DB::table('vendors')->insertGetId(['user_id' => $owner->id, 'business_name' => 'Corner Shop', 'city' => 'Dehradun', 'status' => 'approved', 'service_postal_codes' => json_encode(['248001']), 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $id];
    }

    private function row(int $id, int $available = 1, int $revision = 0): array
    {
        return ['id' => $id, 'available' => $available, 'revision' => $revision, 'price' => '60.00', 'stock_quantity' => 8];
    }

    public function test_category_selection_loads_only_its_subcategories_and_matching_products(): void
    {
        [$owner] = $this->vendor();
        $fresh = DB::table('categories')->where('name', 'Fresh')->value('id');
        $other = DB::table('categories')->where('name', 'Breakfast')->value('id');
        $sub = DB::table('sub_categories')->insertGetId(['category_id' => $fresh, 'name' => 'Fruit', 'active' => true]);
        DB::table('sub_categories')->insert(['category_id' => $other, 'name' => 'Cereal', 'active' => true]);
        $apple = $this->product('Apple', $sub);
        $this->product('Other fresh product');

        $this->actingAs($owner)->get('/vendor/products?category=Fresh&sub_category_id='.$sub)->assertOk()
            ->assertViewHas('subCategories', fn ($rows) => $rows->pluck('name')->all() === ['Fruit'])
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$apple])
            ->assertSee('Save availability')->assertDontSee('Other fresh product');
    }

    public function test_mismatched_category_and_subcategory_are_rejected(): void
    {
        [$owner] = $this->vendor();
        $parent = DB::table('categories')->where('name', 'Breakfast')->value('id');
        $sub = DB::table('sub_categories')->insertGetId(['category_id' => $parent, 'name' => 'Bread', 'active' => true]);
        $this->actingAs($owner)->getJson('/vendor/products?category=Fresh&sub_category_id='.$sub)->assertUnprocessable()->assertJsonValidationErrors('sub_category_id');
    }

    public function test_vendor_saves_multiple_products_without_changing_other_products_or_shops(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $apple = $this->product('Apple');
        $unticked = $this->product('Not stocked here');
        $outside = $this->product('Outside this page');
        $foreign = $this->publishProduct($milk, 5000, ['248001']);
        DB::table('vendor_products')->insert(['vendor_id' => $vendor, 'product_id' => $outside, 'price_paise' => 7000, 'available' => true]);

        $this->actingAs($owner)->put('/vendor/product-selection?category=Fresh', ['vendor_id' => 999, 'products' => [$this->row($milk), $this->row($apple), $this->row($unticked, 0)]])->assertRedirect('/vendor/products?category=Fresh');

        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'available' => true, 'price_paise' => 6000, 'stock_quantity' => 8]);
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $apple, 'available' => true]);
        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor, 'product_id' => $unticked]);
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $outside, 'available' => true, 'revision' => 1]);
        $this->assertDatabaseHas('vendor_products', ['id' => $foreign, 'price_paise' => 5000]);
        $this->get('/vendor/products?category=Fresh')->assertOk()->assertSee('value="60.00"', false);
    }

    public function test_stale_row_rolls_back_the_entire_selection(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $apple = $this->product('Apple');
        DB::table('vendor_products')->insert(['vendor_id' => $vendor, 'product_id' => $apple, 'price_paise' => 7000, 'available' => true, 'revision' => 2]);

        $this->actingAs($owner)->putJson('/vendor/product-selection', ['products' => [$this->row($milk), $this->row($apple, 0, 1)]])->assertUnprocessable()->assertJsonValidationErrors('products.1.revision');

        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk]);
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $apple, 'available' => true, 'price_paise' => 7000, 'revision' => 2]);
    }

    public function test_unticking_hides_the_product_and_shop_from_location_browsing(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $this->actingAs($owner)->put('/vendor/product-selection', ['products' => [$this->row($milk)]])->assertRedirect();
        $locality = $this->serviceLocality('248001');
        $this->getJson('/api/products?locality_id='.$locality.'&vendor_id='.$vendor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('vendors.0.id', $vendor);

        $this->put('/vendor/product-selection', ['products' => [$this->row($milk, 0, 1)]])->assertRedirect();

        $this->getJson('/api/products?locality_id='.$locality)->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'available' => false]);
    }

    public function test_missing_price_and_inactive_products_cannot_be_saved(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $row = $this->row($milk);
        $row['price'] = null;
        $this->actingAs($owner)->putJson('/vendor/product-selection', ['products' => [$row]])->assertUnprocessable()->assertJsonValidationErrors('products.0.price');
        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor]);
        DB::table('products')->where('id', $milk)->update(['active' => false]);
        $this->putJson('/vendor/product-selection', ['products' => [$this->row($milk)]])->assertNotFound();
        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor]);
    }

    public function test_selection_requires_a_vendor_and_rejects_suspended_accounts(): void
    {
        $milk = $this->product();
        $input = ['products' => [$this->row($milk)]];
        $this->put('/vendor/product-selection', $input)->assertRedirect('/vendor/login');
        $this->actingAs(User::factory()->create())->put('/vendor/product-selection', $input)->assertForbidden();
        [$owner, $vendor] = $this->vendor();
        DB::table('vendors')->where('id', $vendor)->update(['status' => 'suspended']);
        $this->actingAs($owner)->put('/vendor/product-selection', $input)->assertForbidden();
        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor]);
    }

    public function test_shop_filter_respects_service_area_approval_and_stock(): void
    {
        $milk = $this->product();
        $localOffer = $this->publishProduct($milk, 6500, ['248001']);
        $otherOffer = $this->publishProduct($milk, 5000, ['110001']);
        $vendor = DB::table('vendor_products')->where('id', $localOffer)->value('vendor_id');
        $other = DB::table('vendor_products')->where('id', $otherOffer)->value('vendor_id');
        $locality = $this->serviceLocality('248001');
        $this->getJson('/api/products?locality_id='.$locality.'&vendor_id='.$other)->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(1, 'vendors')->assertJsonPath('vendors.0.id', $vendor)->assertJsonMissingPath('vendors.0.user_id');
        $this->getJson('/api/products/'.$milk.'?locality_id='.$locality.'&vendor_id='.$other)->assertNotFound();
        $this->getJson('/api/products?locality_id='.$locality.'&vendor_id='.$vendor)->assertJsonPath('data.0.price_paise', 6500);
        DB::table('vendor_products')->where('id', $localOffer)->update(['stock_quantity' => 0]);
        $this->getJson('/api/products?locality_id='.$locality)->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
        DB::table('vendor_products')->where('id', $localOffer)->update(['stock_quantity' => null]);
        DB::table('vendors')->where('id', $vendor)->update(['status' => 'pending']);
        $this->getJson('/api/products?locality_id='.$locality)->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
    }

    public function test_shop_checkout_uses_chosen_vendor_and_never_substitutes_a_cheaper_shop(): void
    {
        $milk = $this->product();
        $selected = $this->publishProduct($milk, 6500, ['248001']);
        $cheaper = $this->publishProduct($milk, 5000, ['248001']);
        $vendor = DB::table('vendor_products')->where('id', $selected)->value('vendor_id');
        $locality = $this->serviceLocality('248001');
        $customer = User::factory()->create();
        DB::table('customer_tokens')->insert(['user_id' => $customer->id, 'token_hash' => hash('sha256', 'shop-token'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $address = ['locality_id' => $locality, 'name' => 'Customer', 'phone' => '9876543210', 'street' => '12 Lane'];
        $input = ['vendor_id' => $vendor, 'request_key' => (string) Str::uuid(), 'items' => [['id' => $milk, 'quantity' => 1]], 'address' => $address, 'payment' => 'cod', 'delivery' => 'standard', 'expected_total' => 6500];
        $this->withToken('shop-token')->postJson('/api/customer/quote', ['vendor_id' => $vendor, 'items' => $input['items'], 'postal_code' => '248001', 'address' => $address])->assertOk()->assertJsonPath('data.bill.subtotal', 6500);

        $reference = $this->postJson('/api/customer/orders', $input)->assertCreated()->json('data.id');

        $this->assertDatabaseHas('order_items', ['vendor_id' => $vendor, 'vendor_product_id' => $selected, 'unit_price_paise' => 6500]);
        $this->assertDatabaseMissing('order_items', ['vendor_product_id' => $cheaper]);
        DB::table('vendor_products')->where('id', $selected)->update(['available' => false]);
        $this->postJson('/api/customer/orders', $input)->assertCreated()->assertJsonPath('data.id', $reference);
        $input['request_key'] = (string) Str::uuid();
        $this->postJson('/api/customer/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_pending_vendors_can_prepare_products_but_do_not_appear_to_customers(): void
    {
        [$owner, $vendor] = $this->vendor();
        DB::table('vendors')->where('id', $vendor)->update(['status' => 'pending']);
        $milk = $this->product();

        $this->actingAs($owner)->put('/vendor/product-selection', ['products' => [$this->row($milk)]])->assertRedirect();

        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'available' => true]);
        $this->getJson('/api/products?postal_code=248001')->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
    }

    public function test_saving_again_preserves_unchanged_revisions_and_can_explicitly_untrack_stock(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $this->actingAs($owner)->put('/vendor/product-selection', ['products' => [$this->row($milk)]])->assertRedirect();
        $this->put('/vendor/product-selection', ['products' => [$this->row($milk, 1, 1)]])->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'revision' => 1, 'stock_quantity' => 8]);
        $row = $this->row($milk, 1, 1);
        unset($row['stock_quantity']);
        $this->put('/vendor/product-selection', ['products' => [$row]])->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'revision' => 1, 'stock_quantity' => 8]);
        $row['stock_quantity'] = null;
        $this->put('/vendor/product-selection', ['products' => [$row]])->assertRedirect();
        $this->assertDatabaseHas('vendor_products', ['vendor_id' => $vendor, 'product_id' => $milk, 'revision' => 2, 'stock_quantity' => null]);
    }

    public function test_duplicate_products_and_invalid_prices_cannot_partially_save(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $this->actingAs($owner)->putJson('/vendor/product-selection', ['products' => [$this->row($milk), $this->row($milk)]])->assertUnprocessable()->assertJsonValidationErrors('products.0.id');
        $row = $this->row($milk);
        $row['price'] = '1.001';
        $this->putJson('/vendor/product-selection', ['products' => [$row]])->assertUnprocessable()->assertJsonValidationErrors('products.0.price');
        $this->assertDatabaseMissing('vendor_products', ['vendor_id' => $vendor]);
    }

    public function test_shop_browsing_excludes_unoffered_sample_products(): void
    {
        [$owner, $vendor] = $this->vendor();
        $milk = $this->product();
        $sample = $this->product('Sample only');
        DB::table('products')->where('id', $sample)->update(['is_sample' => true]);
        $this->actingAs($owner)->put('/vendor/product-selection', ['products' => [$this->row($milk)]])->assertRedirect();

        $this->getJson('/api/products?postal_code=248001&vendor_id='.$vendor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $milk);
    }

    public function test_unavailable_sample_products_do_not_reappear_in_a_selected_location(): void
    {
        [$owner, $vendor] = $this->vendor();
        $product = $this->product();
        DB::table('products')->where('id', $product)->update(['is_sample' => true]);
        $locality = $this->serviceLocality('248001');
        $this->getJson('/api/products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_sample', true);
        $this->getJson('/api/products?locality_id='.$locality)->assertJsonCount(0, 'data');
        $this->actingAs($owner)->put('/vendor/product-selection', ['products' => [$this->row($product)]])->assertRedirect();
        $this->getJson('/api/products?locality_id='.$locality)->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_sample', false);

        $this->put('/vendor/product-selection', ['products' => [$this->row($product, 0, 1)]])->assertRedirect();

        $this->getJson('/api/products?locality_id='.$locality)->assertJsonCount(0, 'data')->assertJsonCount(0, 'vendors');
        $this->getJson('/api/products/'.$product.'?locality_id='.$locality)->assertNotFound();
    }

    public function test_vendor_catalogue_escapes_product_names(): void
    {
        [$owner] = $this->vendor();
        $name = '<script>alert("product")</script>';
        $this->product($name);
        $this->actingAs($owner)->get('/vendor/products?category=Fresh')->assertOk()->assertSee($name)->assertDontSee($name, false);
    }
}
